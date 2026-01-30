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
        // --- Presence (App) ---
        $this->require('APP_ENV');
        $this->require('APP_URL');

        $this->require('APP_LANG');
        $this->require('APP_NAME');
        $this->require('APP_TIMEZONE');
        $this->require('APP_COPY');
        $this->require('APP_COPY_YEAR');

        // --- Presence (Access/Security/Health) ---
        $this->require('HEALTH_REQUIRE_TOKEN');
        $this->requireIfBoolTrue('HEALTH_REQUIRE_TOKEN', 'API_TOKEN');

        // I production vill vi att allowlist finns (säkerhetsgrej).
        $this->requireInProduction('HEALTH_IP_ALLOWLIST');

        // TRUSTED_PROXY är inte ett måste (du kan köra utan proxy),
        // men om den är satt ska den vara korrekt.
        $this->ipList('TRUSTED_PROXY', allowEmpty: true);

        // HEALTH_IP_ALLOWLIST valideras om den finns (och i prod kräver vi den via requireInProduction ovan).
        $this->ipList('HEALTH_IP_ALLOWLIST', allowEmpty: true);

        // --- Presence (CORS) ---
        $this->require('CORS_ALLOW_ORIGIN');
        $this->require('CORS_ALLOW_CREDENTIALS');

        // --- Presence (Locator) ---
        $this->require('LOCATOR_COUNTRY');
        $this->require('LOCATOR_CITY');
        $this->require('LOCATOR_CITY_URL');

        // --- Presence (ORM) ---
        // Krävs i production för att undvika “magiska” autoload-problem.
        $this->requireInProduction('ORM_MODEL_NAMESPACE');

        // --- Presence (Database) ---
        $this->require('DB_DRIVER');
        $this->require('DB_HOST');
        $this->require('DB_PORT');
        $this->require('DB_NAME');
        $this->require('DB_USERNAME');
        $this->require('DB_CHARSET');
        // DB_PASSWORD får vara tom (t.ex. local)

        // --- Presence (Session) ---
        $this->require('SESSION_DRIVER');
        $this->require('SESSION_LIFETIME');
        $this->requireIfEquals('SESSION_DRIVER', 'file', 'SESSION_FILE_PATH');
        $this->requireIfEquals('SESSION_DRIVER', 'database', 'SESSION_TABLE');

        // --- Presence (Keys) ---
        $this->require('SECURE_TOKEN_HMAC');
        $this->require('SECURE_ENCRYPTION_KEY');

        // --- Presence (Cache paths) ---
        $this->require('CACHE_ROOT');
        $this->require('VIEWS_CACHE_PATH');
        $this->require('APP_CACHE_PATH');
        $this->require('HEALTH_CACHE_PATH');
        $this->require('RATELIMIT_CACHE_PATH');

        // --- Presence (Mail) ---
        $this->require('MAIL_HOST');
        $this->require('MAIL_PORT');
        $this->require('MAIL_EMAIL');
        $this->require('MAIL_FROM'); // namn, inte email

        $this->require('MAIL_DEBUG');
        $this->require('MAIL_CHARSET');
        $this->require('MAIL_SECURE');
        $this->require('MAIL_AUTH');

        // Om auth är på måste konto + lösen finnas
        $this->requireIfBoolTrue('MAIL_AUTH', 'MAIL_ACCOUNT');
        $this->requireIfBoolTrue('MAIL_AUTH', 'MAIL_PASSWORD');

        // --- Enums ---
        $this->enum('APP_ENV', ['prod', 'production', 'dev', 'development', 'local', 'test']);
        $this->enum('DB_DRIVER', ['mysql', 'sqlite']);
        $this->enum('SESSION_DRIVER', ['file', 'database']);
        $this->enum('MAIL_SECURE', ['none', 'ssl', 'tls'], allowEmpty: true);

        // --- Bools (0/1, true/false) ---
        foreach (['APP_DEBUG', 'APP_MAINTENANCE', 'APP_PRIVATE', 'MAIL_AUTH', 'MAIL_DEBUG', 'CORS_ALLOW_CREDENTIALS', 'HEALTH_REQUIRE_TOKEN'] as $b) {
            $this->boolLike($b, allowEmpty: true);
        }

        // --- Ints ---
        $this->intLike('DB_PORT', min: 1, max: 65535);
        $this->intLike('MAIL_PORT', min: 1, max: 65535);
        $this->intLike('SESSION_LIFETIME', allowEmpty: true);
        $this->intLike('APP_COPY_YEAR', min: 1970, max: 3000);

        // --- Formats ---
        $this->url('APP_URL');
        $this->url('LOCATOR_CITY_URL');
        $this->url('CORS_ALLOW_ORIGIN');
        $this->email('MAIL_EMAIL');

        // Timezone (bättre fel tidigt än konstiga datum senare)
        $this->timezone('APP_TIMEZONE');

        // --- Paths (skapa dir om den saknas och kontrollera write) ---
        foreach (['VIEWS_CACHE_PATH', 'APP_CACHE_PATH', 'HEALTH_CACHE_PATH', 'RATELIMIT_CACHE_PATH'] as $p) {
            $this->writablePath($p, $basePath);
        }

        // SESSION_FILE_PATH ska bara vara en katalog om driver=file (annars ignorerar vi den helt)
        if (strtolower($this->get('SESSION_DRIVER')) === 'file') {
            $this->writablePath('SESSION_FILE_PATH', $basePath);
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

    private function isProduction(): bool
    {
        $env = strtolower($this->get('APP_ENV'));
        return in_array($env, ['prod', 'production'], true);
    }

    private function requireInProduction(string $key): void
    {
        if ($this->isProduction() && $this->get($key) === '') {
            $this->errors[] = "$key is required in production";
        }
    }

    private function requireIfBoolTrue(string $boolKey, string $requiredKey): void
    {
        $v = strtolower($this->get($boolKey));
        if (in_array($v, ['1', 'true'], true) && $this->get($requiredKey) === '') {
            $this->errors[] = "$requiredKey is required when $boolKey is true";
        }
    }

    private function requireIfEquals(string $key, string $equals, string $requiredKey): void
    {
        $v = strtolower($this->get($key));
        if ($v === strtolower($equals) && $this->get($requiredKey) === '') {
            $this->errors[] = "$requiredKey is required when $key=$equals";
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

        $allowedLower = array_map(
            static fn(string $s): string => strtolower($s),
            $allowed
        );

        if ($v === '' || !in_array(strtolower($v), $allowedLower, true)) {
            $this->errors[] = "$key must be one of: " . implode(', ', $allowed);
        }
    }

    private function boolLike(string $key, bool $allowEmpty = false): void
    {
        $v = strtolower($this->get($key));
        if ($v === '' && $allowEmpty) {
            return;
        }
        if (!in_array($v, ['1', '0', 'true', 'false'], true)) {
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

    private function timezone(string $key, bool $allowEmpty = false): void
    {
        $v = $this->get($key);
        if ($v === '' && $allowEmpty) {
            return;
        }
        if ($v === '' || !in_array($v, timezone_identifiers_list(), true)) {
            $this->errors[] = "$key must be a valid timezone identifier";
        }
    }

    private function ipList(string $key, bool $allowEmpty = false): void
    {
        $raw = $this->get($key);

        if ($raw === '' && $allowEmpty) {
            return;
        }

        if ($raw === '') {
            $this->errors[] = "$key must be a comma-separated list of IPs or CIDRs";
        } else {
            $items = array_values(
                array_filter(
                    array_map('trim', explode(',', $raw)),
                    static fn(string $s): bool => $s !== ''
                )
            );

            for ($i = 0, $n = count($items); $i < $n; $i++) {
                $item = $items[$i];

                if ($this->isValidIpOrCidr($item)) {
                    continue;
                }
                $this->errors[] = "$key contains invalid IP/CIDR: $item";
            }
        }
    }

    private function isValidIpOrCidr(string $value): bool
    {
        // IP (v4/v6)
        if (filter_var($value, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        // CIDR v4/v6: "ip/prefix"
        if (!str_contains($value, '/')) {
            return false;
        }

        [$ip, $prefixRaw] = explode('/', $value, 2);
        $ip = trim($ip);
        $prefixRaw = trim($prefixRaw);

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        if ($prefixRaw === '' || !ctype_digit($prefixRaw)) {
            return false;
        }

        $prefix = (int) $prefixRaw;

        $isV4 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        if ($isV4) {
            return $prefix >= 0 && $prefix <= 32;
        }

        return $prefix >= 0 && $prefix <= 128;
    }

    private function isAbsolute(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        // Unix-style absolut: /var/www/...
        if (str_starts_with($path, '/')) {
            return true;
        }

        // Windows drive: C:\... eller C:/...
        if (preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1) {
            return true;
        }

        // Windows UNC: \\server\share\...
        if (str_starts_with($path, '\\\\')) {
            return true;
        }

        // OBS: En ensam ledande "\" (t.ex. "\cache\foo") behandlas som relativ i Radix,
        // så att den kan byggas under basePath (samma idé som i Dotenv::isRelativePath()).
        return false;
    }

    private function writablePath(string $key, string $basePath): void
    {
        $v = $this->get($key);

        if ($v === '') {
            $this->errors[] = "$key is required (path)";
            return;
        }

        // Bestäm absolut/relativt på originalsträngen (innan vi normaliserar separators).
        // Detta är viktigt för Radix-regeln: "\cache\foo" är relativ (inte absolut) även på Windows.
        $isAbs = $this->isAbsolute($v);

        // Normalisera separators så att både "\cache\views" och "cache/views" fungerar cross-platform.
        $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $v);

        $abs = $isAbs
            ? $normalized
            : rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . ltrim($normalized, '/\\');

        if (!is_dir($abs)) {
            @mkdir($abs, 0o755, true);
        }

        if (!is_dir($abs) || !is_writable($abs)) {
            $this->errors[] = "$key must be an existing writable directory (got: $abs)";
        }
    }
}
