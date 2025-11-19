<?php

declare(strict_types=1);

namespace Radix\File;

use RuntimeException;
use SimpleXMLElement;
use SplFileObject;

final class Reader
{
    // --- Simple reads (befintlig funktionalitet) ---

    public static function text(string $path, ?string $encoding = null): string
    {
        self::ensureFileReadable($path);
        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException("Kunde inte läsa fil: {$path}");
        }
        if ($encoding !== null && strcasecmp($encoding, 'UTF-8') !== 0) {
            $converted = @iconv($encoding, 'UTF-8//IGNORE', $content);
            if ($converted === false) {
                throw new RuntimeException("Kunde inte konvertera encoding från {$encoding} till UTF-8 för: {$path}");
            }
            return $converted;
        }
        return $content;
    }

    /**
     * Läs JSON-fil.
     *
     * @return array<string,mixed>|object
     */
    public static function json(string $path, bool $assoc = true): array|object
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("Kunde inte läsa fil: {$path}");
        }

        $data = json_decode(
            $contents,
            $assoc,
            512,
            JSON_THROW_ON_ERROR
        );

        if ($assoc) {
            if (!is_array($data)) {
                throw new RuntimeException("JSON-data i {$path} är inte ett objekt/array som kan tolkas associativt.");
            }

            /** @var array<string,mixed> $data */
            return $data;
        }

        if (!is_object($data)) {
            throw new RuntimeException("JSON-data i {$path} är inte ett objekt.");
        }

        return $data;
    }

    /**
     * Läs XML.
     * - assoc=true returnerar array (konverterad från SimpleXMLElement).
     * - assoc=false returnerar SimpleXMLElement.
     * - $encoding: konverterar inläst råtext till UTF-8 innan parse.
     *
     * När $assoc = true returneras en assoc‑array (rekursivt).
     * När $assoc = false returneras en SimpleXMLElement.
     *
     * @phpstan-return ($assoc is true ? array<string, mixed> : \SimpleXMLElement)
     */
    public static function xml(string $path, bool $assoc = true, ?string $encoding = null): array|SimpleXMLElement
    {
        self::ensureFileReadable($path);

        $xmlString = file_get_contents($path);
        if ($xmlString === false) {
            throw new RuntimeException("Kunde inte läsa fil: {$path}");
        }
        if ($encoding !== null && strcasecmp($encoding, 'UTF-8') !== 0) {
            $converted = @iconv($encoding, 'UTF-8//IGNORE', $xmlString);
            if ($converted === false) {
                throw new RuntimeException("Kunde inte konvertera encoding från {$encoding} till UTF-8 för: {$path}");
            }
            $xmlString = $converted;
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlString, 'SimpleXMLElement', LIBXML_NOCDATA);
        if ($xml === false) {
            $errors = array_map(static fn($e) => trim($e->message), libxml_get_errors());
            libxml_clear_errors();
            throw new RuntimeException('Kunde inte parsa XML: ' . implode('; ', $errors));
        }
        return $assoc ? self::xmlToArray($xml) : $xml;
    }

    /**
     * Läs en CSV-fil till en array av rader.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function csv(
        string $path,
        ?string $delimiter = null,
        bool $hasHeader = false,
        ?string $encoding = null,
        bool $castNumeric = true
    ): array {
        // För små/medelstora filer. För mycket stora filer använd csvStream().
        /** @var array<int, array<string, mixed>> $rows */
        $rows = [];

        self::csvStream(
            $path,
            /**
             * @param array<mixed> $row
             */
            function (array $row) use (&$rows): void {
                // Normalisera nycklar till string
                $normalized = [];
                foreach ($row as $k => $v) {
                    /** @var int|string $k */
                    $normalized[(string) $k] = $v;
                }

                /** @var array<string, mixed> $normalized */
                $rows[] = $normalized;
            },
            $delimiter,
            $hasHeader,
            $encoding,
            $castNumeric
        );

        /** @var array<int, array<string, mixed>> $rows */
        return $rows;
    }

    /**
     * Läs CSV och returnera JSON‑vänlig struktur (array av assoc‑arrayer).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function csvToJson(
        string $path,
        ?string $delimiter = null,
        bool $hasHeader = true,
        ?string $encoding = null,
        bool $castNumeric = true
    ): array {
        return self::csv($path, $delimiter, $hasHeader, $encoding, $castNumeric);
    }

    // --- Streaming-API ---

    /**
     * Streama CSV rad-för-rad.
     * - $onRow får redan mappade rader (assoc om hasHeader=true).
     * - $delimiter: ',', ';', "\t" (TSV), '|'. null => auto-detekteras.
     * - $encoding: källa konverteras till UTF-8 om satt (t.ex. 'ISO-8859-1').
     */
    public static function csvStream(
        string $path,
        callable $onRow,
        ?string $delimiter = null,
        bool $hasHeader = false,
        ?string $encoding = null,
        bool $castNumeric = true
    ): void {
        self::ensureFileReadable($path);

        $spl = new SplFileObject($path, 'r');
        $spl->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY);
        $spl->setCsvControl(self::detectDelimiter($path, $delimiter));

        $headers = null;

        foreach ($spl as $row) {
            if ($row === [null] || $row === false) {
                continue;
            }

            if (!is_array($row)) {
                // Skydda mot oväntade typer från SplFileObject
                continue;
            }

            // Trimma och ev. encoding-konvertera celler
            $row = array_map(
                static function ($v) use ($encoding, $castNumeric) {
                    if ($v === null) {
                        return null;
                    }

                    // Gör om värdet till sträng på ett säkert sätt
                    if (!is_scalar($v)) {
                        // Oväntad typ: försök json_encode, annars tom sträng
                        $encoded = json_encode($v);
                        $s = $encoded !== false ? $encoded : '';
                    } else {
                        /** @var scalar $v */
                        $s = (string) $v;
                    }

                    $s = trim($s);

                    if ($encoding !== null && strcasecmp($encoding, 'UTF-8') !== 0) {
                        $s2 = @iconv($encoding, 'UTF-8//IGNORE', $s);
                        if ($s2 === false) {
                            throw new RuntimeException("Kunde inte konvertera cell från {$encoding} till UTF-8");
                        }
                        $s = $s2;
                    }

                    // Försök numerisk typning: heltal eller flyttal
                    if ($castNumeric && $s !== '' && is_numeric($s)) {
                        if (ctype_digit($s)) {
                            return (int) $s;
                        }
                        // Hantera decimaltal med punkt
                        return (float) $s;
                    }

                    return $s;
                },
                $row
            );

            if ($hasHeader && $headers === null) {
                $headers = $row;
                continue;
            }

            if ($hasHeader) {
                $assoc = [];
                $max = max(count($headers), count($row));
                for ($i = 0; $i < $max; $i++) {
                    $headerKey = $headers[$i] ?? "col_{$i}";

                    /** @var string $key */
                    $key = $headerKey;

                    $assoc[$key] = $row[$i] ?? null;
                }
                $onRow($assoc);
            } else {
                $onRow($row);
            }
        }
    }

    /**
     * Streama text-fil i chunkar (t.ex. för stora filer).
     * $chunkSize i bytes (standard 8192). Returnerar UTF-8 om $encoding sätts.
     */
    public static function textStream(string $path, callable $onChunk, int $chunkSize = 8192, ?string $encoding = null): void
    {
        self::ensureFileReadable($path);

        if ($chunkSize <= 0) {
            throw new RuntimeException("chunkSize must be a positive integer, got {$chunkSize}");
        }

        $h = fopen($path, 'rb');
        if ($h === false) {
            throw new RuntimeException("Kunde inte öppna fil: $path");
        }
        try {
            while (!feof($h)) {
                $chunk = fread($h, $chunkSize);
                if ($chunk === false) {
                    throw new RuntimeException("Kunde inte läsa chunk från: {$path}");
                }
                if ($chunk === '') {
                    continue;
                }
                if ($encoding !== null && strcasecmp($encoding, 'UTF-8') !== 0) {
                    $converted = @iconv($encoding, 'UTF-8//IGNORE', $chunk);
                    if ($converted === false) {
                        throw new RuntimeException("Kunde inte konvertera chunk från {$encoding} till UTF-8");
                    }
                    $onChunk($converted);
                } else {
                    $onChunk($chunk);
                }
            }
        } finally {
            fclose($h);
        }
    }

    /**
     * Streama JSON Lines (NDJSON) rad-för-rad. Varje rad ska vara ett JSON-objekt/array.
     */
    public static function ndjsonStream(string $path, callable $onItem, ?string $encoding = null, bool $assoc = true): void
    {
        self::ensureFileReadable($path);
        $h = fopen($path, 'rb');
        if ($h === false) {
            throw new RuntimeException("Kunde inte öppna fil: {$path}");
        }
        try {
            while (($line = fgets($h)) !== false) {
                $line = rtrim($line, "\r\n");
                if ($line === '') {
                    continue;
                }
                if ($encoding !== null && strcasecmp($encoding, 'UTF-8') !== 0) {
                    $line2 = @iconv($encoding, 'UTF-8//IGNORE', $line);
                    if ($line2 === false) {
                        throw new RuntimeException("Kunde inte konvertera rad från {$encoding} till UTF-8");
                    }
                    $line = $line2;
                }
                $item = json_decode($line, $assoc, 512, JSON_THROW_ON_ERROR);
                $onItem($item);
            }
        } finally {
            fclose($h);
        }
    }

    // --- Hjälpare ---

    private static function ensureFileReadable(string $path): void
    {
        if (!is_file($path)) {
            $rp = @realpath($path) ?: $path;
            throw new RuntimeException("Filen finns inte: {$rp}");
        }
        if (!is_readable($path)) {
            $rp = @realpath($path) ?: $path;
            throw new RuntimeException("Filen är inte läsbar: {$rp}");
        }
    }

    private static function detectDelimiter(string $path, ?string $preferred): string
    {
        if ($preferred !== null && $preferred !== '') {
            return $preferred;
        }
        $candidates = [',', ';', "\t", '|'];
        $h = fopen($path, 'r');
        if ($h === false) {
            return ',';
        }
        $counts = array_fill_keys($candidates, 0);
        $lines = 0;
        while (!feof($h) && $lines < 10) {
            $line = fgets($h);
            if ($line === false) {
                break;
            }
            foreach ($candidates as $c) {
                $counts[$c] += substr_count($line, $c);
            }
            $lines++;
        }
        fclose($h);
        arsort($counts);
        $best = array_key_first($counts);
        return $best ?? ',';
    }

    /**
     * @return array<string, mixed>
     */
    private static function xmlToArray(SimpleXMLElement $xml): array
    {
        $json = json_encode($xml, JSON_THROW_ON_ERROR);
        /** @var array<string, mixed> $arr */
        $arr = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        return $arr;
    }
}
