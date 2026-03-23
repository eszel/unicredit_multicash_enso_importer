<?php

namespace UniCreditMultiCashImporter;

final class EnsoPayloadBuilder
{
    private bool $includeRawFields;
    private string $sourceFormat;
    private bool $onlyCreditTransactions;
    private ?string $defaultCurrency;
    private int $maxItemsPerRequest;

    public function __construct(
        bool $includeRawFields,
        string $sourceFormat,
        bool $onlyCreditTransactions,
        ?string $defaultCurrency,
        int $maxItemsPerRequest
    )
    {
        $this->includeRawFields = $includeRawFields;
        $this->sourceFormat = $sourceFormat;
        $this->onlyCreditTransactions = $onlyCreditTransactions;
        $this->defaultCurrency = $defaultCurrency;
        $this->maxItemsPerRequest = $maxItemsPerRequest;
    }

    /**
     * @param array<string, mixed> $parsedFile
     * @return array<string, mixed>
     */
    public function build(array $parsedFile): array
    {
        $result = $this->buildWithDiagnostics($parsedFile);

        return $result['payload'];
    }

    /**
     * @param array<string, mixed> $parsedFile
     * @return array{payload:array<string, mixed>,diagnostics:array<int, array<string, mixed>>}
     */
    public function buildWithDiagnostics(array $parsedFile): array
    {
        $items = [];
        $diagnostics = [];

        foreach ($parsedFile['blocks'] as $blockIndex => $block) {
            foreach ($block['transactions'] as $transactionIndex => $transaction) {
                $willSend = !$this->onlyCreditTransactions || $transaction['direction'] === 'credit';
                $payloadItem = null;
                $payloadIndex = null;

                if ($willSend) {
                    $payloadItem = $this->mapTransaction($transaction, $block, (string) $parsedFile['file_name']);
                    $items[] = $payloadItem;
                    $payloadIndex = count($items) - 1;
                }

                $diagnostics[] = $this->buildTransactionDiagnostic(
                    $transaction,
                    $block,
                    (string) $parsedFile['file_name'],
                    (int) $blockIndex,
                    (int) $transactionIndex,
                    $willSend,
                    $payloadIndex,
                    $payloadItem
                );
            }
        }

        if (count($items) > $this->maxItemsPerRequest) {
            throw new \RuntimeException(sprintf(
                'The file contains %d importable items, which exceeds the API limit of %d.',
                count($items),
                $this->maxItemsPerRequest
            ));
        }

        return [
            'payload' => ['items' => $items],
            'diagnostics' => $diagnostics,
        ];
    }

    /**
     * @param array<string, mixed> $transaction
     * @param array<string, mixed> $block
     * @return array<string, mixed>
     */
    private function mapTransaction(array $transaction, array $block, string $fileName): array
    {
        $reference = $transaction['bank_reference'] ?? $transaction['customer_reference'];
        $resolvedCurrency = $this->resolveCurrency($transaction, $block);
        $currency = $resolvedCurrency['currency'];
        $narrative = $transaction['narrative'] !== '' ? $transaction['narrative'] : ($transaction['customer_reference'] ?? null);

        return [
            'datum' => $transaction['booking_date'],
            'osszeg' => round((float) $transaction['amount'], 2),
            'penznem' => $this->truncate($this->normalizeText($currency), 3),
            'forras_format' => $this->truncate($this->sourceFormat, 20),
            'partner_nev' => $this->truncate($this->normalizeText($transaction['partner_name']), 255),
            'partner_szamla' => $this->truncate($this->normalizeAccount($transaction['partner_account']), 64),
            'kozlemeny' => $this->truncate($this->normalizeText($narrative), 1000),
            'tranz_id' => $this->truncate($this->normalizeText($reference), 50),
            'fajlnev' => $this->truncate($fileName, 255),
            'forras_sor' => $this->buildSourceRow($transaction),
        ];
    }

    /**
     * @param array<string, mixed> $transaction
     * @param array<string, mixed> $block
     * @param array<string, mixed>|null $payloadItem
     * @return array<string, mixed>
     */
    private function buildTransactionDiagnostic(
        array $transaction,
        array $block,
        string $fileName,
        int $blockIndex,
        int $transactionIndex,
        bool $willSend,
        ?int $payloadIndex,
        ?array $payloadItem
    ): array {
        $resolvedCurrency = $this->resolveCurrency($transaction, $block);
        $recognized = [
            'statement_reference' => $block['statement_reference'] ?? null,
            'account_number' => $block['account_number'] ?? null,
            'booking_date' => $transaction['booking_date'] ?? null,
            'entry_date' => $transaction['entry_date'] ?? null,
            'direction' => $transaction['direction'] ?? null,
            'amount' => isset($transaction['amount']) ? round((float) $transaction['amount'], 2) : null,
            'currency' => $resolvedCurrency['currency'],
            'currency_source' => $resolvedCurrency['source'],
            'transaction_code' => $transaction['transaction_code'] ?? null,
            'customer_reference' => $transaction['customer_reference'] ?? null,
            'bank_reference' => $transaction['bank_reference'] ?? null,
            'narrative' => $transaction['narrative'] ?? null,
            'partner_bank' => $transaction['partner_bank'] ?? null,
            'partner_account' => $transaction['partner_account'] ?? null,
            'partner_name' => $transaction['partner_name'] ?? null,
            'supplementary_details' => $transaction['supplementary_details'] ?? [],
            'unparsed_tail' => $transaction['unparsed_tail'] ?? null,
        ];

        return [
            'file_name' => $fileName,
            'block_index' => $blockIndex,
            'transaction_index' => $transactionIndex,
            'will_send_to_api' => $willSend,
            'skip_reason' => $willSend ? null : 'filtered_by_only_credit_transactions',
            'api_payload_index' => $payloadIndex,
            'recognized_fields' => $this->filterRecognizedFields($recognized),
            'missing_fields' => $this->collectMissingFields($recognized, [
                'statement_reference',
                'account_number',
                'booking_date',
                'direction',
                'amount',
                'currency',
                'transaction_code',
                'customer_reference',
                'bank_reference',
                'narrative',
                'partner_account',
                'partner_name',
            ]),
            'optional_missing_fields' => $this->collectMissingFields($recognized, [
                'entry_date',
                'partner_bank',
            ]),
            'structured_details' => $transaction['structured_details'] ?? [],
            'raw_fields' => $transaction['raw'] ?? [],
            'api_item' => $payloadItem,
        ];
    }

    /**
     * @param array<string, mixed> $transaction
     */
    private function buildSourceRow(array $transaction): ?string
    {
        if (!$this->includeRawFields) {
            return null;
        }

        $parts = [];

        if (!empty($transaction['raw']['61'])) {
            $parts[] = ':61:' . $transaction['raw']['61'];
        }

        if (!empty($transaction['raw']['86'])) {
            $parts[] = ':86:' . $transaction['raw']['86'];
        }

        if ($parts === []) {
            return null;
        }

        return $this->truncate(implode("\n", $parts), 65535);
    }

    private function normalizeText($value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);

        return $value === '' ? null : $value;
    }

    private function normalizeAccount($value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $normalized = preg_replace('/\s+/', '', $value);

        if ($normalized === null || $normalized === '') {
            return null;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $transaction
     * @param array<string, mixed> $block
     * @return array{currency:?string,source:?string}
     */
    private function resolveCurrency(array $transaction, array $block): array
    {
        if (isset($transaction['currency']) && is_string($transaction['currency']) && $transaction['currency'] !== '') {
            return [
                'currency' => $transaction['currency'],
                'source' => isset($transaction['currency_source']) && is_string($transaction['currency_source']) && $transaction['currency_source'] !== ''
                    ? $transaction['currency_source']
                    : 'transaction',
            ];
        }

        if (isset($block['currency']) && is_string($block['currency']) && $block['currency'] !== '') {
            return [
                'currency' => $block['currency'],
                'source' => 'block_balance',
            ];
        }

        if ($this->defaultCurrency !== null && $this->defaultCurrency !== '') {
            return [
                'currency' => $this->defaultCurrency,
                'source' => 'config_default',
            ];
        }

        return [
            'currency' => null,
            'source' => null,
        ];
    }

    /**
     * @param array<string, mixed> $recognized
     * @return array<string, mixed>
     */
    private function filterRecognizedFields(array $recognized): array
    {
        return array_filter($recognized, function ($value): bool {
            return !$this->isMissingValue($value);
        });
    }

    /**
     * @param array<string, mixed> $recognized
     * @param string[] $fields
     * @return string[]
     */
    private function collectMissingFields(array $recognized, array $fields): array
    {
        $missing = [];

        foreach ($fields as $field) {
            $value = $recognized[$field] ?? null;

            if (!$this->isMissingValue($value)) {
                continue;
            }

            $missing[] = $field;
        }

        return $missing;
    }

    /**
     * @param mixed $value
     */
    private function isMissingValue($value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_string($value)) {
            return trim($value) === '';
        }

        if (is_array($value)) {
            return $value === [];
        }

        return false;
    }

    private function truncate(?string $value, int $maxLength): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $maxLength);
        }

        return substr($value, 0, $maxLength);
    }
}
