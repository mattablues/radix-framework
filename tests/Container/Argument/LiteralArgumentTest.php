<?php

declare(strict_types=1);

namespace Radix\Tests\Container\Argument;

use Closure;
use PHPUnit\Framework\TestCase;
use Radix\Container\Argument\Literal\CallableArgument;
use Radix\Container\Argument\LiteralArgument;
use Radix\Container\Exception\ContainerInvalidArgumentException;
use stdClass;

final class LiteralArgumentTest extends TestCase
{
    public function testConstructorAcceptsAnyValueWhenTypeIsNull(): void
    {
        $arg1 = new LiteralArgument('string');
        $arg2 = new LiteralArgument(123);
        $arg3 = new LiteralArgument(function (): void {});
        $arg4 = new LiteralArgument(new stdClass());

        $this->assertSame('string', $arg1->getValue());
        $this->assertSame(123, $arg2->getValue());
        $this->assertInstanceOf(Closure::class, $arg3->getValue());
        $this->assertInstanceOf(stdClass::class, $arg4->getValue());
    }

    public function testSetValueRejectsObjectWhenTypeIsScalar(): void
    {
        $arg = new LiteralArgument(123, LiteralArgument::TYPE_INT);

        $this->expectException(ContainerInvalidArgumentException::class);

        // Typen är "integer", men värdet är ett objekt → ska inte godkännas.
        $arg->setValue(new stdClass());
    }

    public function testIsMethodReturnsFalseForCallableStringAndDoesNotThrow(): void
    {
        $arg = new CallableArgument('strlen');

        $this->assertFalse(
            $arg->isMethod(),
            'CallableArgument::isMethod() ska returnera false för callable-strängar och får inte kasta TypeError.'
        );
    }

    public function testSetValueAcceptsCallableWhenTypeCallable(): void
    {
        $arg = new LiteralArgument(static fn(): int => 1, LiteralArgument::TYPE_CALLABLE);

        $newCallable = static fn(): int => 2;
        $arg->setValue($newCallable);

        $this->assertSame($newCallable, $arg->getValue());
        $this->assertTrue(is_callable($arg->getValue()));
    }

    public function testSetValueAcceptsCallableArrayWhenTypeCallable(): void
    {
        $obj = new class {
            public function run(): int
            {
                return 123;
            }
        };

        $arg = new LiteralArgument(static fn(): int => 1, LiteralArgument::TYPE_CALLABLE);

        $callableArray = [$obj, 'run'];
        $arg->setValue($callableArray);

        $this->assertSame($callableArray, $arg->getValue());
        $this->assertTrue(is_callable($arg->getValue()));
    }

    public function testConstructorRejectsWrongScalarType(): void
    {
        $this->expectException(ContainerInvalidArgumentException::class);
        new LiteralArgument('not-int', LiteralArgument::TYPE_INT);
    }

    public function testConstructorAcceptsMatchingScalarType(): void
    {
        $arg = new LiteralArgument(123, LiteralArgument::TYPE_INT);
        $this->assertSame(123, $arg->getValue());
    }

    public function testConstructorAcceptsCallableWhenTypeCallable(): void
    {
        $callable = fn(): int => 1;

        $arg = new LiteralArgument($callable, LiteralArgument::TYPE_CALLABLE);

        $this->assertSame($callable, $arg->getValue());
        $this->assertTrue(is_callable($arg->getValue()));
    }

    public function testConstructorRejectsNonCallableWhenTypeCallable(): void
    {
        $this->expectException(ContainerInvalidArgumentException::class);
        new LiteralArgument('not-callable', LiteralArgument::TYPE_CALLABLE);
    }

    public function testConstructorAcceptsObjectWhenTypeObject(): void
    {
        $obj = new stdClass();
        $arg = new LiteralArgument($obj, LiteralArgument::TYPE_OBJECT);

        $this->assertSame($obj, $arg->getValue());
    }

    public function testConstructorRejectsNonObjectWhenTypeObject(): void
    {
        $this->expectException(ContainerInvalidArgumentException::class);
        new LiteralArgument('not-object', LiteralArgument::TYPE_OBJECT);
    }

    public function testSetValueRejectsNonCallableWhenTypeCallable(): void
    {
        $arg = new LiteralArgument(fn() => null, LiteralArgument::TYPE_CALLABLE);

        $this->expectException(ContainerInvalidArgumentException::class);
        $arg->setValue('not-callable');
    }

    public function testSetValueRejectsNonObjectWhenTypeObject(): void
    {
        $arg = new LiteralArgument(new stdClass(), LiteralArgument::TYPE_OBJECT);

        $this->expectException(ContainerInvalidArgumentException::class);
        $arg->setValue('not-object');
    }

    public function testSetValueRejectsWrongScalarType(): void
    {
        $arg = new LiteralArgument(123, LiteralArgument::TYPE_INT);

        $this->expectException(ContainerInvalidArgumentException::class);
        $arg->setValue('still-not-int');
    }

    public function testSetValueAcceptsCorrectTypes(): void
    {
        $obj = new stdClass();
        $argObject = new LiteralArgument($obj, LiteralArgument::TYPE_OBJECT);
        $newObj = new stdClass();
        $argObject->setValue($newObj);
        $this->assertSame($newObj, $argObject->getValue());

        $argInt = new LiteralArgument(1, LiteralArgument::TYPE_INT);
        $argInt->setValue(2);
        $this->assertSame(2, $argInt->getValue());
    }

    public function testConstructorRejectsObjectWhenTypeIsScalar(): void
    {
        $this->expectException(ContainerInvalidArgumentException::class);

        // Typen är "integer", men värdet är ett objekt → ska inte godkännas.
        new LiteralArgument(new stdClass(), LiteralArgument::TYPE_INT);
    }

    public function testSetValueAcceptsAnyTypeWhenTypeIsNull(): void
    {
        // type=null eftersom vi inte anger typ i konstruktorn
        $arg = new LiteralArgument('initial');

        // Ska vara tillåtet att sätta ett objekt när typen är null
        $obj = new stdClass();
        $arg->setValue($obj);

        $this->assertSame($obj, $arg->getValue());
    }
}
