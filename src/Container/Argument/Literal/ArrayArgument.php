<?php

declare(strict_types=1);

namespace Radix\Container\Argument\Literal;

use Radix\Container\Argument\LiteralArgument;
use Radix\Container\Exception\ContainerInvalidArgumentException;

class ArrayArgument extends LiteralArgument
{
    /**
     * @param array<int|string, mixed> $value
     */
    public function __construct(array $value)
    {
        if (empty($value)) {
            throw new ContainerInvalidArgumentException('Array cannot be empty.');
        }

        $this->validateArray($value);
        parent::__construct($value, LiteralArgument::TYPE_ARRAY);
    }

    /**
     * Validera att given array uppfyller samlingens krav.
     *
     * @param array<int|string, mixed> $value
     */
    private function validateArray(array $value): void
    {
        // Exempel: Lägg till valideringslogik här om nödvändigt.
        if (empty($value)) {
            throw new ContainerInvalidArgumentException('Array cannot be empty.');
        }
    }

    /**
     * Lägg till ett värde i samlingen och returnera den uppdaterade arrayen.
     *
     * @return array<int|string, mixed>
     */
    public function addValue(mixed $value): array
    {
        $array = $this->getValue();

        if (!is_array($array)) {
            // Borde inte hända eftersom konstruktorn kräver array,
            // men skyddar runtime/statisk analys.
            throw new ContainerInvalidArgumentException('Underlying value is not an array.');
        }

        $array[] = $value;

        /** @var array<int|string, mixed> $array */
        return $array;
    }

    /**
     * Ta bort alla förekomster av ett visst värde och returnera den uppdaterade arrayen.
     *
     * @return array<int|string, mixed>
     */
    public function removeValue(mixed $value): array
    {
        $array = $this->getValue();

        if (!is_array($array)) {
            throw new ContainerInvalidArgumentException('Underlying value is not an array.');
        }

        $index = array_search($value, $array, true);

        if ($index !== false) {
            unset($array[$index]);
        }

        /** @var array<int|string, mixed> $array */
        return $array;
    }

    /**
     * Sortera samlingens värden och returnera en ny array.
     *
     * Om $callback är satt används den som jämförelsefunktion (samma signatur som i usort).
     *
     * @param callable(mixed, mixed): int|null $callback
     * @return array<int|string, mixed>
     */
    public function sort(?callable $callback = null): array
    {
        $array = $this->getValue();

        if (!is_array($array)) {
            throw new ContainerInvalidArgumentException('Underlying value is not an array.');
        }

        if ($callback !== null) {
            /** @var array<int, mixed> $array */
            usort($array, $callback);
        } else {
            /** @var array<int, mixed> $array */
            sort($array);
        }

        /** @var array<int|string, mixed> $array */
        return $array;
    }

    /**
     * Returnerar arrayens längd.
     *
     * @return int
     */
    public function length(): int
    {
        $array = $this->getValue();

        if (!is_array($array)) {
            throw new ContainerInvalidArgumentException('Underlying value is not an array.');
        }

        return count($array);
    }
}
