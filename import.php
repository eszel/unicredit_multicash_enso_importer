<?php

declare(strict_types=1);

use UniCreditMultiCashImporter\ConfigLoader;
use UniCreditMultiCashImporter\EnsoApiClient;
use UniCreditMultiCashImporter\EnsoPayloadBuilder;
use UniCreditMultiCashImporter\ErrorGuard;
use UniCreditMultiCashImporter\FileFinder;
use UniCreditMultiCashImporter\Importer;
use UniCreditMultiCashImporter\Logger;
use UniCreditMultiCashImporter\MultiCashParser;

require __DIR__ . '/src/bootstrap.php';

$options = getopt('', ['dry-run', 'help', 'limit:']);

if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php import.php [--dry-run] [--limit=NUMBER]\n");
    exit(0);
}

try {
    $config = ConfigLoader::load(__DIR__);
    $logger = new Logger(
        (string) $config['logging']['file'],
        (bool) $config['logging']['stdout']
    );

    $importer = new Importer(
        $config,
        $logger,
        new ErrorGuard(),
        new FileFinder($config['importer']['allowed_extensions']),
        new MultiCashParser($config['importer']['encoding_candidates']),
        new EnsoPayloadBuilder(
            (bool) $config['payload']['include_raw_fields'],
            (string) $config['payload']['forras_format'],
            (bool) $config['payload']['only_credit_transactions'],
            isset($config['payload']['default_currency']) && is_string($config['payload']['default_currency'])
                ? $config['payload']['default_currency']
                : null,
            (int) $config['payload']['max_items_per_request']
        ),
        new EnsoApiClient(
            (string) $config['enso']['base_url'],
            (string) $config['enso']['endpoint'],
            (string) $config['enso']['bearer_token'],
            (int) $config['enso']['timeout'],
            (int) $config['enso']['connect_timeout'],
            (bool) $config['enso']['verify_ssl'],
            $config['enso']['headers']
        )
    );

    $limit = isset($options['limit']) ? max(0, (int) $options['limit']) : null;
    $dryRun = array_key_exists('dry-run', $options);
    exit($importer->run($dryRun, $limit));
} catch (Throwable $throwable) {
    fwrite(STDERR, '[FATAL] ' . $throwable->getMessage() . PHP_EOL);
    exit(1);
}
