<?php

declare(strict_types=1);

namespace Radix\Tests\Container\Exception;

use PHPUnit\Framework\TestCase;
use Radix\Container\Exception\ContainerDependencyInjectionException;

class ContainerDependencyInjectionExceptionTest extends TestCase
{
    public function testExceptionMessage(): void
    {
        $message = "Dependency injection failed!";
        $exception = new ContainerDependencyInjectionException($message);

        $this->assertInstanceOf(ContainerDependencyInjectionException::class, $exception);
        $this->assertSame($message, $exception->getMessage());
    }

    public function testDefaultExceptionCode(): void
    {
        $exception = new ContainerDependencyInjectionException("Test Exception");

        $this->assertSame(0, $exception->getCode()); // Standardkod för Exception är 0
    }

    public function testExceptionWithCustomCode(): void
    {
        $message = "Injection error";
        $code = 500;
        $exception = new ContainerDependencyInjectionException($message, $code);

        $this->assertSame($message, $exception->getMessage());
        $this->assertSame($code, $exception->getCode());
    }
}
