<?php

declare(strict_types=1);

namespace Radix\Tests\Http\Exception;

use PHPUnit\Framework\TestCase;
use Radix\Http\Exception\PageNotFoundException;

final class PageNotFoundExceptionTest extends TestCase
{
    public function testHasHttpStatusCode404ByDefault(): void
    {
        $e = new PageNotFoundException();

        $this->assertSame(
            404,
            $e->getStatusCode(),
            'PageNotFoundException ska ha HTTP-statuskod 404 som default.'
        );

        // (valfritt) dokumentera att Exception-koden inte används här
        $this->assertSame(0, $e->getCode());
    }

    public function testKeepsCustomMessage(): void
    {
        $e = new PageNotFoundException('Custom message');
        $this->assertSame('Custom message', $e->getMessage());
    }
}
