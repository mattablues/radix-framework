<?php

declare(strict_types=1);

namespace Radix\Tests\Container\Argument\Literal;

use PHPUnit\Framework\TestCase;
use Radix\Container\Argument\Literal\StringArgument;
use Radix\Container\Exception\ContainerInvalidArgumentException;

class StringArgumentTest extends TestCase
{
    public function testConstructorValidString(): void
    {
        $stringArgument = new StringArgument('test value');

        $this->assertSame('test value', $stringArgument->getValue());
    }

    public function testConstructorEmptyStringThrowsException(): void
    {
        $this->expectException(ContainerInvalidArgumentException::class);
        $this->expectExceptionMessage('String value cannot be empty.');

        new StringArgument('');
    }

    public function testConstructorBlankStringThrowsException(): void
    {
        $this->expectException(ContainerInvalidArgumentException::class);
        $this->expectExceptionMessage('String value cannot be empty.');

        new StringArgument('   '); // Endast mellanslag
    }

    public function testToUpperCase(): void
    {
        $stringArgument = new StringArgument('test value');
        $uppercased = $stringArgument->toUpperCase();

        $this->assertSame('TEST VALUE', $uppercased);
    }

    public function testToLowerCase(): void
    {
        $stringArgument = new StringArgument('TEST Value');
        $lowercased = $stringArgument->toLowerCase();

        $this->assertSame('test value', $lowercased);
    }

    public function testSetValueValidString(): void
    {
        $stringArgument = new StringArgument('initial value');
        $stringArgument->setValue('updated value');

        $this->assertSame('updated value', $stringArgument->getValue());
    }

    public function testSetValueEmptyStringThrowsException(): void
    {
        $stringArgument = new StringArgument('valid value');

        $this->expectException(ContainerInvalidArgumentException::class);
        $this->expectExceptionMessage('String value cannot be empty.');

        $stringArgument->setValue(''); // Försök sätta tom sträng
    }
}
