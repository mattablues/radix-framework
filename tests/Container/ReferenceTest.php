<?php

declare(strict_types=1);

namespace Radix\Tests\Container;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Radix\Container\Reference;

final class ReferenceTest extends TestCase
{
    public function testSetIdAcceptsNonEmptyString(): void
    {
        $ref = new Reference('initial');
        $ref->setId('new-id');

        $this->assertSame('new-id', $ref->getId());
    }

    public function testSetIdRejectsEmptyString(): void
    {
        $ref = new Reference('initial');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Reference ID must be a non-empty string.');

        $ref->setId('');
    }
}
