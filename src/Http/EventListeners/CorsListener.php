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

        // Bygg upp Expose-Headers dynamiskt och inkludera rate limit-headers
        $existingExpose = $response->getHeaders()['Access-Control-Expose-Headers'] ?? '';
        $expose = array_filter(array_map('trim', explode(',', $existingExpose)));
        $wanted = ['X-Request-Id', 'X-RateLimit-Limit', 'X-RateLimit-Remaining', 'X-RateLimit-Reset'];
        $expose = array_values(array_unique(array_merge($expose, $wanted)));
        $response->setHeader('Access-Control-Expose-Headers', implode(',', $expose));

        $response->setHeader('Access-Control-Max-Age', '600');

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
