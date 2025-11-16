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

        $routeHandler = null;
        /** @var array<string,mixed> $args */
        $args = [];
        $action = null;

        // Bygg handler + args
        if (is_callable($params[0])) {
            $action = null;
            $handler = $params[0];
            unset($params[0]);

            if (is_array($handler) && count($handler) === 2) {
                // [$object, 'method'] eller [ClassName::class, 'method']
                [$objOrClass, $methodName] = $handler;

                if (!is_object($objOrClass) && !is_string($objOrClass)) {
                    throw new UnexpectedValueException('First element of callable array must be object|string.');
                }
                if (!is_string($methodName)) {
                    throw new UnexpectedValueException('Second element of callable array must be a method name string.');
                }

                $reflection = new ReflectionMethod($objOrClass, $methodName);
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
                    $resp = $this->container->get(Response::class);
                    if (!$resp instanceof Response) {
                        throw new UnexpectedValueException(
                            'Container must return Radix\Http\Response for Response::class.'
                        );
                    }
                    $params['response'] = $resp;
                }
            }

            $except = ['method', 'middlewares'];
            $args = [];

            foreach ($params as $key => $value) {
                if (in_array($key, $except, true)) {
                    continue;
                }
                $args[$key] = $value;
            }

            if (count($args) !== count($arguments)) {
                throw new PageNotFoundException("Function argument(s) missing in query string");
            }

            // Normalisera till Closure så att RequestHandler alltid får en Closure i detta fall
            $callableHandler = $handler;
            $routeHandler = static function (...$invokeArgs) use ($callableHandler) {
                return $callableHandler(...$invokeArgs);
            };
        } else {
            $controller = $params[0];
            $action = $params[1];

            if (!is_string($controller) || !is_string($action)) {
                throw new UnexpectedValueException('Controller and action for route must be strings.');
            }

            $controllerInstance = $this->container->get($controller);
            if (!$controllerInstance instanceof \Radix\Controller\AbstractController) {
                throw new UnexpectedValueException(
                    "Resolved controller '$controller' must extend Radix\\Controller\\AbstractController."
                );
            }

            $viewer = $this->container->get(TemplateViewerInterface::class);
            if (!$viewer instanceof TemplateViewerInterface) {
                throw new UnexpectedValueException(
                    'Container must return TemplateViewerInterface for TemplateViewerInterface::class.'
                );
            }

            $responseObj = $this->container->get(Response::class);
            if (!$responseObj instanceof Response) {
                throw new UnexpectedValueException(
                    'Container must return Radix\Http\Response for Response::class.'
                );
            }

            $controllerInstance->setViewer($viewer);
            $controllerInstance->setResponse($responseObj);

            try {
                $args = $this->actionArguments($controller, $action, $params);
            } catch (Exception) {
                throw new PageNotFoundException("Controller method '$action' does not exist.'");
            }

            $routeHandler = $controllerInstance;
        }

        // Hämta och typ-säkra EventDispatcher
        $eventDispatcher = $this->container->get(\Radix\EventDispatcher\EventDispatcher::class);
        if (!$eventDispatcher instanceof \Radix\EventDispatcher\EventDispatcher) {
            throw new UnexpectedValueException(
                'Container must return Radix\EventDispatcher\EventDispatcher for its EventDispatcher binding.'
            );
        }

        $requestHandler = new RequestHandler(
            handler: $routeHandler,
            eventDispatcher: $eventDispatcher,
            args: $args,
            action: $action
        );

        $middleware = $this->middlewares($params);
        $middlewareHandler = new MiddlewareRequestHandler($middleware, $requestHandler);

        return $middlewareHandler->handle($request);
    }

    /**
     * @param array<int|string,mixed> $params
     * @return array<int,\Radix\Middleware\MiddlewareInterface>
     */
    private function middlewares(array $params): array
    {
        if (!array_key_exists('middlewares', $params)) {
            return [];
        }

        $middlewares = $params['middlewares'];

        if (!is_array($middlewares)) {
            return [];
        }

        // Mappa alias → instanser och typ‑säkra
        foreach ($middlewares as $key => $alias) {
            if (!is_string($alias)) {
                throw new UnexpectedValueException('Middleware alias must be a string.');
            }

            if (!array_key_exists($alias, $this->middlewareClasses)) {
                throw new UnexpectedValueException("Middleware class alias '{$alias}' does not exist.");
            }

            $instance = $this->container->get($this->middlewareClasses[$alias]);

            if (!$instance instanceof \Radix\Middleware\MiddlewareInterface) {
                $class = $this->middlewareClasses[$alias];
                throw new UnexpectedValueException("Middleware '$class' must implement MiddlewareInterface.");
            }

            $middlewares[$key] = $instance;
        }

        /** @var array<int,\Radix\Middleware\MiddlewareInterface> $middlewares */
        return $middlewares;
    }

    /**
     * Bygg argumentlista till en controller‑action baserat på route‑parametrar.
     *
     * @param array<int|string,mixed> $params
     * @return array<string,mixed>
     */
    private function actionArguments(string $controller, string $action, array $params): array
    {
        $args = [];
        $method = new ReflectionMethod($controller, $action);

        foreach ($method->getParameters() as $parameter) {
            $name = $parameter->getName();
            if (array_key_exists($name, $params)) {
                $args[$name] = $params[$name];
            }
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