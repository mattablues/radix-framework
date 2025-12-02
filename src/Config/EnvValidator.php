<?php

declare(strict_types=1);

namespace Radix\Config;

use RuntimeException;

final class EnvValidator
{
    /** @var array<int,string> */
    private array $errors = [];

    public function validate(string $basePath = ''): void
    {
        // Presence
        $this->require('APP_ENV');
        $this->require('APP_URL');
        $this->require('DB_DRIVER');
        $this->require('DB_HOST');
        $this->require('DB_PORT');
        $this->require('DB_NAME');
        $this->require('DB_USERNAME');
        $this->require('SESSION_DRIVER');
        $this->require('SECURE_TOKEN_HMAC');
        $this->require('SECURE_ENCRYPTION_KEY');
        $this->require('VIEWS_CACHE_PATH');
        $this->require('APP_CACHE_PATH');
        $this->require('HEALTH_CACHE_PATH');
        $this->require('RATELIMIT_CACHE_PATH');
        $this->require('MAIL_HOST');
        $this->require('MAIL_PORT');
        $this->require('MAIL_EMAIL');
        $this->require('MAIL_FROM'); // endast namn krävs
        // ... existing code ...

        // Enums
        $this->enum('APP_ENV', ['prod','production','dev','development','test']);
        $this->enum('SESSION_DRIVER', ['file','database']);
        $this->enum('MAIL_SECURE', ['none','ssl','tls'], allowEmpty: true);

        // Bools (0/1, true/false)
        foreach (['APP_DEBUG','APP_MAINTENANCE','APP_PRIVATE','MAIL_AUTH','MAIL_DEBUG','CORS_ALLOW_CREDENTIALS','HEALTH_REQUIRE_TOKEN'] as $b) {
            $this->boolLike($b, allowEmpty: true);
        }

        // Ints
        $this->intLike('DB_PORT');
        $this->intLike('MAIL_PORT', min: 1, max: 65535);
        $this->intLike('SESSION_LIFETIME', allowEmpty: true);

        // Formats
        $this->url('APP_URL');
        $this->email('MAIL_EMAIL'); // e-postadress
        // Ta bort e-postvalidering för MAIL_FROM (kan vara namn)

        // Paths (absolut eller relativ -> gör absolut med basePath)
        foreach (['VIEWS_CACHE_PATH','APP_CACHE_PATH','HEALTH_CACHE_PATH','RATELIMIT_CACHE_PATH'] as $p) {
            $this->writablePath($p, $basePath);
        }

        if ($this->errors !== []) {
            throw new RuntimeException("Invalid environment configuration:\n - " . implode("\n - ", $this->errors));
        }
    }

    private function get(string $key): string
    {
        $v = getenv($key);
        return $v === false ? '' : trim((string) $v);
    }

    private function require(string $key): void
    {
        if ($this->get($key) === '') {
            $this->errors[] = "$key is required";
        }
    }

    /**
     * @param array<int,string> $allowed
     */
    private function enum(string $key, array $allowed, bool $allowEmpty = false): void
    {
        $v = $this->get($key);
        if ($v === '' && $allowEmpty) {
            return;
        }
        if ($v === '' || !in_array(strtolower($v), array_map(
            /**
             * @param string $s
             */
            static fn(string $s): string => strtolower($s),
            $allowed
        ), true)) {
            $this->errors[] = "$key must be one of: " . implode(', ', $allowed);
        }
    }

    private function boolLike(string $key, bool $allowEmpty = false): void
    {
        $v = strtolower($this->get($key));
        if ($v === '' && $allowEmpty) {
            return;
        }
        if (!in_array($v, ['1','0','true','false'], true)) {
            $this->errors[] = "$key must be boolean-like (0/1/true/false)";
        }
    }

    private function intLike(string $key, bool $allowEmpty = false, ?int $min = null, ?int $max = null): void
    {
        $v = $this->get($key);
        if ($v === '' && $allowEmpty) {
            return;
        }
        if ($v === '' || !ctype_digit(ltrim($v, '+'))) {
            $this->errors[] = "$key must be integer";
            return;
        }
        $i = (int) $v;
        if ($min !== null && $i < $min) {
            $this->errors[] = "$key must be >= $min";
        }
        if ($max !== null && $i > $max) {
            $this->errors[] = "$key must be <= $max";
        }
    }

    private function url(string $key): void
    {
        $v = $this->get($key);
        if ($v === '' || filter_var($v, FILTER_VALIDATE_URL) === false) {
            $this->errors[] = "$key must be a valid URL";
        }
    }

    private function email(string $key, bool $allowEmpty = false): void
    {
        $v = $this->get($key);
        if ($v === '' && $allowEmpty) {
            return;
        }
        if ($v === '' || filter_var($v, FILTER_VALIDATE_EMAIL) === false) {
            $this->errors[] = "$key must be a valid email";
        }
    }

    private function isAbsolute(string $path): bool
    {
        return $path !== '' && (str_starts_with($path, DIRECTORY_SEPARATOR) || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1);
    }

    private function writablePath(string $key, string $basePath): void
    {
        $v = $this->get($key);
        $abs = $this->isAbsolute($v) ? $v : rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . ltrim($v, '/\\');
        if ($v === '') {
            $this->errors[] = "$key is required (path)";
            return;
        }
        if (!is_dir($abs)) {
            @mkdir($abs, 0o755, true);
        }
        if (!is_dir($abs) || !is_writable($abs)) {
            $this->errors[] = "$key must be an existing writable directory (got: $abs)";
        }
    }
}
