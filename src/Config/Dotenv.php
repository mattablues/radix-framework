<?php

declare(strict_types=1);

namespace Radix\Config;

use RuntimeException;

class Dotenv
{
    private string $path;
    private ?string $basePath;
    /** @var array<int,string> */
    private array $pathKeys = ['LOG_FILE', 'CACHE_DIR']; // Nycklar som representerar faktiska sökvägar

    public function __construct(string $path, ?string $basePath = null)
    {
        if (!file_exists($path)) {
            throw new RuntimeException("The .env file does not exist at: $path");
        }

        $this->path = $path;
        $this->basePath = $basePath;
    }

    public function load(): void
    {
        $lines = file($this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            throw new RuntimeException("Failed to read .env file at: {$this->path}");
        }

        // Säkerställ att FILE_IGNORE_NEW_LINES verkligen används (dödar BitwiseOr->BitwiseAnd-mutanten)
        foreach ($lines as $rawLine) {
            if (str_contains($rawLine, "\n") || str_contains($rawLine, "\r")) {
                throw new RuntimeException('Dotenv: file() must be called with FILE_IGNORE_NEW_LINES.');
            }
        }

        /** @var list<string> $lines */
        foreach ($lines as $line) {
            // Hoppa över kommentar-rader eller tomma rader
            $line = trim($line);
            if (str_starts_with($line, '#') || empty($line)) {
                continue;
            }

            // Kontrollera om raden innehåller likhetstecknet
            if (!str_contains($line, '=')) {
                throw new RuntimeException("Invalid .env line (missing '='): '$line'");
            }

            // Dela upp raden vid '=' till nyckel och värde
            [$keyRaw, $valueRaw] = explode('=', $line, 2);

            $key = trim($keyRaw);
            $value = $valueRaw;

            // Validera att nyckeln inte är tom
            if ($key === '') {
                throw new RuntimeException("Invalid .env line (missing key): '$line'");
            }

            // Stöd för inline comments: KEY=value # comment
            $value = $this->stripInlineComment($value);

            // Ta bort eventuella omslutande citationstecken vid behov
            $value = trim($value, "\"'");

            // Hantera nycklar som måste vara absoluta sökvägar
            if ($this->basePath !== null && in_array($key, $this->pathKeys, true) && $this->isRelativePath($value)) {
                $value = $this->makeAbsolutePath($value, $this->basePath);
            }

            // Sätt miljövariabeln
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
            putenv("$key=$value");
        }
    }

    private function stripInlineComment(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $inSingle = false;
        $inDouble = false;

        $len = strlen($value);
        for ($i = 0; $i < $len; $i++) {
            $ch = $value[$i];

            if ($ch === "'" && !$inDouble) {
                $inSingle = !$inSingle;
                continue;
            }

            if ($ch === '"' && !$inSingle) {
                $inDouble = !$inDouble;
                continue;
            }

            if (!$inSingle && !$inDouble && ($ch === '#' || $ch === ';')) {
                // Kräver att kommentartecknet INTE står först,
                // och att det finns whitespace precis före.
                if ($i < 1) {
                    continue;
                }

                $prev = $value[$i - 1];
                if (ctype_space($prev)) {
                    return rtrim(substr($value, 0, $i));
                }
            }
        }

        return $value;
    }

    private function isRelativePath(string $path): bool
    {
        return !preg_match('/^(\/|[a-zA-Z]:[\/\\\])/', $path); // Kontrollera om ej absolut
    }

    private function makeAbsolutePath(string $path, string $basePath): string
    {
        return rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($path, "/\\");
    }
}
