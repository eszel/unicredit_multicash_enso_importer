<?php

namespace UniCreditMultiCashImporter;

use RuntimeException;
use Throwable;

final class Importer
{
    /** @var array<string, mixed> */
    private array $config;
    private Logger $logger;
    private ErrorGuard $errorGuard;
    private FileFinder $fileFinder;
    private MultiCashParser $parser;
    private EnsoPayloadBuilder $payloadBuilder;
    private EnsoApiClient $apiClient;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        array $config,
        Logger $logger,
        ErrorGuard $errorGuard,
        FileFinder $fileFinder,
        MultiCashParser $parser,
        EnsoPayloadBuilder $payloadBuilder,
        EnsoApiClient $apiClient
    ) {
        $this->config = $config;
        $this->logger = $logger;
        $this->errorGuard = $errorGuard;
        $this->fileFinder = $fileFinder;
        $this->parser = $parser;
        $this->payloadBuilder = $payloadBuilder;
        $this->apiClient = $apiClient;
    }

    public function run(bool $dryRun = false, ?int $overrideLimit = null): int
    {
        $importerConfig = $this->config['importer'];
        $inputDirectory = (string) $importerConfig['input_dir'];
        $importedDirectory = (string) $importerConfig['imported_dir'];
        $errorDirectory = (string) $importerConfig['error_dir'];
        $limit = $overrideLimit ?? (int) ($importerConfig['process_limit'] ?? 0);

        $this->logger->info('Importer started.', [
            'dry_run' => $dryRun,
            'limit' => $limit,
            'input_dir' => $inputDirectory,
        ]);

        $this->errorGuard->ensureErrorDirectoryIsClear($errorDirectory);
        $files = $this->fileFinder->findPendingFiles($inputDirectory, $limit);

        if ($files === []) {
            $this->logger->info('No input file found.');
            return 0;
        }

        $this->logger->info('Pending files found.', [
            'count' => count($files),
            'files' => array_map('basename', $files),
        ]);

        foreach ($files as $sourcePath) {
            $workingPath = $sourcePath;

            try {
                if ($dryRun) {
                    $this->logger->info('Dry-run mode, file will not be moved.', [
                        'file' => basename($sourcePath),
                    ]);
                } else {
                    $workingPath = $this->fileFinder->claimFile($sourcePath, $importedDirectory);
                    $this->logger->info('File moved to imported directory.', [
                        'source' => $sourcePath,
                        'target' => $workingPath,
                    ]);
                }

                $parsedFile = $this->parser->parseFile($workingPath);
                $buildResult = $this->payloadBuilder->buildWithDiagnostics($parsedFile);
                $payload = $buildResult['payload'];
                $diagnostics = $buildResult['diagnostics'];

                $this->logger->info('File parsed successfully.', [
                    'file' => basename($workingPath),
                    'encoding' => $parsedFile['encoding'],
                    'blocks' => $parsedFile['block_count'],
                    'transactions' => $parsedFile['transaction_count'],
                    'detected_currency' => $parsedFile['currency'],
                ]);

                $itemCount = isset($payload['items']) && is_array($payload['items']) ? count($payload['items']) : 0;
                $skippedCount = (int) $parsedFile['transaction_count'] - $itemCount;
                $apiTarget = rtrim((string) $this->config['enso']['base_url'], '/') . '/' . ltrim((string) $this->config['enso']['endpoint'], '/');

                foreach ($diagnostics as $diagnostic) {
                    $this->logger->info('Transaction diagnostic.', [
                        'file' => basename($workingPath),
                        'diagnostic' => $diagnostic,
                    ]);
                }

                $this->logger->info('ENSO request payload prepared.', [
                    'file' => basename($workingPath),
                    'url' => $apiTarget,
                    'items' => $itemCount,
                    'skipped_transactions' => $skippedCount,
                    'payload' => $payload,
                ]);

                if ($itemCount === 0) {
                    $this->logger->info('No importable bank statement items remained after filtering.', [
                        'file' => basename($workingPath),
                        'parsed_transactions' => $parsedFile['transaction_count'],
                        'skipped_transactions' => $skippedCount,
                    ]);

                    continue;
                }

                if ($dryRun) {
                    $this->logger->info('Dry-run completed for file.', [
                        'file' => basename($workingPath),
                        'payload_preview' => [
                            'statement_blocks' => $parsedFile['block_count'],
                            'transaction_count' => $parsedFile['transaction_count'],
                            'api_items' => $itemCount,
                            'skipped_transactions' => $skippedCount,
                        ],
                        'payload' => $payload,
                    ]);

                    continue;
                }

                $response = $this->apiClient->send($payload);

                $this->logger->info('ENSO import request succeeded.', [
                    'file' => basename($workingPath),
                    'url' => $apiTarget,
                    'items' => $itemCount,
                    'skipped_transactions' => $skippedCount,
                    'status_code' => $response['status_code'],
                    'content_type' => $response['content_type'],
                    'response_json' => $response['json'],
                    'response_body' => $response['body'],
                ]);
            } catch (Throwable $throwable) {
                $context = [
                    'file' => basename($workingPath),
                    'message' => $throwable->getMessage(),
                ];

                if (!$dryRun && is_file($workingPath)) {
                    try {
                        $errorPath = $this->fileFinder->moveToError($workingPath, $errorDirectory);
                        $context['error_target'] = $errorPath;
                    } catch (Throwable $moveException) {
                        $context['error_move_failed'] = $moveException->getMessage();
                    }
                }

                $this->logger->error('File processing failed.', $context);

                return 1;
            }
        }

        $this->logger->info('Importer finished successfully.');

        return 0;
    }
}
