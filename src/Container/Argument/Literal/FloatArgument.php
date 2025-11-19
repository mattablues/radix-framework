<?php

declare(strict_types=1);

namespace Radix\Container\Argument\Literal;

use Radix\Container\Argument\LiteralArgument;
use Radix\Container\Exception\ContainerInvalidArgumentException;

class FloatArgument extends LiteralArgument
{
    /**
     * FloatArgument constructor.
     *
     * @param mixed $value Flyttalets värde.
     * @throws ContainerInvalidArgumentException Om värdet inte är ett giltigt flyttal.
     */
    public function __construct(mixed $value)
    {
        if (!is_float($value)) {
            throw new ContainerInvalidArgumentException('Value is not a valid float.');
        }

        parent::__construct($value, LiteralArgument::TYPE_FLOAT);
    }

    /**
     * Sätter värdet för flyttalet efter validering.
     *
     * @param mixed $value
     * @return void
     * @throws ContainerInvalidArgumentException Om värdet inte är ett giltigt flyttal.
     */
    public function setValue(mixed $value): void
    {
        if (!is_float($value)) {
            throw new ContainerInvalidArgumentException('Value is not a valid float.');
        }

        parent::setValue($value);
    }

    /**
     * Multiplicerar flyttalet med en faktor.
     *
     * @param float $factor
     * @return float
     */
    public function multiply(float $factor): float
    {
        $current = $this->getValue();

        if (!is_float($current)) {
            throw new ContainerInvalidArgumentException('Underlying value is not a float.');
        }

        return $current * $factor;
    }

    /**
     * Dividerar flyttalet med ett värde.
     *
     * @param float $divisor
     * @return float
     * @throws ContainerInvalidArgumentException Om divisorn är 0.
     */
    public function divide(float $divisor): float
    {
        if ($divisor === 0.0) {
            throw new ContainerInvalidArgumentException('Division by zero is not allowed.');
        }

        $current = $this->getValue();

        if (!is_float($current)) {
            throw new ContainerInvalidArgumentException('Underlying value is not a float.');
        }

        return $current / $divisor;
    }

    /**
     * Hämtar värdet avrundat till ett visst antal decimaler.
     *
     * @param int $precision
     * @return float
     */
    public function round(int $precision): float
    {
        $current = $this->getValue();

        if (!is_float($current)) {
            throw new ContainerInvalidArgumentException('Underlying value is not a float.');
        }

        return round($current, $precision);
    }
}
