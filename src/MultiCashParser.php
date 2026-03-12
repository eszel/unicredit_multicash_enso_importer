<?php

namespace UniCreditMultiCashImporter;

use RuntimeException;

final class MultiCashParser
{
    /** @var string[] */
    private array $encodingCandidates;

    /**
     * @param string[] $encodingCandidates
     */
    public function __construct(array $encodingCandidates)
    {
        $this->encodingCandidates = $encodingCandidates;
    }

    /**
     * @return array<string, mixed>
     */
    public function parseFile(string $path): array
    {
        $content = file_get_contents($path);

        if ($content === false) {
            throw new RuntimeException('Unable to read file: ' . $path);
        }

        [$normalizedContent, $detectedEncoding] = $this->normalizeEncoding($content);
        $lines = preg_split("/\r\n|\n|\r/", $normalizedContent);

        if ($lines === false) {
            throw new RuntimeException('Unable to split file into lines: ' . $path);
        }

        $blocks = $this->parseBlocks($lines);
        $transactions = [];
        $currency = null;

        foreach ($blocks as $blockIndex => $block) {
            if ($currency === null && isset($block['currency']) && is_string($block['currency']) && $block['currency'] !== '') {
                $currency = $block['currency'];
            }

            foreach ($block['transactions'] as $transaction) {
                $transaction['block_index'] = $blockIndex;
                $transaction['statement_reference'] = $block['statement_reference'];
                $transaction['account_number'] = $block['account_number'];
                $transaction['currency'] = $transaction['currency'] ?? $block['currency'];
                $transactions[] = $transaction;
            }
        }

        return [
            'file_name' => basename($path),
            'file_path' => $path,
            'encoding' => $detectedEncoding,
            'currency' => $currency,
            'block_count' => count($blocks),
            'transaction_count' => count($transactions),
            'blocks' => $blocks,
            'transactions' => $transactions,
        ];
    }

    /**
     * @param string[] $lines
     * @return array<int, array<string, mixed>>
     */
    private function parseBlocks(array $lines): array
    {
        $blocks = [];
        $currentBlock = null;
        $currentTransaction = null;

        foreach ($lines as $lineNumber => $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            if (preg_match('/^:(\d{2}[A-Z]?):(.*)$/', $line, $matches) === 1) {
                $tag = $matches[1];
                $value = $matches[2];

                if ($tag === '20') {
                    $this->finalizeTransaction($currentTransaction, $currentBlock);
                    $this->finalizeBlock($currentBlock, $blocks);

                    $currentBlock = [
                        'statement_reference' => $this->cleanText($value),
                        'account_number' => null,
                        'currency' => null,
                        'metadata' => [],
                        'transactions' => [],
                    ];

                    continue;
                }

                if ($currentBlock === null) {
                    throw new RuntimeException(sprintf('Unexpected tag before :20: on line %d.', $lineNumber + 1));
                }

                if ($tag === '25') {
                    $currentBlock['account_number'] = $this->cleanText($value);
                    continue;
                }

                if ($tag === '60F' || $tag === '60M' || $tag === '62F' || $tag === '62M') {
                    $parsedBalance = $this->parseBalance($value);
                    $currentBlock['metadata'][$tag] = $parsedBalance;

                    if ($currentBlock['currency'] === null && isset($parsedBalance['currency'])) {
                        $currentBlock['currency'] = $parsedBalance['currency'];
                    }

                    continue;
                }

                if ($tag === '61') {
                    $this->finalizeTransaction($currentTransaction, $currentBlock);
                    $currentTransaction = $this->parseTransactionLine($value);
                    continue;
                }

                if ($tag === '86') {
                    if ($currentTransaction === null) {
                        throw new RuntimeException(sprintf('Encountered :86: without :61: on line %d.', $lineNumber + 1));
                    }

                    $currentTransaction['raw']['86'] = $value;
                    continue;
                }

                $currentBlock['metadata'][$tag][] = $this->cleanText($value);
                continue;
            }

            if (strpos($line, '?') === 0 && $currentTransaction !== null) {
                $currentTransaction['raw']['86'] .= $line;
                continue;
            }

            if ($currentTransaction !== null && $currentTransaction['raw']['86'] !== '') {
                $currentTransaction['raw']['86'] .= $line;
                continue;
            }

            if ($currentBlock !== null) {
                $currentBlock['metadata']['unparsed'][] = $this->cleanText($line);
            }
        }

        $this->finalizeTransaction($currentTransaction, $currentBlock);
        $this->finalizeBlock($currentBlock, $blocks);

        return $blocks;
    }

    /**
     * @param array<string, mixed>|null $currentTransaction
     * @param array<string, mixed>|null $currentBlock
     */
    private function finalizeTransaction(?array &$currentTransaction, ?array &$currentBlock): void
    {
        if ($currentTransaction === null) {
            return;
        }

        if ($currentBlock === null) {
            throw new RuntimeException('Transaction found without an active block.');
        }

        $narrativeDetails = $this->parseNarrative((string) $currentTransaction['raw']['86']);

        $currentTransaction['narrative'] = $narrativeDetails['narrative'];
        $currentTransaction['partner_name'] = $narrativeDetails['partner_name'];
        $currentTransaction['partner_account'] = $narrativeDetails['partner_account'];
        $currentTransaction['structured_details'] = $narrativeDetails['structured_details'];

        $currentBlock['transactions'][] = $currentTransaction;
        $currentTransaction = null;
    }

    /**
     * @param array<string, mixed>|null $currentBlock
     * @param array<int, array<string, mixed>> $blocks
     */
    private function finalizeBlock(?array &$currentBlock, array &$blocks): void
    {
        if ($currentBlock === null) {
            return;
        }

        $blocks[] = $currentBlock;
        $currentBlock = null;
    }

    /**
     * @return array{0:string,1:string}
     */
    private function normalizeEncoding(string $content): array
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;

        if (preg_match('//u', $content) === 1) {
            return [$content, 'UTF-8'];
        }

        $bestCandidate = null;
        $bestContent = null;
        $bestScore = null;

        foreach ($this->encodingCandidates as $candidate) {
            $converted = @iconv($candidate, 'UTF-8//IGNORE', $content);

            if ($converted === false || $converted === '') {
                continue;
            }

            if (preg_match('//u', $converted) !== 1) {
                continue;
            }

            $score = $this->scoreConvertedContent($converted);

            if ($bestScore === null || $score > $bestScore) {
                $bestCandidate = $candidate;
                $bestContent = $converted;
                $bestScore = $score;
            }
        }

        if ($bestCandidate !== null && $bestContent !== null) {
            return [$bestContent, $bestCandidate];
        }

        throw new RuntimeException('Unable to convert file encoding to UTF-8.');
    }

    private function scoreConvertedContent(string $content): int
    {
        $score = 0;
        $score += preg_match_all('/[ÁÉÍÓÖŐÚÜŰáéíóöőúüű]/u', $content) ?: 0;
        $score -= (preg_match_all('/[˘µľ÷ĘĶ�]/u', $content) ?: 0) * 3;
        $score -= substr_count($content, "\xEF\xBF\xBD") * 5;

        return $score;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseTransactionLine(string $line): array
    {
        $pattern = '/^(?P<value_date>\d{6})(?P<entry_date>\d{4})?(?P<dc_mark>R?[CD])(?P<funds_code>[A-Z])?(?P<amount>\d+(?:,\d+)?)(?P<rest>.*)$/';

        if (preg_match($pattern, trim($line), $matches) !== 1) {
            throw new RuntimeException('Unable to parse :61: line: ' . $line);
        }

        $referenceDetails = $this->parseReferenceDetails((string) $matches['rest']);

        return [
            'booking_date' => $this->parseValueDate((string) $matches['value_date']),
            'entry_date' => $this->parseEntryDate((string) $matches['value_date'], $matches['entry_date'] ?? ''),
            'direction' => strpos((string) $matches['dc_mark'], 'D') !== false ? 'debit' : 'credit',
            'dc_mark' => (string) $matches['dc_mark'],
            'amount' => (float) str_replace(',', '.', (string) $matches['amount']),
            'transaction_code' => $referenceDetails['transaction_code'],
            'customer_reference' => $referenceDetails['customer_reference'],
            'bank_reference' => $referenceDetails['bank_reference'],
            'narrative' => '',
            'partner_name' => null,
            'partner_account' => null,
            'currency' => null,
            'structured_details' => [],
            'raw' => [
                '61' => trim($line),
                '86' => '',
            ],
        ];
    }

    /**
     * @return array{transaction_code:?string,customer_reference:?string,bank_reference:?string}
     */
    private function parseReferenceDetails(string $rest): array
    {
        $rest = trim($rest);
        $bankReference = null;

        if (strpos($rest, '//') !== false) {
            [$rest, $bankReference] = explode('//', $rest, 2);
            $bankReference = $this->cleanText($bankReference);
        }

        $transactionCode = null;
        $customerReference = null;

        if (preg_match('/^(N[A-Z0-9]{3})(.*)$/', $rest, $matches) === 1) {
            $transactionCode = $matches[1];
            $customerReference = $this->cleanText($matches[2]);
        } elseif ($rest !== '') {
            $customerReference = $this->cleanText($rest);
        }

        return [
            'transaction_code' => $transactionCode,
            'customer_reference' => $customerReference !== '' ? $customerReference : null,
            'bank_reference' => $bankReference !== '' ? $bankReference : null,
        ];
    }

    /**
     * @return array{narrative:string,partner_name:?string,partner_account:?string,structured_details:array<string, string[]>}
     */
    private function parseNarrative(string $rawNarrative): array
    {
        if ($rawNarrative === '') {
            return [
                'narrative' => '',
                'partner_name' => null,
                'partner_account' => null,
                'structured_details' => [],
            ];
        }

        $structured = [];
        $compact = preg_replace('/^\d{3}(?=\?\d{2})/', '', $rawNarrative) ?? $rawNarrative;

        if (preg_match_all('/\?(\d{2})(.*?)(?=\?\d{2}|$)/s', $compact, $matches, PREG_SET_ORDER) === false) {
            throw new RuntimeException('Unable to parse :86: narrative.');
        }

        foreach ($matches as $match) {
            $code = $match[1];
            $text = $this->cleanText($match[2]);

            if ($text === '') {
                continue;
            }

            if (!array_key_exists($code, $structured)) {
                $structured[$code] = [];
            }

            $structured[$code][] = $text;
        }

        $narrativeSegments = [];

        foreach ($structured as $code => $segments) {
            if ((int) $code >= 30) {
                continue;
            }

            foreach ($segments as $segment) {
                $narrativeSegments[] = $segment;
            }
        }

        $partnerAccountSegments = array_merge($structured['30'] ?? [], $structured['31'] ?? []);
        $partnerNameSegments = [];

        foreach (['32', '33', '34', '35', '36', '37', '38', '39'] as $code) {
            if (!isset($structured[$code])) {
                continue;
            }

            foreach ($structured[$code] as $segment) {
                $partnerNameSegments[] = $segment;
            }
        }

        return [
            'narrative' => trim(implode(' ', $narrativeSegments)),
            'partner_name' => $partnerNameSegments === [] ? null : trim(implode(' ', $partnerNameSegments)),
            'partner_account' => $partnerAccountSegments === []
                ? null
                : preg_replace('/\s+/', '', implode('', $partnerAccountSegments)),
            'structured_details' => $structured,
        ];
    }

    /**
     * @return array{date:?string,currency:?string,amount:?float,dc_mark:?string}
     */
    private function parseBalance(string $line): array
    {
        if (preg_match('/^(?P<dc_mark>[CD])(?P<date>\d{6})(?P<currency>[A-Z]{3})(?P<amount>\d+(?:,\d+)?)$/', trim($line), $matches) !== 1) {
            return [
                'date' => null,
                'currency' => null,
                'amount' => null,
                'dc_mark' => null,
            ];
        }

        return [
            'date' => $this->parseValueDate((string) $matches['date']),
            'currency' => (string) $matches['currency'],
            'amount' => (float) str_replace(',', '.', (string) $matches['amount']),
            'dc_mark' => (string) $matches['dc_mark'],
        ];
    }

    private function parseValueDate(string $valueDate): ?string
    {
        if (strlen($valueDate) !== 6) {
            return null;
        }

        $year = (int) substr($valueDate, 0, 2);
        $month = (int) substr($valueDate, 2, 2);
        $day = (int) substr($valueDate, 4, 2);
        $fullYear = $year >= 70 ? 1900 + $year : 2000 + $year;

        if (!checkdate($month, $day, $fullYear)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $fullYear, $month, $day);
    }

    private function parseEntryDate(string $valueDate, string $entryDate): ?string
    {
        if (strlen($entryDate) !== 4) {
            return null;
        }

        $valueDateIso = $this->parseValueDate($valueDate);

        if ($valueDateIso === null) {
            return null;
        }

        $year = (int) substr($valueDateIso, 0, 4);
        $month = (int) substr($entryDate, 0, 2);
        $day = (int) substr($entryDate, 2, 2);

        if (!checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    private function cleanText(string $value): string
    {
        $value = str_replace(["\r", "\n", "\t"], ' ', $value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }
}
