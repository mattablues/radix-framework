<?php

declare(strict_types=1);

namespace Radix\Container\Argument\Literal;

use Radix\Container\Argument\LiteralArgument;
use Radix\Container\Exception\ContainerInvalidArgumentException;

class ArrayArgument extends LiteralArgument
{
    /**
     * ArrayArgument constructor.
     *
     * @param array $value Arrayvärde.
     * @throws ContainerInvalidArgumentException Om värdet inte är giltigt.
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
     * Validerar arrayen.
     *
     * @param  array  $value
     * @throws ContainerInvalidArgumentException
     */
    private function validateArray(array $value): void
    {
        // Exempel: Lägg till valideringslogik här om nödvändigt.
         if (empty($value)) {
             throw new ContainerInvalidArgumentException('Array cannot be empty.');
         }
    }

    /**
     * Lägger till ett värde till arrayen.
     *
     * @param mixed $value
     * @return array
     */
    public function addValue(mixed $value): array
    {
        $array = $this->getValue();
        $array[] = $value;
        return $array;
    }

    /**
     * Tar bort ett värde från arrayen om det finns.
     *
     * @param mixed $value
     * @return array
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
     * Sorterar arrayen efter en callback-funktion.
     *
     * @param callable|null $callback
     * @return array
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