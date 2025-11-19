<?php

declare(strict_types=1);

namespace Radix\Container\Argument\Literal;

use Radix\Container\Argument\LiteralArgument;
use Radix\Container\Exception\ContainerInvalidArgumentException;

class IntegerArgument extends LiteralArgument
{
    /**
     * IntegerArgument constructor.
     *
     * @param mixed $value Heltalsvärde.
     * @throws ContainerInvalidArgumentException Om värdet inte uppfyller kraven.
     */
    public function __construct(mixed $value)
    {
        if (!is_int($value)) {
            throw new ContainerInvalidArgumentException('Value is not a valid integer.');
        }

        parent::__construct($value, LiteralArgument::TYPE_INT);
    }

    /**
     * Sätter argumentets värde efter validering.
     *
     * @param mixed $value
     * @return void
     * @throws ContainerInvalidArgumentException Om värdet inte är ett giltigt heltal.
     */
    public function setValue(mixed $value): void
    {
        if (!is_int($value)) {
            throw new ContainerInvalidArgumentException('Value is not a valid integer.');
        }

        parent::setValue($value); // Anropa basklassens metod för att sätta värdet
    }

    /**
     * Lägg till ett värde till heltalet.
     *
     * @param int $value
     * @return int
     */
    public function add(int $value): int
    {
        $current = $this->getValue();

        if (!is_int($current)) {
            // Ska inte kunna hända om konstruktorn och setValue validerar korrekt.
            throw new ContainerInvalidArgumentException('Underlying value is not an integer.');
        }

        return $current + $value;
    }

    /**
     * Subtrahera ett värde från heltalet.
     *
     * @param int $value
     * @return int
     */
    public function subtract(int $value): int
    {
        $current = $this->getValue();

        if (!is_int($current)) {
            throw new ContainerInvalidArgumentException('Underlying value is not an integer.');
        }

        return $current - $value;
    }

    /**
     * Kontrollera om heltalet är jämnt.
     *
     * @return bool
     */
    public function isEven(): bool
    {
        $current = $this->getValue();

        if (!is_int($current)) {
            throw new ContainerInvalidArgumentException('Underlying value is not an integer.');
        }

        return $current % 2 === 0;
    }

    /**
     * Kontrollera om heltalet är udda.
     *
     * @return bool
     */
    public function isOdd(): bool
    {
        return !$this->isEven();
    }

    /**
     * Multiplicera heltalet med ett annat heltal.
     *
     * @param int $multiplier
     * @return int
     */
    public function multiply(int $multiplier): int
    {
        $current = $this->getValue();

        if (!is_int($current)) {
            throw new ContainerInvalidArgumentException('Underlying value is not an integer.');
        }

        return $current * $multiplier;
    }
}
