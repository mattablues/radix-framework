<?php

declare(strict_types=1);

namespace Radix\Middleware;

use Radix\Http\Request;
use Radix\Http\RequestHandler;
use Radix\Http\RequestHandlerInterface;
use Radix\Http\Response;

class MiddlewareRequestHandler implements RequestHandlerInterface
{
    /**
     * @param array<int,MiddlewareInterface> $middlewares
     */
    public function __construct(
        private array $middlewares,
        private readonly RequestHandler $requestHandler
    ) {}

    public function handle(Request $request): Response
    {
        $middleware = array_shift($this->middlewares);

        if ($middleware === null) {
            return $this->requestHandler->handle($request);
        }

        return $middleware->process($request, $this);
    }
}
