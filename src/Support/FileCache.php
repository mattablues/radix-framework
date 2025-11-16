<?php

declare(strict_types=1);

namespace Radix\Support;

use DateInterval;

final class FileCache
{
    private string $path;

    public function __construct(?string $path = null)
    {
        $root = defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__, 2);
        $base = $path ?? (rtrim($root, '/\\') . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'app');

        if (!is_dir($base)) {
            @mkdir($base, 0755, true);
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
        if ($ok) {
            @chmod($file, 0640);
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
            $now = new \DateTimeImmutable();
            return (int) $now->add($ttl)->format('U');
        }
        $seconds = (int) $ttl;
        return $seconds <= 0 ? 0 : (time() + $seconds);
    }
}