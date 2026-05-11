<?php

declare(strict_types=1);

namespace Radix\Support;

use Radix\Http\Exception\GeoLocatorException;

class GeoLocator
{
    private string $baseUrl = 'http://ip-api.com/json';

    private int $timeout = 2;

    public function __construct(?string $baseUrl = null, ?int $timeout = null)
    {
        $configuredBaseUrl = $baseUrl ?? getenv('GEOLOCATOR_BASE_URL');

        if (is_string($configuredBaseUrl) && trim($configuredBaseUrl) !== '') {
            $this->baseUrl = rtrim(trim($configuredBaseUrl), '/');
        }

        $configuredTimeout = $timeout ?? getenv('GEOLOCATOR_TIMEOUT');

        if (is_int($configuredTimeout) && $configuredTimeout > 0) {
            $this->timeout = $configuredTimeout;
        } elseif (is_string($configuredTimeout) && ctype_digit($configuredTimeout)) {
            $parsedTimeout = (int) $configuredTimeout;

            if ($parsedTimeout > 0) {
                $this->timeout = $parsedTimeout;
            }
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function getLocation(?string $ip = null): array
    {
        $serverIp = $_SERVER['REMOTE_ADDR'] ?? null;

        if ($ip === null) {
            $ip = is_string($serverIp) ? trim($serverIp) : '';
        }

        $url = $this->baseUrl . '/' . $ip;

        $context = stream_context_create([
            'http' => [
                'timeout' => $this->timeout,
            ],
        ]);

        $data = @file_get_contents($url, false, $context);

        if ($data === false) {
            throw new GeoLocatorException("Kunde inte nå API: $url");
        }

        $location = json_decode($data, true);

        if (!is_array($location) || !isset($location['status'])) {
            throw new GeoLocatorException("Ogiltig API-respons: $data");
        }

        if ($location['status'] !== 'success') {
            $rawMessage = $location['message'] ?? 'okänt fel';

            if (is_string($rawMessage)) {
                $message = $rawMessage;
            } else {
                $encoded = json_encode($rawMessage, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $message = $encoded === false ? 'okänt fel' : $encoded;
            }

            throw new GeoLocatorException('API fel: ' . $message);
        }

        /** @var array<string,mixed> $location */
        return $location;
    }

    public function get(string $key, ?string $ip = null): mixed
    {
        $location = $this->getLocation($ip);

        return $location[$key] ?? null;
    }
}
