<?php

declare(strict_types=1);

namespace Radix\Tests\Container\Argument\Literal;

use PHPUnit\Framework\TestCase;
use Radix\Container\Argument\Literal\ArrayArgument;
use Radix\Container\Exception\ContainerInvalidArgumentException;

class ArrayArgumentTest extends TestCase
{
    public function testConstructorRejectsEmptyArray(): void
    {
        $this->expectException(ContainerInvalidArgumentException::class);
        $this->expectExceptionMessage('Array cannot be empty.');

        new ArrayArgument([]);
    }

    public function testConstructorAcceptsNonEmptyArray(): void
    {
        $arg = new ArrayArgument(['foo' => 'bar']);
        $this->assertSame(['foo' => 'bar'], $arg->getValue());
    }

    public function testConstructorValidArray(): void
    {
        $arrayArgument = new ArrayArgument(['key1' => 'value1', 'key2' => 'value2']);
        $this->assertSame(['key1' => 'value1', 'key2' => 'value2'], $arrayArgument->getValue());
    }

    public function testAddValue(): void
    {
        $arrayArgument = new ArrayArgument([1, 2, 3]);
        $updatedArray = $arrayArgument->addValue(4);

        $this->assertEquals([1, 2, 3, 4], $updatedArray);
    }

    public function testRemoveValue(): void
    {
        $arrayArgument = new ArrayArgument([1, 2, 3]);
        $updatedArray = $arrayArgument->removeValue(2);

        $this->assertEquals([0 => 1, 2 => 3], $updatedArray);
    }

    public function testSortDefault(): void
    {
        $arrayArgument = new ArrayArgument([3, 1, 2]);
        $sortedArray = $arrayArgument->sort();

        $this->assertEquals([1, 2, 3], $sortedArray);
    }

    public function testSortWithCallback(): void
    {
        $arrayArgument = new ArrayArgument([3, 1, 2]);
        $sortedArray = $arrayArgument->sort(static function ($a, $b) {
            return $b <=> $a; // Omvänd ordning
        });

        $this->assertEquals([3, 2, 1], $sortedArray);
    }

    public function testLength(): void
    {
        $arrayArgument = new ArrayArgument([1, 2, 3, 4]);
        $this->assertEquals(4, $arrayArgument->length());
    }

    public function testEmptyArrayValidation(): void
    {
        $this->expectException(ContainerInvalidArgumentException::class);
        $this->expectExceptionMessage('Array cannot be empty.');

        new ArrayArgument([]);
    }
}
