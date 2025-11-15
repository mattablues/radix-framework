<?php

declare(strict_types=1);

namespace Radix\Config;

class Dotenv
{
    private string $path;
    private ?string $basePath;
    /** @var array<int,string> */
    private array $pathKeys = ['LOG_FILE', 'CACHE_DIR']; // Nycklar som representerar faktiska sökvägar

    public function __construct(string $path, ?string $basePath = null)
    {
        if (!file_exists($path)) {
            throw new \RuntimeException("The .env file does not exist at: $path");
        }

        $this->path = $path;
        $this->basePath = $basePath;
    }

    public function load(): void
    {
        $lines = file($this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            throw new \RuntimeException("Failed to read .env file at: {$this->path}");
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
                throw new \RuntimeException("Invalid .env line (missing '='): '$line'");
            }

            // Dela upp raden vid '=' till nyckel och värde
            [$key, $value] = array_map('trim', explode('=', $line, 2));

            // Validera att både nyckel och värde inte är tomma
            if (empty($key)) {
                throw new \RuntimeException("Invalid .env line (missing key): '$line'");
            }

            // Ta bort eventuella omslutande citationstecken vid behov
            $value = trim(($value ?? ''), '"\'');

            // Hantera nycklar som måste vara absoluta sökvägar
            if ($this->basePath !== null && in_array($key, $this->pathKeys, true) && $this->isRelativePath($value)) {
                $value = $this->makeAbsolutePath($value, $this->basePath);
            }

            // Sätt miljövariabeln
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value; // För kompatibilitet med äldre kod som förlitar sig på $_SERVER
            putenv("$key=$value");
        }
    }

    private function isRelativePath(string $path): bool
    {
        return !preg_match('/^(\/|[a-zA-Z]:[\/\\\])/', $path); // Kontrollera om ej absolut
    }

    private function makeAbsolutePath(string $path, string $basePath): string
    {
        return rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR);
    }
}