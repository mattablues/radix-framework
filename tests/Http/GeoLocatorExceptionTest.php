<?php

declare(strict_types=1);

namespace Radix\Tests\Http;

use PHPUnit\Framework\TestCase;
use Radix\Http\Exception\GeoLocatorException;
use Radix\Http\Exception\HttpException;

final class GeoLocatorExceptionTest extends TestCase
{
    public function testDefaultStatusCodeIs500(): void
    {
        $e = new GeoLocatorException();

        $this->assertInstanceOf(HttpException::class, $e);

        // HTTP-status ska komma från getStatusCode()
        $this->assertSame(500, $e->getStatusCode());

        // (valfritt) Visa att exception-koden fortfarande är 0
        // $this->assertSame(0, $e->getCode());
    }

    public function testCustomStatusCodeIsPreserved(): void
    {
        $e = new GeoLocatorException('custom', 418);

        // HTTP-status ska bevaras
        $this->assertSame(418, $e->getStatusCode());

        // (valfritt) Koden är fortfarande 0 eftersom vi inte använder den som status
        // $this->assertSame(0, $e->getCode());
    }
}
