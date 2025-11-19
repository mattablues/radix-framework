<?php

declare(strict_types=1);

namespace Radix\Container\Argument\Literal;

use Radix\Container\Argument\LiteralArgument;
use Radix\Container\Exception\ContainerInvalidArgumentException;

class BooleanArgument extends LiteralArgument
{
    /**
     * BooleanArgument constructor.
     *
     * @param mixed $value Booleskt värde.
     * @throws ContainerInvalidArgumentException Om värdet inte uppfyller valideringskrav.
     */
    public function __construct(mixed $value)
    {
        if (!is_bool($value)) {
            throw new ContainerInvalidArgumentException('Value is not a valid boolean.');
        }

        parent::__construct($value, LiteralArgument::TYPE_BOOL);
    }

    /**
     * Vänder boolean-värdet.
     *
     * @return bool
     */
    public function toggle(): bool
    {
        return !$this->getValue();
    }

    /**
     * Returnerar booleskt värde som text.
     *
     * @return string
     */
    public function toString(): string
    {
        return $this->getValue() ? 'true' : 'false';
    }
}
