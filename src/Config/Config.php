<?php

declare(strict_types=1);

namespace Radix\Config;

use Closure;

class Config
{
    /** @var array<string,mixed> */
    private array $config;

    /**
     * Konstruera `Config` med fördefinierade värden.
     *
     * @param array<string,mixed> $config
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    /**
     * Hämta ett konfigurationsvärde med stöd för standardvärde och lazy-loading.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        // Separera nyckeln med "."
        $keys = explode('.', $key);
        $value = $this->config;

        foreach ($keys as $subKey) {
            if (!is_array($value) || !array_key_exists($subKey, $value)) {
                return $default; // Returnera standard om nyckeln ej finns
            }

            $value = $value[$subKey];
        }

        // Kontrollera om värdet är callable
        if (is_callable($value) && is_object($value) && ($value instanceof Closure)) {
            $this->config[$key] = $value = $value();
        }

        return $value;
    }

    /**
     * Sätt eller uppdatera ett konfigurationsvärde.
     */
    public function set(string $key, mixed $value): void
    {
        $this->config[$key] = $value;
    }

    /**
     * Ladda flera nyckelvärden i konfigurationslagret.
     *
     * @param array<string,mixed> $additionalConfig
     */
    public function load(array $additionalConfig): void
    {
        $this->config = array_merge($this->config, $additionalConfig);
    }
}
