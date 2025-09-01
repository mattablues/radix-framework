<?php

declare(strict_types=1);

namespace Radix\Container;

use Dflydev\DotAccessData\Data;

final class Parameter extends Data
{
    protected array $parameters = [];

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
        if (!is_string($name) || empty($name)) {
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

    public function toArray(): array
    {
        return array_filter(
            $this->parameters,
            static fn($key) => is_string($key),
            ARRAY_FILTER_USE_KEY
        );
    }
}