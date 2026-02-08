<?php

declare(strict_types=1);


use PHPUnit\Framework\TestCase;
use Radix\Container\Container;
use Radix\EventDispatcher\EventDispatcher;
use Radix\Http\Request;
use Radix\Http\RequestHandler;
use Radix\Http\Response;
use Radix\Routing\Dispatcher;
use Radix\Routing\Router;
use Radix\Session\SessionInterface;
use Radix\Viewer\TemplateViewerInterface;

class HeadRequestTest extends TestCase
{
    protected Dispatcher $dispatcher;

    protected function setUp(): void
    {
        // Initiera Router
        $router = new Router();

        // Lägg till rutter för test
        $router->get('/login', function () {
            $response = new Response();
            $response->setStatusCode(200)->setBody('Login Page');
            return $response;
        });

        $router->get('/user', function () {
            $response = new Response();
            $response->setStatusCode(302)->setHeader('Location', '/login')->setBody('Redirect to Login');
            return $response;
        });

        // Mocka ett DI-container
        $container = $this->createMock(Container::class);

        // Matcha förväntade beroenden med rätt objekt
        $container->method('get')->willReturnCallback(function (string $classname) {
            return match ($classname) {
                // Mocka den nödvändiga SessionInterface
                SessionInterface::class => $this->createMock(SessionInterface::class),

                // Mocka TemplateViewerInterface om det behövs i ditt system
                TemplateViewerInterface::class => $this->createMock(TemplateViewerInterface::class),

                // Mocka EventDispatcher
                EventDispatcher::class => $this->createMock(EventDispatcher::class),

                // Mocka RequestHandler
                RequestHandler::class => $this->createMock(RequestHandler::class),

                default => throw new RuntimeException("Class $classname cannot be resolved"),
            };
        });

        // Initialisera Dispatcher
        $this->dispatcher = new Dispatcher($router, $container, []);
    }

    /**
     * Simulera en Request och hämta Response.
     */
    protected function sendRequest(string $uri, string $method = 'GET'): Response
    {
        // Mocka serverdata för Request
        $server = [
            'REQUEST_URI' => $uri,
            'REQUEST_METHOD' => $method,
        ];

        // Skapa en ny Request
        $request = new Request(
            uri: $uri,
            method: $method,
            get: [],
            post: [],
            files: [],
            cookie: [],
            server: $server
        );

        // Skickar request via Dispatcher
        return $this->dispatcher->handle($request);
    }

    /**
     * Testa om HEAD-metoden fungerar på /login.
     */
    public function testLoginRouteSupportsHead(): void
    {
        $response = $this->sendRequest('/login', 'HEAD');
        $this->assertEquals(200, $response->getStatusCode());
    }

    /**
     * Testa om obehörig /user omdirigeras till /login.
     */
    public function testUserRouteRedirectsUnauthenticated(): void
    {
        $response = $this->sendRequest('/user', 'HEAD');
        $this->assertEquals(302, $response->getStatusCode());

        // Kontrollera att 'Location' header returneras som en array
        $this->assertEquals(['/login'], $response->header('Location'));
    }
}
