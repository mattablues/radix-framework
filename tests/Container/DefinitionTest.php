<?php

declare(strict_types=1);

namespace Radix\Tests\Container;

use InvalidArgumentException;
use OutOfBoundsException;
use PHPUnit\Framework\TestCase;
use Radix\Container\Definition;
use stdClass;

class DefinitionTest extends TestCase
{
    public function testAddArgumentValidValue(): void
    {
        $definition = new Definition('SomeClass');
        $result = $definition->addArgument('value');

        $this->assertSame($definition, $result); // Verifiera att addArgument returnerar samma instans
    }



    public function testAddArgumentNullThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Argument value cannot be null.');

        $definition = new Definition('SomeClass');
        $definition->addArgument(null);
    }

    public function testAddMultipleArguments(): void
    {
        $definition = new Definition('SomeClass');
        $definition->addArgument('arg1');
        $definition->addArgument('arg2');

        $this->assertSame(['arg1', 'arg2'], $definition->getArguments());
    }

    public function testGetArgumentByIndex(): void
    {
        $definition = new Definition('SomeClass');
        $definition->addArgument('arg1');
        $definition->addArgument('arg2');

        $this->assertSame('arg1', $definition->getArgument(0));
        $this->assertSame('arg2', $definition->getArgument(1));
    }

    public function testGetArgumentInvalidIndexThrowsException(): void
    {
        $this->expectException(OutOfBoundsException::class);
        $this->expectExceptionMessage('Argument at index "10" does not exist.');

        $definition = new Definition('SomeClass');
        $definition->getArgument(10);
    }

    public function testAddMethodCallCastsArgumentsToArray(): void
    {
        $def = new Definition(stdClass::class);

        $def->addMethodCall('setFoo', 'bar');

        $calls = $def->getMethodCalls();

        $this->assertCount(1, $calls);
        $this->assertSame('setFoo', $calls[0][0]);
        $this->assertSame(['bar'], $calls[0][1]);
    }

    public function testSetConcreteRejectsInvalidType(): void
    {
        $def = new Definition(stdClass::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Concrete must be a class name, an object, or a callable.');

        $def->setConcrete(123);
    }
}
