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
    public function invoke(...$args): mixed
    {
        $callable = $this->getValue();

        if (!is_callable($callable)) {
            // Ska inte kunna hända eftersom konstruktorn validerar, men skyddar runtime/statisk analys.
            throw new ContainerInvalidArgumentException('Underlying value is not a callable.');
        }

        /** @var callable $callable */
        return call_user_func($callable, ...$args);
    }

    /**
     * Kontrollerar om callable är en metod på en klass.
     *
     * @return bool
     */
    public function isMethod(): bool
    {
        $value = $this->getValue();

        return is_array($value)
            && count($value) === 2
            && is_object($value[0])
            && is_string($value[1]);
    }

    /**
     * Returnerar en beskrivning av callable.
     *
     * @return string
     */
    public function describe(): string
    {
        $value = $this->getValue();

        if (is_string($value)) {
            // $value är nu garanterat string för sprintf
            return sprintf('Callable function: %s', $value);
        }

        if (is_array($value)) {
            // Förvänta [object|string, string]
            if (count($value) === 2 && is_string($value[1])) {
                [$object, $method] = $value;

                if (is_object($object)) {
                    $className = get_class($object);
                } elseif (is_string($object)) {
                    $className = $object;
                } else {
                    // Ogiltig typ i arrayen – fall tillbaka
                    return 'Callable: unknown type';
                }

                return sprintf('Callable method: %s::%s', $className, $method);
            }

            return 'Callable: unknown type';
        }

        if ($value instanceof \Closure) {
            return 'Callable: anonymous function';
        }

        return 'Callable: unknown type';
    }
}