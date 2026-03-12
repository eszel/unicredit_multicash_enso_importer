<?php

namespace UniCreditMultiCashImporter;

use RuntimeException;

final class FileFinder
{
    /** @var string[] */
    private array $allowedExtensions;

    /**
     * @param string[] $allowedExtensions
     */
    public function __construct(array $allowedExtensions)
    {
        $normalized = [];

        foreach ($allowedExtensions as $extension) {
            $normalized[] = ltrim(strtolower((string) $extension), '.');
        }

        $this->allowedExtensions = array_values(array_unique(array_filter($normalized)));
    }

    /**
     * @return string[]
     */
    public function findPendingFiles(string $directory, int $limit = 0): array
    {
        if (!is_dir($directory)) {
            throw new RuntimeException('Input directory does not exist: ' . $directory);
        }

        $entries = scandir($directory);

        if ($entries === false) {
            throw new RuntimeException('Unable to read input directory: ' . $directory);
        }

        $files = [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $entry;

            if (!is_file($path)) {
                continue;
            }

            $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

            if ($extension === '' || !in_array($extension, $this->allowedExtensions, true)) {
                continue;
            }

            $files[] = $path;
        }

        usort($files, static function (string $left, string $right): int {
            $leftMTime = (int) @filemtime($left);
            $rightMTime = (int) @filemtime($right);

            if ($leftMTime === $rightMTime) {
                return strcmp(basename($left), basename($right));
            }

            return $leftMTime <=> $rightMTime;
        });

        if ($limit > 0) {
            return array_slice($files, 0, $limit);
        }

        return $files;
    }

    public function claimFile(string $sourcePath, string $targetDirectory): string
    {
        return $this->moveFile($sourcePath, $targetDirectory);
    }

    public function moveToError(string $sourcePath, string $targetDirectory): string
    {
        return $this->moveFile($sourcePath, $targetDirectory);
    }

    private function moveFile(string $sourcePath, string $targetDirectory): string
    {
        if (!is_file($sourcePath)) {
            throw new RuntimeException('Source file does not exist: ' . $sourcePath);
        }

        if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0777, true) && !is_dir($targetDirectory)) {
            throw new RuntimeException('Unable to create target directory: ' . $targetDirectory);
        }

        $destinationPath = $this->buildUniqueDestination($sourcePath, $targetDirectory);

        if (!rename($sourcePath, $destinationPath)) {
            throw new RuntimeException(sprintf(
                'Unable to move file from %s to %s',
                $sourcePath,
                $destinationPath
            ));
        }

        return $destinationPath;
    }

    private function buildUniqueDestination(string $sourcePath, string $targetDirectory): string
    {
        $filename = basename($sourcePath);
        $destination = rtrim($targetDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;

        if (!file_exists($destination)) {
            return $destination;
        }

        $name = (string) pathinfo($filename, PATHINFO_FILENAME);
        $extension = (string) pathinfo($filename, PATHINFO_EXTENSION);
        $suffix = date('Ymd_His');
        $counter = 1;

        do {
            $candidate = $name . '_' . $suffix . '_' . $counter;

            if ($extension !== '') {
                $candidate .= '.' . $extension;
            }

            $destination = rtrim($targetDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $candidate;
            ++$counter;
        } while (file_exists($destination));

        return $destination;
    }
}
