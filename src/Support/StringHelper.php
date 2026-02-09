<?php

declare(strict_types=1);

namespace Radix\Support;

use Radix\Config\Config;
use RuntimeException;

class StringHelper
{
    /**
     * @var array<string,mixed>|null
     */
    private static ?array $pluralizationOverride = null;

    /**
     * @var array<string,string>|null
     */
    private static ?array $irregularCache = null;

    /**
     * Appen kan override:a pluralization-reglerna här.
     *
     * @param array<string,mixed> $config
     */
    public static function setPluralizationConfig(array $config): void
    {
        self::$pluralizationOverride = $config;
        self::$irregularCache = null; // reset cache
    }


    /**
     * Singularize a table name.
     */
    public static function singularize(string $tableName): string
    {
        $irregularWords = self::irregularMap();

        $lower = strtolower($tableName);

        if (isset($irregularWords[$lower]) && is_string($irregularWords[$lower])) {
            return $irregularWords[$lower];
        }

        if (str_ends_with($tableName, 'ies')) {
            return substr($tableName, 0, -3) . 'y';
        }

        if (str_ends_with($tableName, 's')) {
            return substr($tableName, 0, -1);
        }

        return $tableName;
    }

    /**
     * Pluralize a word using simple rules + same irregular map.
     */
    public static function pluralize(string $word): string
    {
        $irregularWords = self::irregularMap();

        $lower = strtolower($word);

        if (isset($irregularWords[$lower]) && is_string($irregularWords[$lower])) {
            return $irregularWords[$lower];
        }

        if (preg_match('/(s|x|z|ch|sh)$/i', $word)) {
            return $word . 'es';
        }
        if (preg_match('/[^aeiou]y$/i', $word)) {
            return substr($word, 0, -1) . 'ies';
        }
        return $word . 's';
    }

    /**
     * @return array<string,string>
     */
    private static function irregularMap(): array
    {
        if (self::$irregularCache !== null) {
            return self::$irregularCache;
        }

        $pluralConfig = self::$pluralizationOverride;

        if ($pluralConfig === null) {
            $defaultFile = dirname(__DIR__, 2) . '/support/config/pluralization.php';

            if (!is_file($defaultFile)) {
                throw new RuntimeException('Saknar default pluralization-fil: ' . $defaultFile);
            }

            $pluralConfig = include $defaultFile;
        }

        if (!is_array($pluralConfig)) {
            throw new RuntimeException('pluralization config måste returnera en array.');
        }

        /** @var array<string,mixed> $stringKeyedConfig */
        $stringKeyedConfig = [];
        foreach ($pluralConfig as $k => $v) {
            if (is_string($k)) {
                $stringKeyedConfig[$k] = $v;
            }
        }

        $config = new Config($stringKeyedConfig);
        $rawIrregular = $config->get('irregular', []);

        $map = is_array($rawIrregular) ? $rawIrregular : [];

        $out = [];
        foreach ($map as $k => $v) {
            if (is_string($k) && is_string($v)) {
                $out[strtolower($k)] = $v;
            }
        }

        return self::$irregularCache = $out;
    }
}
