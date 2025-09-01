<?php

declare(strict_types=1);

namespace Radix\Container\Argument\Literal;

use Radix\Container\Argument\LiteralArgument;
use Radix\Container\Exception\ContainerInvalidArgumentException;

class CallableArgument extends LiteralArgument
{
    /**
     * CallableArgument constructor.
     *
     * @param mixed $value Ett anropbart värde.
     * @throws ContainerInvalidArgumentException Om värdet inte är ett giltigt callable.
     */
    public function __construct(mixed $value)
    {
        $this->validateCallable($value);
        parent::__construct($value, LiteralArgument::TYPE_CALLABLE);
    }

    /**
     * Validerar att värdet är callable.
     *
     * @param mixed $value
     * @throws ContainerInvalidArgumentException Om värdet inte är giltigt.
     */
    private function validateCallable(mixed $value): void
    {
        if (!is_callable($value)) {
            throw new ContainerInvalidArgumentException('Value is not a valid callable.');
        }
    }

    /**
     * Anropar callable med de angivna argumenten.
     *
     * @param mixed ...$args Argument som skickas till callable.
     * @return mixed Resultatet av anropet.
     */
    public function invoke(...$args)
    {
        return call_user_func($this->getValue(), ...$args);
    }

    /**
     * Kontrollerar om callable är en metod på en klass.
     *
     * @return bool
     */
    public function isMethod(): bool
    {
        return is_array($this->getValue()) && count($this->getValue()) === 2 && is_object($this->getValue()[0]) && is_string($this->getValue()[1]);
    }

    /**
     * Returnerar en beskrivning av callable.
     *
     * @return string
     */
    public function describe(): string
    {
        if (is_string($this->getValue())) {
            return sprintf('Callable function: %s', $this->getValue());
        }

        if (is_array($this->getValue())) {
            [$object, $method] = $this->getValue();
            return sprintf('Callable method: %s::%s', is_object($object) ? get_class($object) : $object, $method);
        }

        if ($this->getValue() instanceof \Closure) {
            return 'Callable: anonymous function';
        }

        return 'Callable: unknown type';
    }
}