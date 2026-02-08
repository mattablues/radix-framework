<?php

declare(strict_types=1);

namespace Radix\Tests\Container\Argument\Literal;

use DateTime;
use PHPUnit\Framework\TestCase;
use Radix\Container\Argument\Literal\ObjectArgument;
use Radix\Container\Exception\ContainerInvalidArgumentException;
use stdClass;

class ObjectArgumentTest extends TestCase
{
    public function testConstructorValidObject(): void
    {
        $object = new ObjectArgument(new stdClass());
        $this->assertInstanceOf(stdClass::class, $object->getValue());
    }

    public function testIsInstanceOf(): void
    {
        $objectArgument = new ObjectArgument(new DateTime());
        $this->assertTrue($objectArgument->isInstanceOf(DateTime::class));
        $this->assertFalse($objectArgument->isInstanceOf(stdClass::class));
    }

    public function testToJson(): void
    {
        $objectArgument = new ObjectArgument((object) ['key' => 'value']);
        $result = $objectArgument->toJson();

        $this->assertSame('{"key":"value"}', $result);
    }

    public function testCallMethod(): void
    {
        $object = new class {
            public function greet(string $name): string
            {
                return "Hello, $name!";
            }
        };

        $objectArgument = new ObjectArgument($object);
        $result = $objectArgument->callMethod('greet', ['World']);

        $this->assertSame('Hello, World!', $result);
    }

    public function testCallUndefinedMethodThrowsException(): void
    {
        $object = new stdClass();
        $objectArgument = new ObjectArgument($object);

        $this->expectException(ContainerInvalidArgumentException::class);
        $this->expectExceptionMessage('Method "undefinedMethod" does not exist on the given object.');

        $objectArgument->callMethod('undefinedMethod');
    }
}
