<?php

declare(strict_types=1);

namespace Radix\Routing;

use Exception;
use Radix\Container\Container;
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
    public function __construct (
        private Router    $router,
        private Container $container,
        private array     $middlewareClasses
    )
    {
    }

    public function handle(Request $request): Response
    {
        $path = $this->path($request->uri);

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
                ->setBody(json_encode($body));

            return $response;
        }

        $params = $this->router->match($path, $request->method);

        $method = $request->method;
        if ($method === 'HEAD') {
            error_log("Converting method HEAD to GET");
            $method = 'GET'; // Omvandla till GET för att matcha rutter
        }

        if ($params === false) {
            throw new PageNotFoundException("No route matched for '$path' with method '$request->method'", 404);
        }

        if(is_callable($params[0])) {
            $action = null;
            $handler = $params[0];
            unset($params[0]);

            $reflection = new ReflectionFunction($handler);
            $arguments  = $reflection->getParameters();

            foreach ($arguments as $argument) {
                if($argument->getName() === 'request') {
                    $params['request'] = $request;
                }

                if($argument->getName() === 'response') {
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

            if(count($args) !== count($arguments)) {
                throw new PageNotFoundException("Function argument(s) missing in query string", 404);
            }

        } else {
            $controller = $params[0];
            $action = $params[1];

            $handler = $this->container->get($controller);
            $handler->setViewer($this->container->get(TemplateViewerInterface::class));
            //$handler->setAuthUser($this->container->get(AuthInterface::class));
            $handler->setResponse($this->container->get(Response::class));

            try {
                $args = $this->actionArguments($controller, $action, $params);

            } catch (Exception) {
                throw new PageNotFoundException("Controller method '$action' does not exist.'", 404);
            }
        }

        $request->setSession($this->container->get(SessionInterface::class));

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

        if ($path === false){
            throw new UnexpectedValueException("Malformed URL: '$uri'");
        }

        return $path;
    }
}