<?php

declare(strict_types=1);

namespace Radix\Tests\Http;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Radix\Http\RedirectResponse;

final class RedirectResponseTest extends TestCase
{
    public function testItDefaultsToTemporaryRedirect(): void
    {
        $response = new RedirectResponse('/login');

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(['Location' => '/login'], $response->getHeaders());
        $this->assertSame(['/login'], $response->header('Location'));
    }

    public function testItCanUsePermanentRedirect(): void
    {
        $response = new RedirectResponse('/new-url', 301);

        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame(['Location' => '/new-url'], $response->getHeaders());
        $this->assertSame(['/new-url'], $response->header('Location'));
    }

    public function testItAcceptsRedirectBoundaryStatusCodes(): void
    {
        $lowest = new RedirectResponse('/lowest', 300);
        $highest = new RedirectResponse('/highest', 399);

        $this->assertSame(300, $lowest->getStatusCode());
        $this->assertSame(['Location' => '/lowest'], $lowest->getHeaders());

        $this->assertSame(399, $highest->getStatusCode());
        $this->assertSame(['Location' => '/highest'], $highest->getHeaders());
    }

    public function testItRejectsNonRedirectStatusCode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Redirect status code must be between 300 and 399.');

        new RedirectResponse('/nope', 200);
    }

}
