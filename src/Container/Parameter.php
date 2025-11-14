<?php

declare(strict_types=1);

namespace Radix\Container;

use Dflydev\DotAccessData\Data;

final class Parameter extends Data
{
    /**
     * @var array<string, mixed>
     */
    protected array $parameters = [];

    /**
     * Ersätt alla container-parametrar.
     *
     * @param array<string, mixed> $parameters
     */
    public function setParameters(array $parameters): void
    {
        $this->parameters = []; // Rensar tidigare parametrar

        foreach ($parameters as $key => $value) {
            if (!is_string($key) || empty($key)) {
                throw new \InvalidArgumentException('Parameter keys must be non-empty strings.');
            }

            $this->parameters[$key] = $value;
        }
    }

    /**
     * Lägg till flera container-parametrar.
     *
     * @param array<string, mixed> $parameters
     */
    public function addParameters(array $parameters): void
    {
        foreach ($parameters as $key => $value) {
            if (!is_string($key) || empty($key)) {
                throw new \InvalidArgumentException('Parameter keys must be non-empty strings.');
            }

            $this->parameters[$key] = $value;
        }
    }

    public function setParameter(string $name, mixed $value): void
    {
        if (empty($name)) {
            throw new \InvalidArgumentException('Parameter name must be a non-empty string.');
        }

        $this->parameters[$name] = $value;
    }

    public function getParameter(string $name, mixed $default = null): mixed
    {
        if (!isset($this->parameters[$name])) {
            // Logga eller hantera att parametern saknas (valfritt)
            if ($default === null) {
                throw new \InvalidArgumentException(sprintf('Parameter "%s" does not exist and no default value was provided.', $name));
            }

            return $default;
        }

        return $this->parameters[$name];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->parameters;
    }
}