<?php

declare(strict_types=1);

namespace Radix\Middleware\Middlewares;

use Radix\Http\Request;
use Radix\Http\Response;
use Radix\Middleware\MiddlewareInterface;

final class RequestId implements MiddlewareInterface
{
    private const int MS_FACTOR = 1000;

    public function process(Request $request, \Radix\Http\RequestHandlerInterface $next): Response
    {
        $requestId = isset($_SERVER['HTTP_X_REQUEST_ID']) && is_string($_SERVER['HTTP_X_REQUEST_ID'])
            ? $_SERVER['HTTP_X_REQUEST_ID']
            : bin2hex(random_bytes(12));
        $_SERVER['HTTP_X_REQUEST_ID'] = $requestId;

        $start = microtime(true);

        $response = $next->handle($request);

        // Använd trunkering (floor) istället för round för att förenkla och undvika mutations-flakiness
        $durationMs = (int) ((microtime(true) - $start) * self::MS_FACTOR);
        $response->setHeader('X-Request-Id', $requestId);
        $response->setHeader('X-Response-Time', $durationMs . 'ms');

        return $response;
    }
}
