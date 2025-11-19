<?php

declare(strict_types=1);

namespace Radix\Support;

use Radix\Config\Config;
use RuntimeException;

class StringHelper
{
    /**
     * Singularize a table name.
     */
    public static function singularize(string $tableName): string
    {
        // Ladda konfigurationen för pluralisering
        $pluralConfig = include dirname(__DIR__, 3) . '/config/pluralization.php';

        if (!is_array($pluralConfig)) {
            throw new RuntimeException('pluralization.php måste returnera en array.');
        }

        /** @var array<string,mixed> $pluralConfig */
        $config = new Config($pluralConfig);
        $rawIrregular = $config->get('irregular', []);
        $irregularWords = is_array($rawIrregular) ? $rawIrregular : [];

        $lower = strtolower($tableName);

        // Kontrollera om det finns oregelbundna pluralformer
        if (isset($irregularWords[$lower])) {
            $mapped = $irregularWords[$lower];

            if (is_string($mapped)) {
                return $mapped;
            }
        }

        // Hantera standardfallet där tabellnamnet slutar på 'ies'
        if (str_ends_with($tableName, 'ies')) {
            return substr($tableName, 0, -3) . 'y';
        }

        // Ta bort sista 's' om det inte är ett undantag
        if (str_ends_with($tableName, 's')) {
            return substr($tableName, 0, -1);
        }

        // Returnera originalnamnet om inget behöver ändras
        return $tableName;
    }

    /**
     * Pluralize a word using simple rules + same irregular map.
     */
    public static function pluralize(string $word): string
    {
        $pluralConfig = include dirname(__DIR__, 3) . '/config/pluralization.php';

        if (!is_array($pluralConfig)) {
            throw new RuntimeException('pluralization.php måste returnera en array.');
        }

        /** @var array<string,mixed> $pluralConfig */
        $config = new Config($pluralConfig);
        $rawIrregular = $config->get('irregular', []);
        $irregularWords = is_array($rawIrregular) ? $rawIrregular : [];

        $lower = strtolower($word);

        // Om oregelbunden mappning finns (direkt sträng), använd den.
        if (isset($irregularWords[$lower]) && is_string($irregularWords[$lower])) {
            // För ord som "status" där singular==plural, returnera värdet.
            return $irregularWords[$lower];
        }

        // Enkla regler
        if (preg_match('/(s|x|z|ch|sh)$/i', $word)) {
            return $word . 'es';
        }
        if (preg_match('/[^aeiou]y$/i', $word)) {
            return substr($word, 0, -1) . 'ies';
        }
        return $word . 's';
    }
}
