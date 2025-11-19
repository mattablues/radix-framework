<?php

declare(strict_types=1);

namespace Radix\Container\Argument\Literal;

use Radix\Container\Argument\LiteralArgument;
use Radix\Container\Exception\ContainerInvalidArgumentException;

class StringArgument extends LiteralArgument
{
    /**
     * StringArgument constructor.
     *
     * @param mixed $value Strängens värde.
     * @throws ContainerInvalidArgumentException Om värdet inte är en giltig sträng.
     */
    public function __construct(mixed $value)
    {
        $this->validateString($value);

        parent::__construct($value, LiteralArgument::TYPE_STRING);
    }

    /**
     * Validerar att värdet är en giltig sträng.
     *
     * @param mixed $value
     * @return void
     * @throws ContainerInvalidArgumentException Om strängen inte godkänns.
     */
    private function validateString(mixed $value): void
    {
        if (!is_string($value) || trim($value) === '') {
            throw new ContainerInvalidArgumentException('String value cannot be empty.');
        }
    }

    /**
     * Ställer in värdet för strängargumentet.
     *
     * @param mixed $value
     * @return void
     * @throws ContainerInvalidArgumentException Om värdet inte godkänns som en giltig sträng.
     */
    public function setValue(mixed $value): void
    {
        $this->validateString($value);

        parent::setValue($value);
    }

    /**
     * Returnerar strängen i versaler.
     *
     * @return string
     */
    public function toUpperCase(): string
    {
        $value = $this->getValue();

        if (!is_string($value)) {
            // Borde inte hända p.g.a. validateString(), men skyddar runtime/statisk analys.
            throw new ContainerInvalidArgumentException('Underlying value is not a string.');
        }

        return strtoupper($value);
    }

    /**
     * Returnerar strängen i gemener.
     *
     * @return string
     */
    public function toLowerCase(): string
    {
        $value = $this->getValue();

        if (!is_string($value)) {
            throw new ContainerInvalidArgumentException('Underlying value is not a string.');
        }

        return strtolower($value);
    }

    /**
     * Returnerar antalet tecken i strängen.
     *
     * @return int
     */
    public function length(): int
    {
        $value = $this->getValue();

        if (!is_string($value)) {
            throw new ContainerInvalidArgumentException('Underlying value is not a string.');
        }

        return strlen($value);
    }
}
