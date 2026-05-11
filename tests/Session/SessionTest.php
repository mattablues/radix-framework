<?php

declare(strict_types=1);

namespace Radix\Tests\Session;

use PHPUnit\Framework\TestCase;
use Radix\Session\Session;
use ReflectionMethod;

final class SessionTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $serverBackup = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->serverBackup = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;

        parent::tearDown();
    }

    public function testHttpsRequestIsDetectedFromHttpsServerValueOn(): void
    {
        $_SERVER = ['HTTPS' => 'on'];

        self::assertTrue($this->invokeIsHttpsRequest());
    }

    public function testHttpsRequestIsDetectedFromHttpsServerValueOne(): void
    {
        $_SERVER = ['HTTPS' => '1'];

        self::assertTrue($this->invokeIsHttpsRequest());
    }

    public function testHttpsRequestIsDetectedFromRequestScheme(): void
    {
        $_SERVER = ['REQUEST_SCHEME' => 'https'];

        self::assertTrue($this->invokeIsHttpsRequest());
    }

    public function testHttpsRequestIsDetectedFromForwardedProto(): void
    {
        $_SERVER = ['HTTP_X_FORWARDED_PROTO' => 'https'];

        self::assertTrue($this->invokeIsHttpsRequest());
    }

    public function testHttpsRequestIsDetectedFromCommaSeparatedForwardedProto(): void
    {
        $_SERVER = ['HTTP_X_FORWARDED_PROTO' => 'http,https'];

        self::assertTrue($this->invokeIsHttpsRequest());
    }

    public function testHttpsRequestIsDetectedFromForwardedSsl(): void
    {
        $_SERVER = ['HTTP_X_FORWARDED_SSL' => 'on'];

        self::assertTrue($this->invokeIsHttpsRequest());
    }

    public function testHttpRequestIsNotDetectedAsHttps(): void
    {
        $_SERVER = [
            'HTTPS' => 'off',
            'REQUEST_SCHEME' => 'http',
            'HTTP_X_FORWARDED_PROTO' => 'http',
            'HTTP_X_FORWARDED_SSL' => 'off',
        ];

        self::assertFalse($this->invokeIsHttpsRequest());
    }

    public function testNormalizeSameSiteAcceptsValidValues(): void
    {
        $session = new Session();
        $method = new ReflectionMethod(Session::class, 'normalizeSameSite');
        $method->setAccessible(true);

        self::assertSame('Lax', $method->invoke($session, 'lax'));
        self::assertSame('Strict', $method->invoke($session, 'STRICT'));
        self::assertSame('None', $method->invoke($session, 'none'));
    }

    public function testNormalizeSameSiteFallsBackToLaxForInvalidValue(): void
    {
        $session = new Session();
        $method = new ReflectionMethod(Session::class, 'normalizeSameSite');
        $method->setAccessible(true);

        self::assertSame('Lax', $method->invoke($session, 'invalid'));
        self::assertSame('Lax', $method->invoke($session, null));
        self::assertSame('Lax', $method->invoke($session, true));
    }

    private function invokeIsHttpsRequest(): bool
    {
        $session = new Session();
        $method = new ReflectionMethod(Session::class, 'isHttpsRequest');
        $method->setAccessible(true);

        $result = $method->invoke($session);

        self::assertIsBool($result);

        return $result;
    }
}
