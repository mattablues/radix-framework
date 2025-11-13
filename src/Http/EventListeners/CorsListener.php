<?php

declare(strict_types=1);

namespace Radix\Http\EventListeners;

use Radix\Http\Event\ResponseEvent;

class CorsListener
{
    public function __invoke(ResponseEvent $event): void
    {
        $request = $event->request();
        $response = $event->response();

        $env = getenv('APP_ENV') ?: 'production';
        if ($env === 'production') {
            // I prod, låt kontrollern bestämma (HealthController tar bort CORS)
            return;
        }

        $allowedOrigin = getenv('CORS_ALLOW_ORIGIN') ?: '*';
        if (!isset($response->getHeaders()['Access-Control-Allow-Origin'])) {
            $response->setHeader('Access-Control-Allow-Origin', $allowedOrigin);
        }

        $response->setHeader('Access-Control-Allow-Methods', 'GET,POST,PUT,PATCH,DELETE,OPTIONS');
        $response->setHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type, X-Requested-With');
        $response->setHeader('Access-Control-Max-Age', '600');
        $response->setHeader('Access-Control-Expose-Headers', 'X-Request-Id');

        $allowCredentials = (getenv('CORS_ALLOW_CREDENTIALS') === '1');
        if ($allowCredentials) {
            $response->setHeader('Access-Control-Allow-Credentials', 'true');
            $origin = $request->header('Origin') ?? '*';
            if ($origin !== '*') {
                $response->setHeader('Access-Control-Allow-Origin', $origin);
            }
        }

        if (strtoupper($request->method) === 'OPTIONS') {
            $response->setStatusCode(204);
        }
    }
}