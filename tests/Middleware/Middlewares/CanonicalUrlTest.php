<?php

declare(strict_types=1);

namespace Radix\Tests\Middleware\Middlewares;

use PHPUnit\Framework\TestCase;
use Radix\Http\Request;
use Radix\Http\RequestHandlerInterface;
use Radix\Http\Response;
use Radix\Middleware\Middlewares\CanonicalUrl;

final class CanonicalUrlNextSpy
{
    public bool $called = false;
}

final class CanonicalUrlTest extends TestCase
{
    private ?string $previousAppUrl = null;

    protected function setUp(): void
    {
        $value = getenv('APP_URL');
        $this->previousAppUrl = is_string($value) ? $value : null;
    }

    protected function tearDown(): void
    {
        if ($this->previousAppUrl === null) {
            putenv('APP_URL');
            unset($_ENV['APP_URL'], $_SERVER['APP_URL']);

            return;
        }

        putenv('APP_URL=' . $this->previousAppUrl);
        $_ENV['APP_URL'] = $this->previousAppUrl;
        $_SERVER['APP_URL'] = $this->previousAppUrl;
    }

    /**
     * @param array<string, mixed> $server
     */
    private function request(array $server): Request
    {
        return new Request(
            uri: '/',
            method: 'GET',
            get: [],
            post: [],
            files: [],
            cookie: [],
            server: $server
        );
    }

    private function next(Response $response, CanonicalUrlNextSpy $spy): RequestHandlerInterface
    {
        return new class ($response, $spy) implements RequestHandlerInterface {
            public function __construct(
                private readonly Response $response,
                private readonly CanonicalUrlNextSpy $spy
            ) {}

            public function handle(Request $request): Response
            {
                $this->spy->called = true;

                return $this->response;
            }
        };
    }

    public function testItRedirectsToCanonicalHostWithPermanentRedirect(): void
    {
        putenv('APP_URL=https://example.com');

        $spy = new CanonicalUrlNextSpy();
        $nextResponse = new Response();

        $middleware = new CanonicalUrl();

        $response = $middleware->process(
            $this->request([
                'HTTP_HOST' => 'www.example.com',
                'HTTPS' => 'on',
                'REQUEST_URI' => '/about?x=1',
            ]),
            $this->next($nextResponse, $spy)
        );

        $this->assertFalse($spy->called);
        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame(['https://example.com/about?x=1'], $response->header('Location'));
    }

    public function testItRedirectsToCanonicalSchemeWithPermanentRedirect(): void
    {
        putenv('APP_URL=https://example.com');

        $spy = new CanonicalUrlNextSpy();
        $nextResponse = new Response();

        $middleware = new CanonicalUrl();

        $response = $middleware->process(
            $this->request([
                'HTTP_HOST' => 'example.com',
                'HTTPS' => 'off',
                'REQUEST_URI' => '/secure',
            ]),
            $this->next($nextResponse, $spy)
        );

        $this->assertFalse($spy->called);
        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame(['https://example.com/secure'], $response->header('Location'));
    }

    public function testItPassesThroughWhenRequestAlreadyMatchesCanonicalUrl(): void
    {
        putenv('APP_URL=https://example.com');

        $spy = new CanonicalUrlNextSpy();
        $nextResponse = new Response();
        $nextResponse->setBody('ok');

        $middleware = new CanonicalUrl();

        $response = $middleware->process(
            $this->request([
                'HTTP_HOST' => 'example.com',
                'HTTPS' => 'on',
                'REQUEST_URI' => '/already-ok',
            ]),
            $this->next($nextResponse, $spy)
        );

        $this->assertTrue($spy->called);
        $this->assertSame($nextResponse, $response);
        $this->assertSame('ok', $response->getBody());
    }

    public function testItPassesThroughWhenAppUrlIsMissing(): void
    {
        putenv('APP_URL');
        unset($_ENV['APP_URL'], $_SERVER['APP_URL']);

        $spy = new CanonicalUrlNextSpy();
        $nextResponse = new Response();

        $middleware = new CanonicalUrl();

        $response = $middleware->process(
            $this->request([
                'HTTP_HOST' => 'wrong.example.com',
                'HTTPS' => 'off',
                'REQUEST_URI' => '/anything',
            ]),
            $this->next($nextResponse, $spy)
        );

        $this->assertTrue($spy->called);
        $this->assertSame($nextResponse, $response);
    }

    public function testItPassesThroughWhenCanonicalHostIsLocal(): void
    {
        putenv('APP_URL=https://my-app.test');

        $spy = new CanonicalUrlNextSpy();
        $nextResponse = new Response();

        $middleware = new CanonicalUrl();

        $response = $middleware->process(
            $this->request([
                'HTTP_HOST' => 'example.com',
                'HTTPS' => 'off',
                'REQUEST_URI' => '/anything',
            ]),
            $this->next($nextResponse, $spy)
        );

        $this->assertTrue($spy->called);
        $this->assertSame($nextResponse, $response);
    }

    public function testItPassesThroughWhenCurrentHostIsLocal(): void
    {
        putenv('APP_URL=https://example.com');

        $spy = new CanonicalUrlNextSpy();
        $nextResponse = new Response();

        $middleware = new CanonicalUrl();

        $response = $middleware->process(
            $this->request([
                'HTTP_HOST' => 'localhost:8080',
                'HTTPS' => 'off',
                'REQUEST_URI' => '/anything',
            ]),
            $this->next($nextResponse, $spy)
        );

        $this->assertTrue($spy->called);
        $this->assertSame($nextResponse, $response);
    }

    public function testItUsesForwardedProtoAsCurrentScheme(): void
    {
        putenv('APP_URL=https://example.com');

        $spy = new CanonicalUrlNextSpy();
        $nextResponse = new Response();

        $middleware = new CanonicalUrl();

        $response = $middleware->process(
            $this->request([
                'HTTP_HOST' => 'example.com',
                'HTTP_X_FORWARDED_PROTO' => 'https',
                'REQUEST_URI' => '/behind-proxy',
            ]),
            $this->next($nextResponse, $spy)
        );

        $this->assertTrue($spy->called);
        $this->assertSame($nextResponse, $response);
    }

    public function testItNormalizesRequestUriWhenMissingLeadingSlash(): void
    {
        putenv('APP_URL=https://example.com');

        $spy = new CanonicalUrlNextSpy();
        $nextResponse = new Response();

        $middleware = new CanonicalUrl();

        $response = $middleware->process(
            $this->request([
                'HTTP_HOST' => 'old.example.com',
                'HTTPS' => 'on',
                'REQUEST_URI' => 'path-without-slash',
            ]),
            $this->next($nextResponse, $spy)
        );

        $this->assertFalse($spy->called);
        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame(['https://example.com/path-without-slash'], $response->header('Location'));
    }

    public function testItNormalizesUppercaseCanonicalSchemeAndHost(): void
    {
        putenv('APP_URL=HTTPS://EXAMPLE.COM');

        $spy = new CanonicalUrlNextSpy();
        $nextResponse = new Response();

        $middleware = new CanonicalUrl();

        $response = $middleware->process(
            $this->request([
                'HTTP_HOST' => 'example.com',
                'HTTPS' => 'on',
                'REQUEST_URI' => '/case',
            ]),
            $this->next($nextResponse, $spy)
        );

        $this->assertTrue($spy->called);
        $this->assertSame($nextResponse, $response);
    }

    public function testItNormalizesUppercaseCurrentHost(): void
    {
        putenv('APP_URL=https://example.com');

        $spy = new CanonicalUrlNextSpy();
        $nextResponse = new Response();

        $middleware = new CanonicalUrl();

        $response = $middleware->process(
            $this->request([
                'HTTP_HOST' => 'EXAMPLE.COM',
                'HTTPS' => 'on',
                'REQUEST_URI' => '/current-host-case',
            ]),
            $this->next($nextResponse, $spy)
        );

        $this->assertTrue($spy->called);
        $this->assertSame($nextResponse, $response);
    }

    public function testItTreatsUppercaseHttpsOnAsHttps(): void
    {
        putenv('APP_URL=https://example.com');

        $spy = new CanonicalUrlNextSpy();
        $nextResponse = new Response();

        $middleware = new CanonicalUrl();

        $response = $middleware->process(
            $this->request([
                'HTTP_HOST' => 'example.com',
                'HTTPS' => 'ON',
                'REQUEST_URI' => '/uppercase-on',
            ]),
            $this->next($nextResponse, $spy)
        );

        $this->assertTrue($spy->called);
        $this->assertSame($nextResponse, $response);
    }

    public function testItTreatsUppercaseForwardedProtoHttpsAsHttps(): void
    {
        putenv('APP_URL=https://example.com');

        $spy = new CanonicalUrlNextSpy();
        $nextResponse = new Response();

        $middleware = new CanonicalUrl();

        $response = $middleware->process(
            $this->request([
                'HTTP_HOST' => 'example.com',
                'HTTP_X_FORWARDED_PROTO' => 'HTTPS',
                'REQUEST_URI' => '/uppercase-forwarded-proto',
            ]),
            $this->next($nextResponse, $spy)
        );

        $this->assertTrue($spy->called);
        $this->assertSame($nextResponse, $response);
    }

    public function testItTreatsHttpsStringOneAsHttps(): void
    {
        putenv('APP_URL=https://example.com');

        $spy = new CanonicalUrlNextSpy();
        $nextResponse = new Response();

        $middleware = new CanonicalUrl();

        $response = $middleware->process(
            $this->request([
                'HTTP_HOST' => 'example.com',
                'HTTPS' => '1',
                'REQUEST_URI' => '/string-one',
            ]),
            $this->next($nextResponse, $spy)
        );

        $this->assertTrue($spy->called);
        $this->assertSame($nextResponse, $response);
    }

    public function testItTreatsHttpsIntegerOneAsHttps(): void
    {
        putenv('APP_URL=https://example.com');

        $spy = new CanonicalUrlNextSpy();
        $nextResponse = new Response();

        $middleware = new CanonicalUrl();

        $response = $middleware->process(
            $this->request([
                'HTTP_HOST' => 'example.com',
                'HTTPS' => 1,
                'REQUEST_URI' => '/integer-one',
            ]),
            $this->next($nextResponse, $spy)
        );

        $this->assertTrue($spy->called);
        $this->assertSame($nextResponse, $response);
    }

    public function testItPassesThroughWhenCanonicalSchemeIsMissing(): void
    {
        putenv('APP_URL=//example.com');

        $spy = new CanonicalUrlNextSpy();
        $nextResponse = new Response();

        $middleware = new CanonicalUrl();

        $response = $middleware->process(
            $this->request([
                'HTTP_HOST' => 'other.example.com',
                'HTTPS' => 'off',
                'REQUEST_URI' => '/missing-scheme',
            ]),
            $this->next($nextResponse, $spy)
        );

        $this->assertTrue($spy->called);
        $this->assertSame($nextResponse, $response);
    }

    public function testItPassesThroughWhenCanonicalHostIsMissing(): void
    {
        putenv('APP_URL=https:///missing-host');

        $spy = new CanonicalUrlNextSpy();
        $nextResponse = new Response();

        $middleware = new CanonicalUrl();

        $response = $middleware->process(
            $this->request([
                'HTTP_HOST' => 'other.example.com',
                'HTTPS' => 'off',
                'REQUEST_URI' => '/missing-host',
            ]),
            $this->next($nextResponse, $spy)
        );

        $this->assertTrue($spy->called);
        $this->assertSame($nextResponse, $response);
    }

    public function testItPassesThroughWhenCurrentHostIsMissing(): void
    {
        putenv('APP_URL=https://example.com');

        $spy = new CanonicalUrlNextSpy();
        $nextResponse = new Response();

        $middleware = new CanonicalUrl();

        $response = $middleware->process(
            $this->request([
                'HTTPS' => 'off',
                'REQUEST_URI' => '/missing-current-host',
            ]),
            $this->next($nextResponse, $spy)
        );

        $this->assertTrue($spy->called);
        $this->assertSame($nextResponse, $response);
    }

    public function testItPassesThroughWhenCurrentHostIsNotString(): void
    {
        putenv('APP_URL=https://example.com');

        $spy = new CanonicalUrlNextSpy();
        $nextResponse = new Response();

        $middleware = new CanonicalUrl();

        $response = $middleware->process(
            $this->request([
                'HTTP_HOST' => ['example.com'],
                'HTTPS' => 'off',
                'REQUEST_URI' => '/host-not-string',
            ]),
            $this->next($nextResponse, $spy)
        );

        $this->assertTrue($spy->called);
        $this->assertSame($nextResponse, $response);
    }

    public function testItUsesSlashWhenRequestUriIsEmpty(): void
    {
        putenv('APP_URL=https://example.com');

        $spy = new CanonicalUrlNextSpy();
        $nextResponse = new Response();

        $middleware = new CanonicalUrl();

        $response = $middleware->process(
            $this->request([
                'HTTP_HOST' => 'old.example.com',
                'HTTPS' => 'on',
                'REQUEST_URI' => '',
            ]),
            $this->next($nextResponse, $spy)
        );

        $this->assertFalse($spy->called);
        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame(['https://example.com/'], $response->header('Location'));
    }

    public function testItUsesSlashWhenRequestUriIsNotString(): void
    {
        putenv('APP_URL=https://example.com');

        $spy = new CanonicalUrlNextSpy();
        $nextResponse = new Response();

        $middleware = new CanonicalUrl();

        $response = $middleware->process(
            $this->request([
                'HTTP_HOST' => 'old.example.com',
                'HTTPS' => 'on',
                'REQUEST_URI' => ['not-string'],
            ]),
            $this->next($nextResponse, $spy)
        );

        $this->assertFalse($spy->called);
        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame(['https://example.com/'], $response->header('Location'));
    }
}
