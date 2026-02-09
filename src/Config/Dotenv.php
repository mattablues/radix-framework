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
            if (!is_string($rawLine)) {
                throw new RuntimeException('Dotenv: unexpected non-string line from file().');
            }

            if (str_contains($rawLine, "\n") || str_contains($rawLine, "\r")) {
                throw new RuntimeException('Dotenv: file() must be called with FILE_IGNORE_NEW_LINES.');
            }
        }

        /** @var list<string> $lines */
        foreach ($lines as $line) {
            $line = trim($line);
            if (str_starts_with($line, '#') || empty($line)) {
                continue;
            }

            if (!str_contains($line, '=')) {
                throw new RuntimeException("Invalid .env line (missing '='): '$line'");
            }

            // Dela upp raden vid '=' till nyckel och värde (robust mot mutanter/edge cases)
            $parts = explode('=', $line, 2);
            $keyRaw = $parts[0] ?? '';
            $valueRaw = $parts[1] ?? '';

            $key = trim($keyRaw);
            $value = $valueRaw;

            if ($key === '') {
                throw new RuntimeException("Invalid .env line (missing key): '$line'");
            }

            $value = $this->stripInlineComment($value);

            $value = trim($value, "\"'");

            if ($this->basePath !== null && in_array($key, $this->pathKeys, true) && $this->isRelativePath($value)) {
                $value = $this->makeAbsolutePath($value, $this->basePath);
            }

            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
            putenv("$key=$value");
        }
    }

    private function stripInlineComment(string $value): string
    {
        $value = trim($value);

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
                // Kommentartecknet får inte stå först
                if ($i === 0) {
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
