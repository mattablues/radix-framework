<?php

declare(strict_types=1);

namespace Radix\Tests\Container\Argument\Literal;

use PHPUnit\Framework\TestCase;
use Radix\Container\Argument\Literal\BooleanArgument;
use Radix\Container\Exception\ContainerInvalidArgumentException;

class BoolArgumentTest extends TestCase
{
    public function testConstructorValidBoolean(): void
    {
        $booleanArgument = new BooleanArgument(true); // Testa med sant
        $this->assertTrue($booleanArgument->getValue());

        $booleanArgument = new BooleanArgument(false); // Testa med falskt
        $this->assertFalse($booleanArgument->getValue());
    }

    public function testConstructorInvalidValueThrowsException(): void
    {
        $this->expectException(ContainerInvalidArgumentException::class);
        $this->expectExceptionMessage('Value is not a valid boolean.'); // Ändrat till matchande meddelande

        new BooleanArgument('not a boolean'); // Skickar en ogiltig typ
    }

    public function testSetValueValidBoolean(): void
    {
        $booleanArgument = new BooleanArgument(true);
        $booleanArgument->setValue(false); // Uppdatera värdet till falskt
        $this->assertFalse($booleanArgument->getValue());

        $booleanArgument->setValue(true); // Uppdatera tillbaka till sant
        $this->assertTrue($booleanArgument->getValue());
    }

    public function testSetValueInvalidValueThrowsException(): void
    {
        $booleanArgument = new BooleanArgument(true);

        $this->expectException(ContainerInvalidArgumentException::class);
        $this->expectExceptionMessage('Incorrect type for value.');

        $booleanArgument->setValue('invalid'); // Försök att sätta ett ogiltigt värde
    }
}
