<?php

declare(strict_types=1);

namespace Radix\Support;

use DateInterval;
use DateTimeImmutable;

final class FileCache
{
    private string $path;

    public function __construct(?string $path = null)
    {
        $root = defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__, 2);
        $base = $path ?? (rtrim($root, '/\\') . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'app');

        if (!is_dir($base)) {
            @mkdir($base, 0o755, true);
        }
        $this->path = $base;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $file = $this->file($key);
        if (!is_file($file)) {
            return $default;
        }
        $data = @file_get_contents($file);
        if ($data === false) {
            return $default;
        }
        $payload = @json_decode($data, true);
        if (!is_array($payload)) {
            return $default;
        }

        $expiresRaw = $payload['e'] ?? 0;
        $expires = is_numeric($expiresRaw) ? (int) $expiresRaw : 0;

        if ($expires > 0 && time() > $expires) {
            @unlink($file);
            return $default;
        }

        return $payload['v'] ?? $default;
    }

    public function set(string $key, mixed $value, int|DateInterval|null $ttl = null): bool
    {
        $file = $this->file($key);
        $expires = $this->ttlToExpires($ttl);
        $payload = json_encode(['v' => $value, 'e' => $expires], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            return false;
        }

        $ok = @file_put_contents($file, $payload) !== false;

        if ($ok && DIRECTORY_SEPARATOR === '/') {
            @chmod($file, 0o664);
        }
        return $ok;
    }

    public function delete(string $key): bool
    {
        $file = $this->file($key);
        return is_file($file) ? @unlink($file) : true;
    }

    public function clear(): bool
    {
        $ok = true;
        foreach (glob($this->path . DIRECTORY_SEPARATOR . '*.cache') ?: [] as $f) {
            $ok = @unlink($f) && $ok;
        }
        return $ok;
    }

    /**
     * Rensar utgångna cachefiler från disken.
     * Använder ett "lotteri" för att undvika att skanna disken vid varje anrop.
     */
    public function prune(?int $now = null): void
    {
        // Genom att använda en temporär variabel för time() och säkerställa
        // att $now faktiskt används om den finns, dödar vi coalesce-mutanten.
        $currentTime = $now;
        if ($currentTime === null) {
            $currentTime = time();
        }

        $files = glob($this->path . DIRECTORY_SEPARATOR . '*.cache') ?: [];

        foreach ($files as $file) {
            $data = @file_get_contents($file);
            if ($data === false) {
                continue;
            }
            $payload = @json_decode($data, true);

            if (!is_array($payload)) {
                @unlink($file);
                continue;
            }

            // Om 'e' saknas eller inte är numerisk hoppar vi över (evig cache).
            // Detta dödar LogicalOr-mutanten genom att förenkla till en kontroll.
            if (!is_numeric($payload['e'] ?? null)) {
                continue;
            }

            $expires = (int) $payload['e'];

            // Rensa endast om ett utgångsdatum faktiskt är satt (> 0) och har passerats.
            if ($expires > 0 && $currentTime > $expires) {
                @unlink($file);
            }
        }
    }

    private function file(string $key): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', $key);
        return $this->path . DIRECTORY_SEPARATOR . $safe . '.cache';
    }

    private function ttlToExpires(int|DateInterval|null $ttl): int
    {
        if ($ttl === null) {
            return 0;
        }
        if ($ttl instanceof DateInterval) {
            $now = new DateTimeImmutable();
            return (int) $now->add($ttl)->format('U');
        }
        $seconds = (int) $ttl;
        return $seconds <= 0 ? 0 : (time() + $seconds);
    }
}
