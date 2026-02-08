<?php

declare(strict_types=1);

namespace Radix\Tests\Container\Argument\Literal;

use PHPUnit\Framework\TestCase;
use Radix\Container\Argument\Literal\IntegerArgument;
use Radix\Container\Exception\ContainerInvalidArgumentException;

class IntArgumentTest extends TestCase
{
    public function testConstructorValidInteger(): void
    {
        $intArgument = new IntegerArgument(10);
        $this->assertEquals(10, $intArgument->getValue());
    }

    public function testConstructorInvalidValueThrowsException(): void
    {
        $this->expectException(ContainerInvalidArgumentException::class);
        $this->expectExceptionMessage('Value is not a valid integer.');

        new IntegerArgument('not an integer'); // Ogiltig typ
    }

    public function testSetValueValidInteger(): void
    {
        $intArgument = new IntegerArgument(10);
        $intArgument->setValue(20);
        $this->assertEquals(20, $intArgument->getValue());
    }

    public function testSetValueInvalidValueThrowsException(): void
    {
        $intArgument = new IntegerArgument(10);

        $this->expectException(ContainerInvalidArgumentException::class);
        $this->expectExceptionMessage('Value is not a valid integer.');

        $intArgument->setValue('invalid'); // Ogiltigt värde
    }

    public function testMultiply(): void
    {
        $intArgument = new IntegerArgument(5);
        $this->assertEquals(25, $intArgument->multiply(5)); // 5 * 5 = 25
    }

    public function testIsEven(): void
    {
        $intArgument = new IntegerArgument(4);
        $this->assertTrue($intArgument->isEven());

        $intArgument = new IntegerArgument(3);
        $this->assertFalse($intArgument->isEven());
    }

    public function testIsOdd(): void
    {
        $intArgument = new IntegerArgument(3);
        $this->assertTrue($intArgument->isOdd());

        $intArgument = new IntegerArgument(4);
        $this->assertFalse($intArgument->isOdd());
    }
}
