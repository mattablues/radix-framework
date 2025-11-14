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
        $array[] = $value;
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
        $index = array_search($value, $array, true);

        if ($index !== false) {
            unset($array[$index]);
        }

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

        if ($callback) {
            usort($array, $callback);
        } else {
            sort($array);
        }

        return $array;
    }

    /**
     * Returnerar arrayens längd.
     *
     * @return int
     */
    public function length(): int
    {
        return count($this->getValue());
    }
}