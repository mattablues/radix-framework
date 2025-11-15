<?php

declare(strict_types=1);

namespace Radix\Container\Argument\Literal;

use Radix\Container\Argument\LiteralArgument;
use Radix\Container\Exception\ContainerInvalidArgumentException;

class ObjectArgument extends LiteralArgument
{
    /**
     * ObjectArgument constructor.
     *
     * @param object $value Objektvärde.
     * @throws ContainerInvalidArgumentException Om värdet inte uppfyller valideringskrav.
     */
    public function __construct(object $value)
    {
        $this->validateObject($value);
        parent::__construct($value, LiteralArgument::TYPE_OBJECT);
    }

    /**
     * Validerar objektet.
     *
     * @param object $value
     */
    private function validateObject(object $value): void
    {
        // Ingen kontroll behövs: object-typ garanterar ej-null och truthy.
        // Lägg specifik validering här vid behov (t.ex. klass-krav).
    }

    public function isInstanceOf(string $className): bool
    {
        return $this->getValue() instanceof $className;
    }

    public function toJson(): string
    {
        $json = json_encode($this->getValue(), JSON_THROW_ON_ERROR);
        if ($json === false) {
            throw new ContainerInvalidArgumentException('Failed to convert object to JSON.');
        }
        return $json;
    }

    /**
     * Anropa en metod på det underliggande objektet.
     *
     * @param array<int, mixed> $arguments
     */
    public function callMethod(string $method, array $arguments = []): mixed
    {
        $object = $this->getValue();

        if (!is_object($object)) {
            // Borde inte hända eftersom konstruktorn kräver object,
            // men skyddar både runtime och statisk analys.
            throw new ContainerInvalidArgumentException('Underlying value is not an object.');
        }

        $callable = [$object, $method];

        if (!is_callable($callable)) {
            throw new ContainerInvalidArgumentException(
                sprintf('Method "%s" does not exist on the given object.', $method)
            );
        }

        /** @var callable $callable */
        return call_user_func_array($callable, $arguments);
    }
}