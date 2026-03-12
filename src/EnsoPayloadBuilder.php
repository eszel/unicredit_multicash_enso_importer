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
        $items = [];

        foreach ($parsedFile['blocks'] as $block) {
            foreach ($block['transactions'] as $transaction) {
                if ($this->onlyCreditTransactions && $transaction['direction'] !== 'credit') {
                    continue;
                }

                $items[] = $this->mapTransaction($transaction, $block, (string) $parsedFile['file_name']);
            }
        }

        if (count($items) > $this->maxItemsPerRequest) {
            throw new \RuntimeException(sprintf(
                'The file contains %d importable items, which exceeds the API limit of %d.',
                count($items),
                $this->maxItemsPerRequest
            ));
        }

        return ['items' => $items];
    }

    /**
     * @param array<string, mixed> $transaction
     * @param array<string, mixed> $block
     * @return array<string, mixed>
     */
    private function mapTransaction(array $transaction, array $block, string $fileName): array
    {
        $reference = $transaction['bank_reference'] ?? $transaction['customer_reference'];
        $currency = $transaction['currency'] ?? $block['currency'] ?? $this->defaultCurrency;
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
