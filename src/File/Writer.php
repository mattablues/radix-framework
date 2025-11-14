<?php

declare(strict_types=1);

namespace Radix\File;

use RuntimeException;

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
        if ($isAssoc && $headers === null) {
            $headers = self::collectHeaders($rows);
        }

        $fp = fopen($path, 'wb');
        if ($fp === false) {
            throw new RuntimeException("Kunde inte öppna fil för skrivning: {$path}");
        }

        $write = function (array $fields) use ($fp, $delimiter, $targetEncoding): void {
            // fputcsv hanterar quoting. Konvertera encoding per cell före skrivning.
            if ($targetEncoding !== null && strcasecmp($targetEncoding, 'UTF-8') !== 0) {
                foreach ($fields as &$v) {
                    if ($v === null) { continue; }
                    $v = (string)$v;
                    $conv = @iconv('UTF-8', $targetEncoding . '//TRANSLIT', $v);
                    if ($conv === false) {
                        throw new RuntimeException("Kunde inte konvertera cell till {$targetEncoding}");
                    }
                    $v = $conv;
                }
                unset($v);
            }
            fputcsv($fp, $fields, $delimiter);
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
            if ($targetEncoding !== null && strcasecmp($targetEncoding, 'UTF-8') !== 0) {
                foreach ($fields as &$v) {
                    if ($v === null) { continue; }
                    $v = (string)$v;
                    $conv = @iconv('UTF-8', $targetEncoding . '//TRANSLIT', $v);
                    if ($conv === false) {
                        throw new RuntimeException("Kunde inte konvertera cell till {$targetEncoding}");
                    }
                    $v = $conv;
                }
                unset($v);
            }
            fputcsv($fp, $fields, $delimiter);
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
            fclose($fp);
        }
    }

    private static function ensureParentDir(string $path): void
    {
        $dir = dirname($path);
        if ($dir !== '' && !is_dir($dir)) {
            if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new RuntimeException("Kunde inte skapa katalog: {$dir}");
            }
        }
        if ($dir !== '' && !is_writable($dir)) {
            throw new RuntimeException("Katalog inte skrivbar: {$dir}");
        }
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
                if (!in_array($k, $headers, true)) {
                    $headers[] = (string)$k;
                }
            }
        }
        return $headers;
    }

    /**
     * Validera rader mot ett enkelt schema.
     * $schema = [
     *   'required' => ['id','name'],
     *   'types' => ['id' => 'int', 'price' => 'float', 'active' => 'bool'],
     *   'defaults' => ['active' => false],
     *   'trim' => true, 'nullable' => ['note']
     * ]
     * Returnerar normaliserade rader. Kastar RuntimeException vid fel om $onError === 'throw',
     * annars hoppar över felaktiga rader om $onError === 'skip'.
     *
     * @param array<int,array<string,mixed>> $rows
     * @param array<string,mixed>            $schema
     * @return array<int,array<string,mixed>>
     */
    public static function validateRows(array $rows, array $schema, string $onError = 'throw'): array
    {
        $required = $schema['required'] ?? [];
        $types = $schema['types'] ?? [];
        $defaults = $schema['defaults'] ?? [];
        $trim = (bool)($schema['trim'] ?? false);
        $nullable = array_flip($schema['nullable'] ?? []);

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                if ($onError === 'skip') { continue; }
                throw new RuntimeException('Raden är inte en array');
            }
            // required
            foreach ($required as $key) {
                if (!array_key_exists($key, $row) || ($row[$key] === '' && !isset($nullable[$key]))) {
                    if ($onError === 'skip') { continue 2; }
                    throw new RuntimeException("Saknar obligatoriskt fält: {$key}");
                }
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
            foreach ($types as $k => $t) {
                if (!array_key_exists($k, $row)) {
                    continue;
                }
                $val = $row[$k];
                if (isset($nullable[$k]) && ($val === '' || $val === null)) {
                    $row[$k] = null;
                    continue;
                }
                switch ($t) {
                    case 'int':
                        if (is_numeric($val) && (string)(int)$val == (string)$val) { $row[$k] = (int)$val; break; }
                        if ($onError === 'skip') { continue 3; }
                        throw new RuntimeException("Fält {$k} måste vara int");
                    case 'float':
                        if (is_numeric($val)) { $row[$k] = (float)$val; break; }
                        if ($onError === 'skip') { continue 3; }
                        throw new RuntimeException("Fält {$k} måste vara float");
                    case 'bool':
                        if (is_bool($val)) { break; }
                        $lower = is_string($val) ? strtolower($val) : $val;
                        if ($lower === 1 || $lower === 0 || $lower === '1' || $lower === '0' || $lower === 'true' || $lower === 'false' || $lower === 'yes' || $lower === 'no') {
                            $row[$k] = in_array($lower, [1, '1', 'true', 'yes'], true);
                            break;
                        }
                        if ($onError === 'skip') { continue 3; }
                        throw new RuntimeException("Fält {$k} måste vara bool");
                    case 'string':
                        $row[$k] = (string)$val;
                        break;
                    default:
                        // okänd typ -> lämna orörd
                        break;
                }
            }
            $out[] = $row;
        }
        return $out;
    }

    /**
     * Skriv XML från array|object.
     * - $rootName: rotnodens namn.
     * - $targetEncoding: konvertering från UTF-8 till målencoding om satt.
     *
     * @param array<string,mixed>|object $data
     */
    public static function xml(string $path, array|object $data, string $rootName = 'root', ?string $targetEncoding = null): void
    {
        self::ensureParentDir($path);

        $xml = new \SimpleXMLElement(sprintf('<?xml version="1.0" encoding="UTF-8"?><%s/>', $rootName));
        self::arrayToXml(is_object($data) ? (array)$data : $data, $xml);

        $xmlString = $xml->asXML();
        if ($xmlString === false) {
            throw new \RuntimeException('Kunde inte serialisera XML');
        }

        if ($targetEncoding !== null && strcasecmp($targetEncoding, 'UTF-8') !== 0) {
            $converted = @iconv('UTF-8', $targetEncoding . '//TRANSLIT', $xmlString);
            if ($converted === false) {
                throw new \RuntimeException("Kunde inte konvertera XML till {$targetEncoding}");
            }
            $xmlString = $converted;
        }

        $fp = fopen($path, 'wb');
        if ($fp === false) {
            throw new \RuntimeException("Kunde inte öppna fil för skrivning: {$path}");
        }
        try {
            fwrite($fp, $xmlString);
            fflush($fp);
        } finally {
            fclose($fp);
        }
    }

    /**
     * @param array<string,mixed> $data
     */
    private static function arrayToXml(array $data, \SimpleXMLElement $xml): void
    {
        foreach ($data as $key => $value) {
            $key = is_numeric($key) ? 'item' : (string)$key;
            if (is_array($value)) {
                $child = $xml->addChild($key);
                self::arrayToXml($value, $child);
            } else {
                if (is_bool($value)) {
                    $value = $value ? 'true' : 'false';
                }
                $xml->addChild($key, htmlspecialchars((string)$value));
            }
        }
    }
}