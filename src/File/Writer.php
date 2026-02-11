<?php

declare(strict_types=1);

namespace Radix\File;

use RuntimeException;
use SimpleXMLElement;

final class Writer
{
    /**
     * Skriv rå text (skapar kataloger vid behov).
     */
    public static function text(string $path, string $content, ?string $targetEncoding = null): void
    {
        self::ensureParentDir($path);
        if ($targetEncoding !== null && strcasecmp($targetEncoding, 'UTF-8') !== 0) {
            $converted = @iconv('UTF-8', $targetEncoding . '//TRANSLIT', $content);
            if ($converted === false) {
                throw new RuntimeException("Kunde inte konvertera text till {$targetEncoding} för: {$path}");
            }
            $content = $converted;
        }
        $bytes = file_put_contents($path, $content);
        if ($bytes === false) {
            throw new RuntimeException("Kunde inte skriva fil: {$path}");
        }
    }

    /**
     * Skriv JSON med valfria JSON-flaggor.
     * $pretty=true för läsbar formatering.
     *
     * @param array<string,mixed>|object $data
     */
    public static function json(string $path, array|object $data, bool $pretty = true, int $flags = 0, ?string $targetEncoding = null): void
    {
        $opts = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | $flags;
        if ($pretty) {
            $opts |= JSON_PRETTY_PRINT;
        }
        $encoded = json_encode($data, $opts | JSON_THROW_ON_ERROR);
        self::text($path, $encoded . PHP_EOL, $targetEncoding);
    }

    /**
     * Skriv CSV.
     * - $rows: listor eller assoc-arrayer.
     * - $headers: null => genereras vid assoc.
     * - $delimiter: ',', ';', "\t" (TSV), '|'
     * - $targetEncoding: t.ex. 'ISO-8859-1'
     *
     * @param array<int,array<int|string,mixed>> $rows
     * @param array<int,string>|null             $headers
     */
    public static function csv(
        string $path,
        array $rows,
        ?array $headers = null,
        string $delimiter = ',',
        ?string $targetEncoding = null
    ): void {
        self::ensureParentDir($path);

        $isAssoc = self::rowsAreAssoc($rows);

        // Normalisera headers så att det alltid är en array
        if ($headers === null) {
            $headers = [];
        }

        if (self::shouldCollectHeaders($isAssoc, $headers)) {
            $headers = self::collectHeaders($rows);
        }

        $fp = fopen($path, 'wb');
        if ($fp === false) {
            throw new RuntimeException("Kunde inte öppna fil för skrivning: {$path}");
        }

        $write = function (array $fields) use ($fp, $delimiter, $targetEncoding): void {
            // 1) Normalisera till int|string‑nycklar och tillåtna fputcsv‑värden
            /** @var array<int|string, bool|float|int|string|null> $normalized */
            $normalized = [];
            foreach ($fields as $k => $v) {
                /** @var int|string $k */

                if ($v === null) {
                    $normalized[$k] = null;
                    continue;
                }

                if (self::isCsvScalarValue($v)) {
                    $normalized[$k] = $v;
                } else {
                    // Oväntad typ: serialisera till sträng
                    $encoded = json_encode($v);
                    $normalized[$k] = $encoded !== false ? $encoded : '';
                }
            }

            // 2) Encoding‑konvertering per cell
            if ($targetEncoding !== null && strcasecmp($targetEncoding, 'UTF-8') !== 0) {
                foreach ($normalized as &$v) {
                    if ($v === null) {
                        continue;
                    }
                    if (!is_string($v)) {
                        $v = (string) $v;
                    }
                    $conv = @iconv('UTF-8', $targetEncoding . '//TRANSLIT', $v);
                    if ($conv === false) {
                        throw new RuntimeException("Kunde inte konvertera cell till {$targetEncoding}");
                    }
                    $v = $conv;
                }
                unset($v);
            }

            fputcsv($fp, $normalized, $delimiter, '"', "");
        };

        try {
            if ($isAssoc && $headers !== []) {
                $write($headers);
            }
            foreach ($rows as $row) {
                if ($isAssoc) {
                    $ordered = [];
                    foreach ($headers as $h) {
                        $ordered[] = $row[$h] ?? null;
                    }
                    $write($ordered);
                } else {
                    $write($row);
                }
            }
        } finally {
            fclose($fp);
        }
    }

    /**
     * Streama skrivning av CSV. Anropa $writeRow med varje rad (array).
     * Om du anger $headers så skrivs header-rad först.
     *
     * @param array<int,string>|null $headers
     */
    public static function csvStream(
        string $path,
        callable $withWriter,
        ?array $headers = null,
        string $delimiter = ',',
        ?string $targetEncoding = null
    ): void {
        self::ensureParentDir($path);
        $fp = fopen($path, 'wb');
        if ($fp === false) {
            throw new RuntimeException("Kunde inte öppna fil för skrivning: {$path}");
        }

        $writeRow = function (array $fields) use ($fp, $delimiter, $targetEncoding): void {
            /** @var array<int|string, bool|float|int|string|null> $normalized */
            $normalized = [];
            foreach ($fields as $k => $v) {
                /** @var int|string $k */

                if ($v === null) {
                    $normalized[$k] = null;
                    continue;
                }

                if (self::isCsvScalarValue($v)) {
                    $normalized[$k] = $v;
                } else {
                    $encoded = json_encode($v);
                    $normalized[$k] = $encoded !== false ? $encoded : '';
                }
            }

            if ($targetEncoding !== null && strcasecmp($targetEncoding, 'UTF-8') !== 0) {
                foreach ($normalized as &$v) {
                    if ($v === null) {
                        continue;
                    }
                    if (!is_string($v)) {
                        // här är $v begränsad till bool|int|float|string, så cast är säker
                        $v = (string) $v;
                    }
                    $conv = @iconv('UTF-8', $targetEncoding . '//TRANSLIT', $v);
                    if ($conv === false) {
                        throw new RuntimeException("Kunde inte konvertera cell till {$targetEncoding}");
                    }
                    $v = $conv;
                }
                unset($v);
            }

            fputcsv($fp, $normalized, $delimiter, '"', "");
        };

        try {
            if ($headers !== null && $headers !== []) {
                $writeRow($headers);
            }
            $withWriter($writeRow);
        } finally {
            fclose($fp);
        }
    }

    /**
     * Konvertera JSON‑liknande data (array) till CSV‑fil.
     *
     * @param array<int,array<string,mixed>>      $data
     * @param array<int,string>|null              $headers
     */
    public static function jsonToCsv(
        string $path,
        array $data,
        ?array $headers = null,
        string $delimiter = ',',
        ?string $targetEncoding = null
    ): void {
        self::csv($path, $data, $headers, $delimiter, $targetEncoding);
    }

    /**
     * Skriv NDJSON (JSON Lines) streamat. $onWrite får en callable att skriva en item per rad.
     */
    public static function ndjsonStream(string $path, callable $withWriter, ?string $targetEncoding = null, bool $pretty = false): void
    {
        self::ensureParentDir($path);
        $fp = fopen($path, 'wb');
        if ($fp === false) {
            throw new RuntimeException("Kunde inte öppna fil för skrivning: {$path}");
        }

        // Ta ett exklusivt lock så att vi kan testa att "finally" verkligen stänger/släpper.
        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            throw new RuntimeException("Kunde inte låsa fil för skrivning: {$path}");
        }

        $writeItem = function (array|object $item) use ($fp, $targetEncoding, $pretty): void {
            $opts = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | ($pretty ? JSON_PRETTY_PRINT : 0);

            $json = json_encode($item, $opts | JSON_THROW_ON_ERROR);

            if ($targetEncoding !== null && strcasecmp($targetEncoding, 'UTF-8') !== 0) {
                $json2 = @iconv('UTF-8', $targetEncoding . '//TRANSLIT', $json);
                if ($json2 === false) {
                    throw new RuntimeException("Kunde inte konvertera JSON-rad till {$targetEncoding}");
                }
                $json = $json2;
            }

            fwrite($fp, $json);
            fwrite($fp, PHP_EOL);
        };

        try {
            $withWriter($writeItem);
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    private static function ensureParentDir(string $path): void
    {
        $dir = dirname($path);
        if ($dir !== '' && !is_dir($dir)) {
            if (!@mkdir($dir, 0o775, true) && !is_dir($dir)) {
                throw new RuntimeException("Kunde inte skapa katalog: {$dir}");
            }
        }

        // OBS: Ingen is_writable()-check här.
        // På Windows/CI blir den ofta instabil och den riktiga write-operationen
        // är ändå det som avgör om katalogen fungerar.
    }

    /**
     * @param array<int,mixed> $rows
     */
    private static function rowsAreAssoc(array $rows): bool
    {
        foreach ($rows as $r) {
            if (is_array($r) && array_keys($r) !== range(0, count($r) - 1)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<int,mixed> $rows
     * @return array<int,string>
     */
    private static function collectHeaders(array $rows): array
    {
        $headers = [];
        foreach ($rows as $r) {
            if (!is_array($r)) {
                continue;
            }
            foreach ($r as $k => $_) {
                $key = self::normalizeHeaderKey($k);
                if (!in_array($key, $headers, true)) {
                    $headers[] = $key;
                }
            }
        }
        return $headers;
    }

    private static function normalizeHeaderKey(mixed $key): string
    {
        return self::castMixedToString($key);
    }

    /**
     * Validera rader mot ett enkelt schema.
     *
     * @param array<int,array<string,mixed>> $rows
     * @param array<string,mixed>            $schema
     * @return array<int,array<string,mixed>>
     */
    public static function validateRows(
        array $rows,
        array $schema,
        string $onError = 'throw',
        ?callable $onRequiredChecked = null
    ): array {
        // Normalisera schema-delar till säkra typer
        $requiredRaw = $schema['required'] ?? [];
        $required = is_array($requiredRaw) ? array_values($requiredRaw) : [];

        $typesRaw = $schema['types'] ?? [];
        $types = is_array($typesRaw) ? $typesRaw : [];

        $defaultsRaw = $schema['defaults'] ?? [];
        $defaults = is_array($defaultsRaw) ? $defaultsRaw : [];

        $trimRaw = $schema['trim'] ?? false;
        $trim = self::normalizeBoolLike($trimRaw, 'trim');

        $nullableRaw = $schema['nullable'] ?? [];
        $nullableList = [];
        if (is_array($nullableRaw)) {
            foreach ($nullableRaw as $name) {
                if (is_scalar($name) || $name === null) {
                    $nullableList[] = strval($name);
                } else {
                    $encoded = json_encode($name);
                    $nullableList[] = $encoded !== false ? $encoded : '';
                }
            }
        }
        /** @var array<string,int> $nullable */
        $nullable = array_flip($nullableList);

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                if ($onError === 'skip') {
                    continue;
                }
                throw new RuntimeException('Raden är inte en array');
            }

            // Normalisera radernas nycklar till strängar
            $normalizedRow = [];
            foreach ($row as $rk => $rv) {
                $normalizedRow[(string) $rk] = $rv;
            }
            /** @var array<string,mixed> $row */
            $row = $normalizedRow;

            $skipRow = false;

                $requiredCount = count($required);
            for ($i = 0; $i < $requiredCount; $i++) {
                /** @var string $key */
                $key = $required[$i];

                if ($onRequiredChecked !== null) {
                    $onRequiredChecked($key);
                }

                if (
                    !array_key_exists($key, $row)
                    || ($row[$key] === '' && !array_key_exists($key, $nullable))
                ) {
                    if ($onError === 'skip') {
                        $skipRow = true;
                        break;
                    }
                    throw new RuntimeException("Saknar obligatoriskt fält: {$key}");
                }
            }

            if ($skipRow) {
                continue;
            }

            // defaults
            foreach ($defaults as $k => $v) {
                if (!array_key_exists($k, $row) || $row[$k] === null || $row[$k] === '') {
                    $row[$k] = $v;
                }
            }

            // trim
            if ($trim) {
                foreach ($row as $k => $v) {
                    if (is_string($v)) {
                        $row[$k] = trim($v);
                    }
                }
            }

            // typer
            foreach ($types as $tKey => $t) {
                /** @var string $k */
                $k = $tKey;

                if (!array_key_exists($k, $row)) {
                    continue;
                }

                $val = $row[$k];
                if (array_key_exists($k, $nullable) && ($val === '' || $val === null)) {
                    $row[$k] = null;
                    continue;
                }

                switch ($t) {
                    case 'int':
                        if (is_int($val)) {
                            break;
                        }
                        if (is_string($val) && self::isIntLikeString($val)) {
                            $row[$k] = (int) $val;
                            break;
                        }
                        if ($onError === 'skip') {
                            continue 3;
                        }
                        throw new RuntimeException("Fält {$k} måste vara int");

                    case 'float':
                        if (is_numeric($val)) {
                            $row[$k] = (float) $val;
                            break;
                        }
                        if ($onError === 'skip') {
                            continue 3;
                        }
                        throw new RuntimeException("Fält {$k} måste vara float");

                    case 'bool':
                        if (is_bool($val)) {
                            break;
                        }

                        if (is_string($val)) {
                            $lower = strtolower($val);
                        } else {
                            $lower = $val;
                        }

                        if (
                            $lower === 1 || $lower === 0
                            || $lower === '1' || $lower === '0'
                            || $lower === 'true' || $lower === 'false'
                            || $lower === 'yes' || $lower === 'no'
                        ) {
                            $row[$k] = in_array($lower, [1, '1', 'true', 'yes'], true);
                            break;
                        }
                        if ($onError === 'skip') {
                            continue 3;
                        }
                        throw new RuntimeException("Fält {$k} måste vara bool");

                    case 'string':
                        if (is_string($val)) {
                            $row[$k] = $val;
                        } elseif (is_int($val) || is_float($val) || is_bool($val) || $val === null) {
                            $row[$k] = (string) $val;
                        } else {
                            $encoded = json_encode($val);
                            $row[$k] = $encoded !== false ? $encoded : '';
                        }
                        break;

                    default:
                        break;
                }
            }

            /** @var array<string,mixed> $row */
            $out[] = $row;
        }

        /** @var array<int,array<string,mixed>> $out */
        return $out;
    }

    /**
     * Skriv XML från array|object.
     * - $rootName: rotnodens namn.
     * - $targetEncoding: konvertering från UTF-8 till målencoding om satt.
     *
     * @param array<mixed,mixed>|object $data
     */
    public static function xml(string $path, array|object $data, string $rootName = 'root', ?string $targetEncoding = null): void
    {
        self::ensureParentDir($path);

        $xml = new SimpleXMLElement(sprintf('<?xml version="1.0" encoding="UTF-8"?><%s/>', $rootName));
        self::arrayToXml(is_object($data) ? (array) $data : $data, $xml);

        $xmlString = $xml->asXML();
        if ($xmlString === false) {
            throw new RuntimeException('Kunde inte serialisera XML');
        }

        if ($targetEncoding !== null && strcasecmp($targetEncoding, 'UTF-8') !== 0) {
            $converted = @iconv('UTF-8', $targetEncoding . '//TRANSLIT', $xmlString);
            if ($converted === false) {
                throw new RuntimeException("Kunde inte konvertera XML till {$targetEncoding}");
            }
            $xmlString = $converted;
        }

        $bytes = file_put_contents($path, $xmlString);
        if ($bytes === false) {
            throw new RuntimeException("Kunde inte skriva fil: {$path}");
        }
    }

    /**
     * @param array<mixed,mixed> $data
     */
    private static function arrayToXml(array $data, SimpleXMLElement $xml): void
    {
        foreach ($data as $key => $value) {
            // Normalisera nyckeln till sträng
            $keyStr = self::normalizeXmlKey($key);
            $keyStr = is_numeric($keyStr) ? 'item' : $keyStr;

            if (is_array($value)) {
                $child = $xml->addChild($keyStr);
                self::arrayToXml($value, $child);
            } else {
                if (is_bool($value)) {
                    $value = $value ? 'true' : 'false';
                } elseif (is_scalar($value)) {
                    $value = (string) $value;
                } else {
                    // Fallback för objekt/resurser/null: serialisera eller gör tom sträng
                    $encoded = json_encode($value);
                    $value = $encoded !== false ? $encoded : '';
                }

                $xml->addChild($keyStr, htmlspecialchars($value));
            }
        }
    }

    private static function normalizeXmlKey(mixed $key): string
    {
        // Array-nycklar i PHP är alltid int|string, men vi defensiv-hanterar mixed här.
        return self::castMixedToString($key);
    }

    /**
     * Säkert "stringify" av mixed för att göra PHPStan nöjd och beteendet stabilt.
     */
    private static function castMixedToString(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }

        // Fallback: försök serialisera (för arrays/objekt utan __toString/etc)
        $encoded = json_encode($value);
        return $encoded !== false ? $encoded : '';
    }

    /**
     * Bryt ut villkoret så att det går att enhetstesta och därmed döda mutationer.
     *
     * @param array<int,string> $headers
     */
    private static function shouldCollectHeaders(bool $isAssoc, array $headers): bool
    {
        return $isAssoc && $headers === [];
    }

    private static function isCsvScalarValue(mixed $v): bool
    {
        return is_bool($v) || is_int($v) || is_float($v) || is_string($v);
    }

    private static function normalizeBoolLike(mixed $value, string $fieldName): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            if ($value === 0) {
                return false;
            }
            if ($value === 1) {
                return true;
            }
            throw new RuntimeException("Schema-fältet '{$fieldName}' måste vara boolean-like (0/1/true/false)");
        }

        if (is_string($value)) {
            $v = strtolower(trim($value));
            if ($v === '0' || $v === 'false') {
                return false;
            }
            if ($v === '1' || $v === 'true') {
                return true;
            }

            // Viktigt: särskilj "ogiltig sträng" från "ogiltig typ" så Throw_-mutanten blir dödbar.
            throw new RuntimeException("Schema-fältet '{$fieldName}' måste vara boolean-like (0/1/true/false), fick: '{$value}'");
        }

        throw new RuntimeException("Schema-fältet '{$fieldName}' måste vara boolean-like (0/1/true/false)");
    }

    private static function isIntLikeString(string $value): bool
    {
        // Tillåt ev. whitespace runt värdet (t.ex. från CSV) men inga decimaler/exp.
        $value = trim($value);

        if ($value === '') {
            return false;
        }

        return preg_match('/\A[+-]?\d+\z/', $value) === 1;
    }
}
