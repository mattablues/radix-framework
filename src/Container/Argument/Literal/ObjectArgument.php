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
     * @throws ContainerInvalidArgumentException Om objektet inte är giltigt.
     */
    private function validateObject(object $value): void
    {
        // Om ingen specifik validering behövs, lämna denna metod tom.
        // Alternativt anpassa valideringslogiken utifrån dina behov.

        // En kontroll kan t.ex. bara säkerställa att objektet inte är null:
        if (!$value) {
            throw new ContainerInvalidArgumentException('Object cannot be null.');
        }
    }


    /**
     * Kontrollerar om objektet är en instans av en viss klass.
     *
     * @param string $className
     * @return bool
     */
    public function isInstanceOf(string $className): bool
    {
        return $this->getValue() instanceof $className;
    }

    /**
     * Returnerar objektet som en JSON-sträng (om möjligt).
     *
     * @return string
     * @throws ContainerInvalidArgumentException Om objektet inte kan konverteras till JSON.
     */
    public function toJson(): string
    {
        $json = json_encode($this->getValue(), JSON_THROW_ON_ERROR);

        if ($json === false) {
            throw new ContainerInvalidArgumentException('Failed to convert object to JSON.');
        }

        return $json;
    }

    /**
     * Anropar en metod på objektet dynamiskt.
     *
     * @param string $method
     * @param array $arguments
     * @return mixed
     * @throws ContainerInvalidArgumentException Om metoden inte finns eller inte är tillgänglig.
     */
    public function callMethod(string $method, array $arguments = [])
    {
        $object = $this->getValue();

        if (!method_exists($object, $method)) {
            throw new ContainerInvalidArgumentException(
                sprintf('Method "%s" does not exist on the given object.', $method)
            );
        }

        return call_user_func_array([$object, $method], $arguments);
    }
}