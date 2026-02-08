<?php

declare(strict_types=1);

namespace Radix\Tests\Routing;

use PHPUnit\Framework\TestCase;
use Radix\Container\Container;
use Radix\EventDispatcher\EventDispatcher;
use Radix\Http\Exception\PageNotFoundException;
use Radix\Http\Request;
use Radix\Http\Response;
use Radix\Routing\Dispatcher;
use Radix\Routing\Router;
use ReflectionClass;
use UnexpectedValueException;

/**
 * En funktion som kan användas som "string callable".
 */
function handler_returns_response(Request $request): Response
{
    $res = new Response();
    $res->setStatusCode(200);
    $res->setBody('ok');
    return $res;
}

final class DispatcherStaticCallable
{
    public static function run(Request $request): Response
    {
        $res = new Response();
        $res->setStatusCode(200);
        $res->setBody('static-ok');
        return $res;
    }
}

final class DispatcherTest extends TestCase
{
    private function makeContainer(): Container
    {
        $c = new Container();
        $c->addShared(EventDispatcher::class, new EventDispatcher());
        $c->addShared(Response::class, new Response());
        return $c;
    }

    private function makeRequest(string $uri, string $method = 'GET'): Request
    {
        return new Request(
            uri: $uri,
            method: $method,
            get: [],
            post: [],
            files: [],
            cookie: [],
            server: []
        );
    }

    /**
     * Injicera en route direkt i Router så att vi kan testa Dispatcher-grenar
     * som Router-API:t inte tillåter (t.ex. string-handlers).
     *
     * @param array<int|string,mixed> $params
     */
    private function injectRoute(Router $router, string $path, array $params): void
    {
        $ref = new ReflectionClass($router);

        $routesProp = $ref->getProperty('routes');
        $routesProp->setAccessible(true);

        $routes = $routesProp->getValue($router);
        if (!is_array($routes)) {
            $routes = [];
        }

        $routes[] = [
            'path' => $path,
            'params' => $params,
        ];

        $routesProp->setValue($router, $routes);
    }

    public function testHandleAcceptsClosureHandlerAndBuildsArgsFromSignature(): void
    {
        $router = new Router();
        $container = $this->makeContainer();

        $router->get('/hello', static function (Request $request): Response {
            $res = new Response();
            $res->setStatusCode(200);
            $res->setBody('hello');
            return $res;
        });

        $dispatcher = new Dispatcher(
            router: $router,
            container: $container,
            middlewareClasses: []
        );

        $response = $dispatcher->handle($this->makeRequest('/hello', 'GET'));

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('hello', $response->getBody());
    }

    public function testHandleAcceptsStringCallableHandlerViaInjectedRoute(): void
    {
        $router = new Router();
        $container = $this->makeContainer();

        // Router::get() tillåter inte string-handlers, så vi injicerar en route-post.
        $this->injectRoute($router, '/fn', [
            0 => __NAMESPACE__ . '\\handler_returns_response',
            'method' => 'GET',
        ]);

        $dispatcher = new Dispatcher(
            router: $router,
            container: $container,
            middlewareClasses: []
        );

        $response = $dispatcher->handle($this->makeRequest('/fn', 'GET'));

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('ok', $response->getBody());
    }

    public function testHandleThrowsPageNotFoundWhenRequiredArgumentIsMissing(): void
    {
        $router = new Router();
        $container = $this->makeContainer();

        $router->get('/needs-id', static function (string $id): Response {
            $res = new Response();
            $res->setStatusCode(200);
            $res->setBody($id);
            return $res;
        });

        $dispatcher = new Dispatcher(
            router: $router,
            container: $container,
            middlewareClasses: []
        );

        $this->expectException(PageNotFoundException::class);
        $this->expectExceptionMessage('Function argument(s) missing in query string');

        $dispatcher->handle($this->makeRequest('/needs-id', 'GET'));
    }

    public function testHandleRejectsInvokableObjectHandlerWithUnexpectedValueException(): void
    {
        $router = new Router();
        $container = $this->makeContainer();

        // Invokable object: callable, men varken Closure eller string eller [obj, method]
        $invokable = new class {
            public function __invoke(Request $request): Response
            {
                $res = new Response();
                $res->setStatusCode(200);
                $res->setBody('invokable');
                return $res;
            }
        };

        // Router::get() tillåter inte object-handlers, så vi injicerar en route-post.
        $this->injectRoute($router, '/invokable', [
            0 => $invokable,
            'method' => 'GET',
        ]);

        $dispatcher = new Dispatcher(
            router: $router,
            container: $container,
            middlewareClasses: []
        );

        // Originalkod: ska kasta UnexpectedValueException ("Unsupported callable type...")
        // Mutant #10: försöker ReflectionFunction($object) => TypeError, och testet failar => mutanten dör.
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Unsupported callable type for route handler.');

        $dispatcher->handle($this->makeRequest('/invokable', 'GET'));
    }

    public function testHandleDoesNotBreakArgumentLoopAfterInjectingRequest(): void
    {
        $router = new Router();
        $container = $this->makeContainer();

        // request kommer först, men vi behöver också route-parametern "id"
        $router->get('/with-id/{id}', static function (Request $request, string $id): Response {
            $res = new Response();
            $res->setStatusCode(200);
            $res->setBody('id=' . $id);
            return $res;
        });

        $dispatcher = new Dispatcher(
            router: $router,
            container: $container,
            middlewareClasses: []
        );

        $response = $dispatcher->handle($this->makeRequest('/with-id/123', 'GET'));

        // Mutant #11 (continue -> break) gör att loopen avbryts efter request,
        // och då saknas $id => anropet kraschar/ger fel -> testet failar => mutanten dör.
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('id=123', $response->getBody());
    }

    public function testHandleAllowsMissingOptionalArgumentInSignature(): void
    {
        $router = new Router();
        $container = $this->makeContainer();

        // "id" är optional och finns inte i route-parametrar
        $router->get('/optional', static function (Request $request, string $id = 'default'): Response {
            $res = new Response();
            $res->setStatusCode(200);
            $res->setBody($id);
            return $res;
        });

        $dispatcher = new Dispatcher(
            router: $router,
            container: $container,
            middlewareClasses: []
        );

        $response = $dispatcher->handle($this->makeRequest('/optional', 'GET'));

        // Mutant #12 (|| -> &&) gör att optional inte längre tolereras (om inte också variadic),
        // vilket leder till PageNotFoundException -> testet failar => mutanten dör.
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('default', $response->getBody());
    }

    public function testHandleDoesNotBreakArgumentLoopAfterMappingFirstRouteParam(): void
    {
        $router = new Router();
        $container = $this->makeContainer();

        // Två required route-parametrar som båda måste mappas.
        $router->get('/u/{id}/p/{postid}', static function (string $id, string $postid): Response {
            $res = new Response();
            $res->setStatusCode(200);
            $res->setBody($id . ':' . $postid);
            return $res;
        });

        $dispatcher = new Dispatcher(
            router: $router,
            container: $container,
            middlewareClasses: []
        );

        $response = $dispatcher->handle($this->makeRequest('/u/10/p/99', 'GET'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('10:99', $response->getBody());
    }

    public function testHandleAcceptsCallableArrayObjectMethodHandler(): void
    {
        $router = new Router();
        $container = $this->makeContainer();

        $obj = new class {
            public function run(Request $request): Response
            {
                $res = new Response();
                $res->setStatusCode(200);
                $res->setBody('array-callable-ok');
                return $res;
            }
        };

        $router->get('/arr', [$obj, 'run']);

        $dispatcher = new Dispatcher(
            router: $router,
            container: $container,
            middlewareClasses: []
        );

        $response = $dispatcher->handle($this->makeRequest('/arr', 'GET'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('array-callable-ok', $response->getBody());
    }

    public function testFaviconGetIsShortCircuitedTo204(): void
    {
        $router = new Router();
        $container = $this->makeContainer();

        $dispatcher = new Dispatcher(
            router: $router,
            container: $container,
            middlewareClasses: []
        );

        $response = $dispatcher->handle($this->makeRequest('/favicon.ico', 'GET'));

        $this->assertSame(204, $response->getStatusCode());
    }

    public function testFaviconPostIsNotShortCircuitedAndThrowsPageNotFound(): void
    {
        $router = new Router();
        $container = $this->makeContainer();

        $dispatcher = new Dispatcher(
            router: $router,
            container: $container,
            middlewareClasses: []
        );

        $this->expectException(PageNotFoundException::class);

        $dispatcher->handle($this->makeRequest('/favicon.ico', 'POST'));
    }

    public function testApiInvalidVersionReturns400(): void
    {
        $router = new Router();
        $container = $this->makeContainer();

        $dispatcher = new Dispatcher(
            router: $router,
            container: $container,
            middlewareClasses: []
        );

        $response = $dispatcher->handle($this->makeRequest('/api/foo', 'GET'));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('/api/v<number>', $response->getBody());
    }

    public function testApiValidVersionDoesNotTrigger400Guard(): void
    {
        $router = new Router();
        $container = $this->makeContainer();

        $router->get('/api/v1/ping', static function (): Response {
            $res = new Response();
            $res->setStatusCode(200);
            $res->setBody('pong');
            return $res;
        });

        $dispatcher = new Dispatcher(
            router: $router,
            container: $container,
            middlewareClasses: []
        );

        $response = $dispatcher->handle($this->makeRequest('/api/v1/ping', 'GET'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('pong', $response->getBody());
    }

    public function testApiGuardRequiresVersionAtStartOfPathAfterApiPrefix(): void
    {
        $router = new Router();
        $container = $this->makeContainer();

        $dispatcher = new Dispatcher(
            router: $router,
            container: $container,
            middlewareClasses: []
        );

        // Startar med /api/ men versionsegmentet kommer senare -> ska ge 400 i originalet.
        $response = $dispatcher->handle($this->makeRequest('/api/x/api/v1', 'GET'));

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testHandleAcceptsCallableArrayWithStaticClassMethodString(): void
    {
        $router = new Router();
        $container = $this->makeContainer();

        // String class-name + static method => callable array där $objOrClass är string (ska vara ok).
        $router->get('/static', [DispatcherStaticCallable::class, 'run']);

        $dispatcher = new Dispatcher(
            router: $router,
            container: $container,
            middlewareClasses: []
        );

        $response = $dispatcher->handle($this->makeRequest('/static', 'GET'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('static-ok', $response->getBody());
    }

    public function testFaviconGetIsShortCircuitedTo204AndSetsCacheControlHeader(): void
    {
        $router = new Router();
        $container = $this->makeContainer();

        $dispatcher = new Dispatcher(
            router: $router,
            container: $container,
            middlewareClasses: []
        );

        $response = $dispatcher->handle($this->makeRequest('/favicon.ico', 'GET'));

        $this->assertSame(204, $response->getStatusCode());

        // Dödar MethodCallRemoval: om setHeader tas bort blir detta [] istället för värdet.
        $this->assertSame(
            ['public, max-age=86400, immutable'],
            $response->header('Cache-Control')
        );
    }

    public function testApiInvalidVersionReturns400WithExpectedJsonShape(): void
    {
        $router = new Router();
        $container = $this->makeContainer();

        $dispatcher = new Dispatcher(
            router: $router,
            container: $container,
            middlewareClasses: []
        );

        $response = $dispatcher->handle($this->makeRequest('/api/foo', 'GET'));

        $this->assertSame(400, $response->getStatusCode());

        $body = json_decode($response->getBody(), true);

        $this->assertIsArray($body);

        // Dödar:
        // - ArrayItemRemoval ("success" tas bort)
        // - FalseValue ("success" => true)
        $this->assertArrayHasKey('success', $body);
        $this->assertFalse($body['success']);

        $this->assertArrayHasKey('errors', $body);
        $this->assertIsArray($body['errors']);
        $this->assertNotEmpty($body['errors']);

        $firstError = $body['errors'][0] ?? null;
        $this->assertIsArray($firstError);

        // Dödar ArrayItemRemoval ("field" tas bort)
        $this->assertArrayHasKey('field', $firstError);
        $this->assertSame('URI', $firstError['field']);
    }
}
