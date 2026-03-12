<?php

namespace UniCreditMultiCashImporter;

use RuntimeException;

final class ErrorGuard
{
    public function ensureErrorDirectoryIsClear(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create error directory: ' . $directory);
        }

        $entries = scandir($directory);

        if ($entries === false) {
            throw new RuntimeException('Unable to read error directory: ' . $directory);
        }

        $blockingEntries = [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === '.gitkeep') {
                continue;
            }

            $blockingEntries[] = $entry;
        }

        if ($blockingEntries !== []) {
            throw new RuntimeException(sprintf(
                'The error directory is not empty: %s',
                implode(', ', $blockingEntries)
            ));
        }
    }
}
