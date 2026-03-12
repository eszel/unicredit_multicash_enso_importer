<?php

namespace UniCreditMultiCashImporter;

use RuntimeException;

final class Logger
{
    private string $filePath;
    private bool $writeToStdout;

    public function __construct(string $filePath, bool $writeToStdout = true)
    {
        $this->filePath = $filePath;
        $this->writeToStdout = $writeToStdout;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function info(string $message, array $context = []): void
    {
        $this->log('INFO', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function warning(string $message, array $context = []): void
    {
        $this->log('WARNING', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function error(string $message, array $context = []): void
    {
        $this->log('ERROR', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function log(string $level, string $message, array $context): void
    {
        $line = sprintf(
            "[%s] %s %s%s",
            date('Y-m-d H:i:s'),
            $level,
            $message,
            $context === [] ? '' : ' ' . $this->encodeContext($context)
        );

        $this->writeLine($line);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function encodeContext(array $context): string
    {
        $encoded = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($encoded === false) {
            return '{"context_encoding":"failed"}';
        }

        return $encoded;
    }

    private function writeLine(string $line): void
    {
        if ($this->writeToStdout) {
            fwrite(STDOUT, $line . PHP_EOL);
        }

        $directory = dirname($this->filePath);

        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create log directory: ' . $directory);
        }

        if (file_put_contents($this->filePath, $line . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException('Unable to write log file: ' . $this->filePath);
        }
    }
}
