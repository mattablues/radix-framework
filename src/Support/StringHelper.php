<?php

declare(strict_types=1);

namespace Radix\Support;

use Radix\Config\Config;

class StringHelper
{
    /**
     * Singularize a table name.
     */
    public static function singularize(string $tableName): string
    {
        // Ladda konfigurationen för pluralisering
        $config = new Config(include dirname(__DIR__, 3) . '/config/pluralization.php');
        $irregularWords = $config->get('irregular', []);

        // Kontrollera om det finns oregelbundna pluralformer
        if (isset($irregularWords[strtolower($tableName)])) {
            return $irregularWords[strtolower($tableName)];
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
}