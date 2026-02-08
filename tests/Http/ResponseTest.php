<?php

declare(strict_types=1);

namespace Radix\Tests\Http;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Radix\Http\Response;

final class ResponseTest extends TestCase
{
    public function testSetStatusCodeFluentlySetsAndReturnsResponse(): void
    {
        $response = new Response();

        $result = $response->setStatusCode(404);

        $this->assertSame($response, $result);
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testSetBodyStoresBodyAndGetBodyReturnsIt(): void
    {
        $response = new Response();

        $response->setBody('Hello world');

        $this->assertSame('Hello world', $response->getBody());
        // body()-alias om du använder den någonstans
        $this->assertSame('Hello world', $response->body());
    }

    public function testSetHeaderAcceptsScalarAndStoresAsString(): void
    {
        $response = new Response();

        // int ska sparas som sträng
        $response->setHeader('X-Int', 123);
        // bool ska sparas som sträng
        $response->setHeader('X-Bool', true);

        $headers = $response->getHeaders();

        $this->assertSame('123', $headers['X-Int'] ?? null);
        $this->assertSame('1', $headers['X-Bool'] ?? null);

        // headers() ska returnera samma array av strängar
        $this->assertSame($headers, $response->headers());
    }

    public function testSetHeaderThrowsOnNonScalarValue(): void
    {
        $response = new Response();

        $this->expectException(InvalidArgumentException::class);
        // Exakt felmeddelande från originalkoden för en array:
        $this->expectExceptionMessage('Header value must be a scalar, array given.');

        /** @psalm-suppress InvalidArgument  vi testar feltypen avsiktligt */
        /** @phpstan-ignore-next-line */
        $response->setHeader('X-Array', ['not', 'scalar']);
    }

    public function testHeaderReturnsListOfValuesOrEmptyArray(): void
    {
        $response = new Response();
        $response->setHeader('X-Test', 'value');

        $existing = $response->header('X-Test');
        $missing  = $response->header('X-Missing');

        $this->assertSame(['value'], $existing);
        $this->assertSame([], $missing);
    }
}
