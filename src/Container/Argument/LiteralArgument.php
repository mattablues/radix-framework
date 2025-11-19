<?php

declare(strict_types=1);

namespace Radix\Container\Argument;

use Radix\Container\Exception\ContainerInvalidArgumentException;

class LiteralArgument implements LiteralArgumentInterface
{
    public const string TYPE_ARRAY = 'array';
    public const string TYPE_BOOL = 'boolean';
    public const string TYPE_CALLABLE = 'callable';
    public const string TYPE_FLOAT = 'double'; // Alternativ: 'float'
    public const string TYPE_INT = 'integer';
    public const string TYPE_OBJECT = 'object';
    public const string TYPE_STRING = 'string';

    protected mixed $value;
    protected ?string $type;

    /**
     * Konstruktor med typvalidering.
     *
     * @param mixed $value Värdet av argumentet.
     * @param string|null $type Typen för värdet (valfritt).
     */
    public function __construct(mixed $value, string $type = null)
    {
        $this->type = $type;

        if (
            null === $type
            || ($type === self::TYPE_CALLABLE && is_callable($value))
            || ($type === self::TYPE_OBJECT && is_object($value))
            || gettype($value) === $type
        ) {
            $this->value = $value;
        } else {
            throw new ContainerInvalidArgumentException('Incorrect type for value.');
        }
    }

    /**
     * Hämtar argumentets värde.
     *
     * @return mixed
     */
    public function getValue(): mixed
    {
        return $this->value;
    }

    /**
     * Sätter argumentets värde.
     *
     * @param mixed $value
     * @return void
     *
     * @throws ContainerInvalidArgumentException Om typen på värdet är inkorrekt.
     */
    public function setValue(mixed $value): void
    {
        if (
            ($this->type === self::TYPE_CALLABLE && !is_callable($value))
            || ($this->type === self::TYPE_OBJECT && !is_object($value))
            || (gettype($value) !== $this->type && $this->type !== null)
        ) {
            throw new ContainerInvalidArgumentException('Incorrect type for value.');
        }

        $this->value = $value;
    }

    /**
     * Hämtar argumentets typ.
     *
     * @return string|null
     */
    public function getType(): ?string
    {
        return $this->type;
    }
}
