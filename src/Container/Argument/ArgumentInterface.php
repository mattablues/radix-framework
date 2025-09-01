<?php

declare(strict_types=1);

namespace Radix\Container\Argument;

interface ArgumentInterface
{
    /**
     * Hämtar argumentets värde.
     *
     * @return mixed
     */
    public function getValue(): mixed;

    /**
     * Sätter värdet för argumentet.
     *
     * @param mixed $value
     *
     * @return void
     */
    public function setValue(mixed $value): void;
}
