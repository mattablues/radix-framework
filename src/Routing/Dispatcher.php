<?php

declare(strict_types=1);

namespace Radix\Routing;

use Exception;
use Psr\Container\ContainerInterface;
use Radix\Http\Exception\PageNotFoundException;
use Radix\Http\JsonResponse;
use Radix\Http\Request;
use Radix\Http\RequestHandler;
use Radix\Http\Response;
use Radix\Middleware\MiddlewareRequestHandler;
use Radix\Session\SessionInterface;
use Radix\Viewer\TemplateViewerInterface;
use ReflectionFunction;
use ReflectionMethod;
use UnexpectedValueException;

readonly class Dispatcher
{
    private Router $router;
    private ContainerInterface $container;

    /** @var array<string,string> */
    private array $middlewareClasses;

    /**
     * @param array<string,string> $middlewareClasses Map alias => middleware‑klassnamn.
     */
    public function __construct(
        Router $router,
        ContainerInterface $container,
        array $middlewareClasses
    ) {
        $this->router = $router;
        $this->container = $container;
        $this->middlewareClasses = $middlewareClasses;
    }

    public function handle(Request $request): Response
    {
        $path = $this->path($request->uri);

        // Kortslut favicon-requests: returnera 204 och cachea för att minska brus
        if (in_array($request->method, ['GET', 'HEAD'], true) && $path === '/favicon.ico') {
            $res = new \Radix\Http\Response();
            $res->setStatusCode(204);
            $res->setHeader('Cache-Control', 'public, max-age=86400, immutable');
            return $res;
        }

        // Kontrollera om detta är ett API-anrop med felaktigt mönster
        if (str_starts_with($path, '/api/') && !preg_match('#^/api/v\d+(/|$)#', $path)) {
            $body = [
                "success" => false,
                "errors" => [
                    [
                        "field" => "URI",
                        "messages" => ["URI måste följa mönstret /api/v<number>, där <number> är ett heltal."]
                    ]
                ]
            ];

            $response = new JsonResponse();
            $response
                ->setStatusCode(400)
                ->setHeader('Content-Type', 'application/json')
                ->setBody(json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return $response;
        }

        $params = $this->router->match($path, $request->method);

        $method = $request->method;
        if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
            error_log("Converting method {$method} to GET");
            $method = 'GET'; // Omvandla HEAD/OPTIONS till GET för att matcha rutter
        }

        if ($params === false) {
            throw new PageNotFoundException("No route matched for '$path' with method '$request->method'");
        }

        if (is_callable($params[0])) {
            $action = null;
            $handler = $params[0];
            unset($params[0]);

            if (is_array($handler) && count($handler) === 2) {
                // [$object, 'method'] eller [ClassName::class, 'method']
                $reflection = new ReflectionMethod($handler[0], $handler[1]);
            } elseif ($handler instanceof \Closure || is_string($handler)) {
                // Funktionsnamn eller anonym funktion
                $reflection = new ReflectionFunction($handler);
            } else {
                // Någon annan callable-variant vi inte stödjer explicit
                throw new UnexpectedValueException('Unsupported callable type for route handler.');
            }

            $arguments = $reflection->getParameters();

            foreach ($arguments as $argument) {
                if ($argument->getName() === 'request') {
                    $params['request'] = $request;
                }

                if ($argument->getName() === 'response') {
                    $params['response'] = $this->container->get(Response::class);
                }
            }

            $except = ['method', 'middlewares'];

            $args = [];

            foreach ($params as $key => $value) {
                if (in_array($key, $except, true)) {
                    continue;
                } else {
                    $args[$key] = $value;
                }
            }

            if (count($args) !== count($arguments)) {
                throw new PageNotFoundException("Function argument(s) missing in query string");
            }

        } else {
            $controller = $params[0];
            $action = $params[1];

            $handler = $this->container->get($controller);
            $handler->setViewer($this->container->get(TemplateViewerInterface::class));
            $handler->setResponse($this->container->get(Response::class));

            try {
                $args = $this->actionArguments($controller, $action, $params);
            } catch (Exception) {
                throw new PageNotFoundException("Controller method '$action' does not exist.'");
            }
        }

        $requestHandler = new RequestHandler(
            handler: $handler,
            eventDispatcher: $this->container->get(\Radix\EventDispatcher\EventDispatcher::class),
            args: $args,
            action: $action
        );

        $middleware = $this->middlewares($params);
        $middlewareHandler = new MiddlewareRequestHandler($middleware, $requestHandler);

        $response = $middlewareHandler->handle($request);

        // Returnera response korrekt
        return $response;
    }

    /**
     * @param array<int|string,mixed> $params
     * @return array<int,mixed>
     */
    private function middlewares(array $params): array
    {
        if (!array_key_exists('middlewares', $params)) {
            return [];
        }

        $middlewares = $params['middlewares'];

        // Kontrollera att alla middleware-klasser finns
        array_walk($middlewares, function (&$value) {
            if (!array_key_exists($value, $this->middlewareClasses)) {
                throw new UnexpectedValueException("Middleware class '$value' does not exist.");
            }

            $value = $this->container->get($this->middlewareClasses[$value]);
        });

        return $middlewares;
    }

    /**
     * Bygg argumentlista till en controller‑action baserat på route‑parametrar.
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function actionArguments(string $controller, string $action, array $params): array
    {
        $args = [];
        $method = new ReflectionMethod($controller, $action);

        foreach ($method->getParameters() as $parameter) {
            $name = $parameter->getName();
            $args[$name] = $params[$name];
        }

        return $args;
    }

    private function path(string $uri): string
    {
        $path = parse_url($uri, PHP_URL_PATH);

        if ($path === false || $path === null) {
            throw new UnexpectedValueException("Malformed URL: '$uri'");
        }

        return $path;
    }
}