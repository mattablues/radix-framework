<?php

declare(strict_types=1);

namespace Radix\Tests\Container\Argument\Literal;

use PHPUnit\Framework\TestCase;
use Radix\Container\Argument\Literal\FloatArgument;
use Radix\Container\Exception\ContainerInvalidArgumentException;

class FloatArgumentTest extends TestCase
{
    public function testConstructorValidFloat(): void
    {
        $floatArgument = new FloatArgument(10.5);
        $this->assertEquals(10.5, $floatArgument->getValue());
    }

    public function testConstructorInvalidValueThrowsException(): void
    {
        $this->expectException(ContainerInvalidArgumentException::class);
        $this->expectExceptionMessage('Value is not a valid float.');

        new FloatArgument('not a float'); // Ogiltig typ
    }

    public function testSetValueValidFloat(): void
    {
        $floatArgument = new FloatArgument(10.5);
        $floatArgument->setValue(20.75);
        $this->assertEquals(20.75, $floatArgument->getValue());
    }

    public function testSetValueInvalidFloatThrowsException(): void
    {
        $floatArgument = new FloatArgument(10.5);

        $this->expectException(ContainerInvalidArgumentException::class);
        $this->expectExceptionMessage('Value is not a valid float.');

        $floatArgument->setValue('invalid'); // Ogiltigt värde
    }

    public function testMultiply(): void
    {
        $floatArgument = new FloatArgument(5.5);
        $this->assertEquals(27.5, $floatArgument->multiply(5)); // 5.5 * 5 = 27.5
    }

    public function testDivide(): void
    {
        $floatArgument = new FloatArgument(10.0);
        $this->assertEquals(2.5, $floatArgument->divide(4)); // 10.0 / 4 = 2.5
    }

    public function testDivideByZeroThrowsException(): void
    {
        $floatArgument = new FloatArgument(10.0);

        $this->expectException(ContainerInvalidArgumentException::class);
        $this->expectExceptionMessage('Division by zero is not allowed.');

        $floatArgument->divide(0.0); // Försöker dividera med 0
    }

    public function testRound(): void
    {
        $floatArgument = new FloatArgument(10.5555);
        $this->assertEquals(10.56, $floatArgument->round(2)); // Precision: 2 decimaler
        $this->assertEquals(11.0, $floatArgument->round(0));  // Precision: 0 decimaler
    }
}
