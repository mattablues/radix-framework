<?php

declare(strict_types=1);

namespace Radix\Support;

use Radix\Http\Exception\GeoLocatorException;

class GeoLocator
{
    private const string DEFAULT_BASE_URL = 'http://ip-api.com/json';
    private const int DEFAULT_TIMEOUT_SECONDS = 2;

    private string $baseUrl;
    private int $timeoutSeconds;
    private bool $enabled;

    public function __construct(?string $baseUrl = null, ?int $timeoutSeconds = null, ?bool $enabled = null)
    {
        $this->baseUrl = $this->resolveBaseUrl($baseUrl);
        $this->timeoutSeconds = $this->resolveTimeout($timeoutSeconds);
        $this->enabled = $enabled ?? $this->resolveEnabled();
    }

    /**
     * @return array<string,mixed>
     */
    public function getLocation(?string $ip = null): array
    {
        if (!$this->enabled) {
            throw new GeoLocatorException('GeoLocator är avstängd via konfiguration.');
        }

        $ip = $this->resolveIp($ip);

        if ($ip === '') {
            throw new GeoLocatorException('Kunde inte avgöra IP-adress för geolocation.');
        }

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            throw new GeoLocatorException("Ogiltig IP-adress för geolocation: {$ip}");
        }

        $url = $this->baseUrl . '/' . rawurlencode($ip);

        $context = stream_context_create([
            'http' => [
                'timeout' => $this->timeoutSeconds,
                'ignore_errors' => true,
                'header' => "Accept: application/json\r\n",
            ],
        ]);

        $data = @file_get_contents($url, false, $context);

        if ($data === false || $data === '') {
            throw new GeoLocatorException("Kunde inte nå GeoLocator API: {$url}");
        }

        $location = json_decode($data, true);

        if (!is_array($location) || !isset($location['status'])) {
            throw new GeoLocatorException("Ogiltig GeoLocator API-respons: {$data}");
        }

        if ($location['status'] !== 'success') {
            $rawMessage = $location['message'] ?? 'okänt fel';

            if (is_string($rawMessage)) {
                $message = $rawMessage;
            } else {
                $encoded = json_encode($rawMessage, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $message = $encoded === false ? 'okänt fel' : $encoded;
            }

            throw new GeoLocatorException('GeoLocator API fel: ' . $message);
        }

        /** @var array<string,mixed> $location */
        return $location;
    }

    public function get(string $key, ?string $ip = null): mixed
    {
        $location = $this->getLocation($ip);

        return $location[$key] ?? null;
    }

    private function resolveIp(?string $ip): string
    {
        if (is_string($ip) && $ip !== '') {
            return trim($ip);
        }

        $serverIp = $_SERVER['REMOTE_ADDR'] ?? null;

        if (!is_string($serverIp)) {
            return '';
        }

        return trim($serverIp);
    }

    private function resolveBaseUrl(?string $baseUrl): string
    {
        if (is_string($baseUrl) && trim($baseUrl) !== '') {
            return rtrim(trim($baseUrl), '/');
        }

        $envBaseUrl = getenv('GEOLOCATOR_BASE_URL');

        if (is_string($envBaseUrl) && trim($envBaseUrl) !== '') {
            return rtrim(trim($envBaseUrl), '/');
        }

        return self::DEFAULT_BASE_URL;
    }

    private function resolveTimeout(?int $timeoutSeconds): int
    {
        if ($timeoutSeconds !== null && $timeoutSeconds > 0) {
            return $timeoutSeconds;
        }

        $envTimeout = getenv('GEOLOCATOR_TIMEOUT');

        if (is_string($envTimeout) && ctype_digit($envTimeout)) {
            $timeout = (int) $envTimeout;

            if ($timeout > 0) {
                return min($timeout, 10);
            }
        }

        return self::DEFAULT_TIMEOUT_SECONDS;
    }

    private function resolveEnabled(): bool
    {
        $value = getenv('GEOLOCATOR_ENABLED');

        if ($value === false) {
            return true;
        }

        $normalized = strtolower(trim((string) $value));

        if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }

        if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        return true;
    }
}
