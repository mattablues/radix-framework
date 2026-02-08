<?php

declare(strict_types=1);

namespace Radix\Tests\Http;

use PHPUnit\Framework\TestCase;
use Radix\EventDispatcher\EventDispatcher;
use Radix\Http\Event\ResponseEvent;
use Radix\Http\Request;
use Radix\Http\RequestHandler;
use Radix\Http\Response;
use Radix\Session\SessionInterface;

final class RequestHandlerTest extends TestCase
{
    public function testResponseEventIsDispatchedWhenHandlerReturnsResponse(): void
    {
        // Enkel spy-dispatcher
        $dispatcher = new class extends EventDispatcher {
            public int $calls = 0;
            public ?object $lastEvent = null;

            public function dispatch(object $event): object
            {
                $this->calls++;
                $this->lastEvent = $event;
                return parent::dispatch($event);
            }
        };

        /** @var array<string,mixed> $get */
        $get = [];
        /** @var array<string,mixed> $post */
        $post = [];
        /** @var array<string,mixed> $files */
        $files = [];
        /** @var array<string,mixed> $cookie */
        $cookie = [];
        /** @var array<string,mixed> $server */
        $server = [];

        $request = new Request(
            uri: '/test',
            method: 'GET',
            get: $get,
            post: $post,
            files: $files,
            cookie: $cookie,
            server: $server
        );

        // Handler som bara returnerar en Response
        $handler = function () {
            return new Response();
        };

        $requestHandler = new RequestHandler(
            handler: $handler,
            eventDispatcher: $dispatcher,
            args: []
        );

        $response = $requestHandler->handle($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(1, $dispatcher->calls, 'ResponseEvent ska dispatchas exakt en gång.');
        $this->assertInstanceOf(ResponseEvent::class, $dispatcher->lastEvent);
    }

    public function testCsrfValidationRunsForPostWhenApiSegmentIsNotAtStart(): void
    {
        // Återanvänd samma typ av fake-session som i övriga tester
        $session = new class implements SessionInterface {
            private bool $started = true;
            /** @var array<string,mixed> */
            private array $store = [];

            public int $validateCalls = 0;
            public ?string $lastToken = null;

            public function __construct()
            {
                $this->store['csrf_time'] = time();
            }

            public function isStarted(): bool
            {
                return $this->started;
            }

            public function start(): bool
            {
                $this->started = true;
                return true;
            }

            public function destroy(): void
            {
                $this->store = [];
                $this->started = false;
            }

            public function clear(): void
            {
                $this->store = [];
            }

            public function isAuthenticated(): bool
            {
                return false;
            }

            public function setCsrfToken(): string
            {
                $token = 'dummy';
                $this->store['csrf_token'] = $token;
                $this->store['csrf_time'] = time();
                return $token;
            }

            public function csrf(): string
            {
                $token = $this->store['csrf_token'] ?? $this->setCsrfToken();
                if (!is_string($token)) {
                    return $this->setCsrfToken();
                }
                return $token;
            }

            public function validateCsrfToken(?string $token): void
            {
                $this->validateCalls++;
                $this->lastToken = $token;
            }

            public function set(string $key, mixed $value): void
            {
                $this->store[$key] = $value;
            }

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->store[$key] ?? $default;
            }

            public function has(string $key): bool
            {
                return array_key_exists($key, $this->store);
            }

            public function remove(string $key): void
            {
                unset($this->store[$key]);
            }

            public function setFlashMessage(string $message, string $type = 'success', array $params = []): void
            {
                $this->store['flash_notification'] = ['body' => $message, 'type' => $type] + $params;
            }

            /**
             * @return array<string,mixed>|null
             */
            public function flashMessage(): ?array
            {
                $msg = $this->store['flash_notification'] ?? null;
                if (!is_array($msg)) {
                    return null;
                }

                /** @var array<string,mixed> $msg */
                return $msg;
            }

            public function isValid(): bool
            {
                return true;
            }
        };

        $eventDispatcher = new EventDispatcher();

        $handler = function () {
            return new Response();
        };

        $requestHandler = new RequestHandler(
            handler: $handler,
            eventDispatcher: $eventDispatcher,
            args: []
        );

        // URI innehåller "/api/v1" men INTE i början
        $request = $this->makeRequest(
            method: 'POST',
            uri: '/web/foo/api/v1/users',
            session: $session,
            csrfToken: 'token-non-root-api'
        );

        $session->validateCalls = 0;
        $session->lastToken = null;

        $requestHandler->handle($request);

        // Original: isApiRequest() === false => CSRF ska köras
        // Mutant:   isApiRequest() === true  => CSRF körs inte
        $this->assertSame(1, $session->validateCalls, 'CSRF ska köras när /api/v.. inte ligger först i URI:n.');
        $this->assertSame('token-non-root-api', $session->lastToken);
    }

    public function testCsrfValidationRunsForNonApiRequestsOnly(): void
    {
        // Samma typ av fake-session som i CsrfMiddlewareTest, men med räknare
        $session = new class implements SessionInterface {
            private bool $started = true;
            /** @var array<string,mixed> */
            private array $store = [];

            // RÄKNARE för det här testet
            public int $validateCalls = 0;
            public ?string $lastToken = null;

            public function __construct()
            {
                // sätt en giltig csrf_time så att eventuella datumkontroller inte spökar
                $this->store['csrf_time'] = time();
            }

            public function isStarted(): bool
            {
                return $this->started;
            }

            public function start(): bool
            {
                $this->started = true;
                return true;
            }

            public function destroy(): void
            {
                $this->store = [];
                $this->started = false;
            }

            public function clear(): void
            {
                $this->store = [];
            }

            public function isAuthenticated(): bool
            {
                return false;
            }

            public function setCsrfToken(): string
            {
                // för vårt test spelar själva värdet ingen roll
                $token = 'dummy';
                $this->store['csrf_token'] = $token;
                $this->store['csrf_time'] = time();
                return $token;
            }

            public function csrf(): string
            {
                $token = $this->store['csrf_token'] ?? $this->setCsrfToken();
                if (!is_string($token)) {
                    return $this->setCsrfToken();
                }
                return $token;
            }

            public function validateCsrfToken(?string $token): void
            {
                // Här loggar vi bara att metoden anropats
                $this->validateCalls++;
                $this->lastToken = $token;
                // ingen validering / inget undantag – vi vill bara se *att* den kallas
            }

            public function set(string $key, mixed $value): void
            {
                $this->store[$key] = $value;
            }

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->store[$key] ?? $default;
            }

            public function has(string $key): bool
            {
                return array_key_exists($key, $this->store);
            }

            public function remove(string $key): void
            {
                unset($this->store[$key]);
            }

            public function setFlashMessage(string $message, string $type = 'success', array $params = []): void
            {
                $this->store['flash_notification'] = ['body' => $message, 'type' => $type] + $params;
            }

            /**
             * @return array<string,mixed>|null
             */
            public function flashMessage(): ?array
            {
                $msg = $this->store['flash_notification'] ?? null;
                if (!is_array($msg)) {
                    return null;
                }

                /** @var array<string,mixed> $msg */
                return $msg;
            }

            public function isValid(): bool
            {
                return true;
            }
        };

        // Använd den riktiga EventDispatcher-klassen
        $eventDispatcher = new EventDispatcher();

        // Handler som bara returnerar en Response
        $handler = function () {
            return new Response();
        };

        $requestHandler = new RequestHandler(
            handler: $handler,
            eventDispatcher: $eventDispatcher,
            args: []
        );

        // 1) ICKE-API POST – CSRF SKA KÖRAS
        $webRequest = $this->makeRequest(
            method: 'POST',
            uri: '/kontakt',
            session: $session,
            csrfToken: 'token-web'
        );

        $session->validateCalls = 0;
        $session->lastToken = null;

        $requestHandler->handle($webRequest);

        $this->assertSame(1, $session->validateCalls, 'CSRF ska valideras för vanliga POST-requests.');
        $this->assertSame('token-web', $session->lastToken);

        // 2) API POST – CSRF SKA INTE KÖRAS
        $apiRequest = $this->makeRequest(
            method: 'POST',
            uri: '/api/v1/users',
            session: $session,
            csrfToken: 'token-api'
        );

        $session->validateCalls = 0;
        $session->lastToken = null;

        $requestHandler->handle($apiRequest);

        $this->assertSame(0, $session->validateCalls, 'CSRF ska inte valideras för API-requests.');
        $this->assertNull($session->lastToken);
    }

    private function makeRequest(
        string $method,
        string $uri,
        SessionInterface $session,
        ?string $csrfToken
    ): Request {
        /** @var array<string,mixed> $get */
        $get = [];
        /** @var array<string,mixed> $post */
        $post = [];
        /** @var array<string,mixed> $files */
        $files = [];
        /** @var array<string,mixed> $cookie */
        $cookie = [];
        /** @var array<string,mixed> $server */
        $server = [];

        if ($csrfToken !== null) {
            $post['csrf_token'] = $csrfToken;
        }

        // Samma konstruktor-pattern som i CsrfMiddlewareTest::makeReq()
        $req = new Request($uri, $method, $get, $post, $files, $cookie, $server);
        $req->setSession($session);

        return $req;
    }
}
