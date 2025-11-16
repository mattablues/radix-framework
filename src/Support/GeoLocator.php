<?php

declare(strict_types=1);

namespace Radix\Support;

use Radix\Http\Exception\GeoLocatorException;

class GeoLocator
{
    private string $baseUrl = 'http://ip-api.com/json'; // Ny URL till IP-API

    /**
     * @return array<string,mixed>
     */
    public function getLocation(?string $ip = null): array
    {
        $serverIp = $_SERVER['REMOTE_ADDR'] ?? null;

        if ($ip === null) {
            $ip = is_string($serverIp) ? $serverIp : '';
        }

        $url = $this->baseUrl . '/' . $ip;
        $data = @file_get_contents($url);

        if ($data === false) {
            throw new GeoLocatorException("Kunde inte nå API: $url");
        }

        $location = json_decode($data, true);

        if (!is_array($location) || !isset($location['status'])) {
            throw new GeoLocatorException("Ogiltig API-respons: $data");
        }

        if ($location['status'] !== 'success') {
            throw new GeoLocatorException("API fel: " . $location['message']);
        }

        return $location;
    }

    public function get(string $key, ?string $ip = null): mixed
    {
        $location = $this->getLocation($ip);
        return $location[$key] ?? null;
    }
}