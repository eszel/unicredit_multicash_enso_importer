<?php

namespace UniCreditMultiCashImporter;

use RuntimeException;

final class ConfigLoader
{
    /**
     * @return array<string, mixed>
     */
    public static function load(string $projectRoot): array
    {
        $baseFile = rtrim($projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'config.php';
        $localFile = rtrim($projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'config.local.php';

        if (!is_file($baseFile)) {
            throw new RuntimeException('Missing base configuration file: ' . $baseFile);
        }

        $baseConfig = require $baseFile;

        if (!is_array($baseConfig)) {
            throw new RuntimeException('Base configuration must return an array.');
        }

        if (!is_file($localFile)) {
            return $baseConfig;
        }

        $localConfig = require $localFile;

        if (!is_array($localConfig)) {
            throw new RuntimeException('Local configuration must return an array.');
        }

        return self::merge($baseConfig, $localConfig);
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private static function merge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (array_key_exists($key, $base) && is_array($base[$key]) && is_array($value)) {
                $base[$key] = self::merge($base[$key], $value);
                continue;
            }

            $base[$key] = $value;
        }

        return $base;
    }
}
