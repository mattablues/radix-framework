<?php

declare(strict_types=1);

namespace Radix\Middleware\Middlewares;

use Radix\Error\ErrorResponder;
use Radix\Http\Request;
use Radix\Http\RequestHandlerInterface;
use Radix\Http\Response;
use Radix\Middleware\MiddlewareInterface;

final class RequestSizeLimitMiddleware implements MiddlewareInterface
{
    public function __construct(private int $maxBytes)
    {
    }

    public function process(Request $request, RequestHandlerInterface $next): Response
    {
        $lenHeader = $request->header('Content-Length');
        $len = is_numeric($lenHeader) ? (int) $lenHeader : null;

        if ($len !== null && $len > $this->maxBytes) {
            // API får extra detaljer om gränsen
            return ErrorResponder::respond(
                $request,
                413,
                'Payload Too Large',
                ['limit' => $this->maxBytes]
            );
        }

        return $next->handle($request);
    }
}