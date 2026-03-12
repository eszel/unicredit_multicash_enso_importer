<?php

namespace UniCreditMultiCashImporter;

use RuntimeException;

final class EnsoApiClient
{
    private string $baseUrl;
    private string $endpoint;
    private string $bearerToken;
    private int $timeout;
    private int $connectTimeout;
    private bool $verifySsl;
    /** @var array<string, string> */
    private array $headers;

    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        string $baseUrl,
        string $endpoint,
        string $bearerToken,
        int $timeout,
        int $connectTimeout,
        bool $verifySsl,
        array $headers = []
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->endpoint = '/' . ltrim($endpoint, '/');
        $this->bearerToken = $bearerToken;
        $this->timeout = $timeout;
        $this->connectTimeout = $connectTimeout;
        $this->verifySsl = $verifySsl;
        $this->headers = $headers;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function send(array $payload): array
    {
        if ($this->baseUrl === '') {
            throw new RuntimeException('ENSO base URL is missing.');
        }

        if ($this->endpoint === '/') {
            throw new RuntimeException('ENSO endpoint is missing.');
        }

        if ($this->bearerToken === '') {
            throw new RuntimeException('ENSO bearer token is missing.');
        }

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($body === false) {
            throw new RuntimeException('Unable to encode ENSO payload as JSON.');
        }

        $handle = curl_init($this->baseUrl . $this->endpoint);

        if ($handle === false) {
            throw new RuntimeException('Unable to initialize cURL.');
        }

        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $this->bearerToken,
            'Content-Type: application/json',
        ];

        foreach ($this->headers as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }

        curl_setopt_array($handle, [
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
            CURLOPT_TIMEOUT => $this->timeout,
        ]);

        $responseBody = curl_exec($handle);

        if ($responseBody === false) {
            $error = curl_error($handle);
            curl_close($handle);
            throw new RuntimeException('ENSO request failed: ' . $error);
        }

        $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $contentType = (string) curl_getinfo($handle, CURLINFO_CONTENT_TYPE);
        curl_close($handle);

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new RuntimeException(sprintf(
                'ENSO request failed with HTTP %d: %s',
                $statusCode,
                $this->truncate($responseBody)
            ));
        }

        $decoded = null;

        if (stripos($contentType, 'application/json') !== false) {
            $decoded = json_decode($responseBody, true);
        }

        return [
            'status_code' => $statusCode,
            'content_type' => $contentType,
            'body' => $responseBody,
            'json' => is_array($decoded) ? $decoded : null,
        ];
    }

    private function truncate(string $value, int $maxLength = 500): string
    {
        $value = trim($value);

        if (strlen($value) <= $maxLength) {
            return $value;
        }

        return substr($value, 0, $maxLength) . '...';
    }
}
