<?php

declare(strict_types=1);

namespace Radix\Support;

class Logger
{
    private string $dir;
    private string $channel;
    private int $maxBytes;
    private int $retentionDays;
    private ?string $lastCleanupDay = null; // instansbunden vakt

    public function __construct(
        ?string $channel = 'app',
        ?string $baseDir = null,
        ?int $maxBytes = null,
        ?int $retentionDays = null
    ) {
        $root = defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__, 3);
        $base = $baseDir ?: (rtrim($root, '/\\') . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs');
        if (!is_dir($base)) {
            @mkdir($base, 0o755, true);
        }
        $this->dir = $base;
        $this->channel = $channel ?: 'app';

        // Standard: 10 MB filstorlek, 14 dagars retention
        $this->maxBytes = $maxBytes ?? (10 * 1024 * 1024);
        $this->retentionDays = $retentionDays ?? 14;
    }

    /**
     * @param array<string,mixed> $context
     */
    public function info(string $message, array $context = []): void
    {
        $this->write('INFO', $message, $context);
    }

    /**
     * @param array<string,mixed> $context
     */
    public function error(string $message, array $context = []): void
    {
        $this->write('ERROR', $message, $context);
    }

    /**
     * @param array<string,mixed> $context
     */
    public function warning(string $message, array $context = []): void
    {
        $this->write('WARNING', $message, $context);
    }

    /**
     * @param array<string,mixed> $context
     */
    public function debug(string $message, array $context = []): void
    {
        $this->write('DEBUG', $message, $context);
    }

    /**
     * @param array<string,mixed> $context
     */
    private function write(string $level, string $message, array $context): void
    {
        $this->cleanupOldLogs(); // enkel daglig städning

        $line = sprintf(
            "[%s] %s.%s %s %s\n",
            date('Y-m-d H:i:s'),
            $this->channel,
            $level,
            $this->interpolate($message, $context),
            $this->contextToString($context)
        );

        $fileBase = $this->dir . DIRECTORY_SEPARATOR . sprintf('%s-%s.log', $this->channel, date('Y-m-d'));
        $file = $this->resolveWritableFile($fileBase);
        @file_put_contents($file, $line, FILE_APPEND);
        // Loggning får aldrig kasta – ignorera fel
    }

    private function resolveWritableFile(string $fileBase): string
    {
        // Om filen inte existerar eller är under gränsen, använd den
        if (!is_file($fileBase) || filesize($fileBase) === false || filesize($fileBase) < $this->maxBytes) {
            return $fileBase;
        }

        // Rulla: hitta nästa suffix: .1, .2, ...
        $i = 1;
        while (true) {
            $candidate = $fileBase . '.' . $i;
            if (!is_file($candidate) || filesize($candidate) === false || filesize($candidate) < $this->maxBytes) {
                return $candidate;
            }
            $i++;
            // Säkerhetsbroms (mycket osannolikt att nås)
            if ($i > 1000) {
                return $candidate; // skriv ändå
            }
        }
    }

    private function cleanupOldLogs(): void
    {
        $today = date('Y-m-d');
        if ($this->lastCleanupDay === $today) {
            return;
        }
        $this->lastCleanupDay = $today;

        $threshold = time() - ($this->retentionDays * 86400);

        $files = @scandir($this->dir) ?: [];
        foreach ($files as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $path = $this->dir . DIRECTORY_SEPARATOR . $f;
            if (!is_file($path)) {
                continue;
            }
            $mtime = @filemtime($path);
            if ($mtime !== false && $mtime < $threshold) {
                @unlink($path);
            }
        }
    }

    /**
     * @param array<string,mixed> $context
     */
    private function interpolate(string $message, array $context): string
    {
        $replace = [];
        foreach ($context as $k => $v) {
            if (is_scalar($v) || $v === null) {
                $replace['{' . $k . '}'] = (string) $v;
            }
        }
        return strtr($message, $replace);
    }

    /**
     * @param array<string,mixed> $context
     */
    private function contextToString(array $context): string
    {
        if ($context === []) {
            return '';
        }
        // Ta bort värden som redan interpolerats
        $rest = array_filter($context, function ($k) use ($context) {
            return str_contains($this->interpolate('{' . $k . '}', $context), '{' . $k . '}');
        }, ARRAY_FILTER_USE_KEY);
        if ($rest === []) {
            return '';
        }
        $json = @json_encode($rest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json === false ? '' : $json;
    }
}
