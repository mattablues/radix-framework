<?php

declare(strict_types=1);

namespace Radix\Tests\Container;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Radix\Container\Parameter;

final class ParameterTest extends TestCase
{
    public function testSetParametersRejectsNonStringOrEmptyKeys(): void
    {
        $parameter = new Parameter();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Parameter keys must be non-empty strings.');

        $parameter->setParameters([
            '' => 'empty',   // tom sträng ska inte tillåtas
        ]);
    }

    public function testAddParametersRejectsNonStringOrEmptyKeys(): void
    {
        $parameter = new Parameter();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Parameter keys must be non-empty strings.');

        $parameter->addParameters([
            '' => 'empty',   // tom sträng
        ]);
    }
}
