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

        // Konfigurerbar origin (fallback '*')
        $allowedOrigin = getenv('CORS_ALLOW_ORIGIN') ?: '*';

        // Sätt Allow-Origin om inte redan satt
        $headers = $response->getHeaders();
        if (!isset($headers['Access-Control-Allow-Origin'])) {
            $response->setHeader('Access-Control-Allow-Origin', $allowedOrigin);
        }

        // Vanliga CORS-huvuden
        $response->setHeader('Access-Control-Allow-Methods', 'GET,POST,PUT,PATCH,DELETE,OPTIONS');
        $response->setHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type, X-Requested-With');
        $response->setHeader('Access-Control-Max-Age', '600');

        // Exponera headers till klient (JS kan läsa X-Request-Id)
        $response->setHeader('Access-Control-Expose-Headers', 'X-Request-Id');

        // Credentials-stöd (konfigstyrt)
        // Obs: Om du sätter Allow-Credentials:true måste Allow-Origin vara en specifik origin, inte '*'
        $allowCredentials = (getenv('CORS_ALLOW_CREDENTIALS') === '1');
        if ($allowCredentials) {
            $response->setHeader('Access-Control-Allow-Credentials', 'true');
            if ($allowedOrigin === '*') {
                // För säkerhets skull: om credentials används och origin är '*', sätt tillbaka till request origin om tillgänglig
                $origin = $request->header('Origin') ?? '*';
                if ($origin !== '*') {
                    $response->setHeader('Access-Control-Allow-Origin', $origin);
                }
            }
        }

        // Preflight: säkerställ 204 (ingen body krävs)
        if (strtoupper($request->method) === 'OPTIONS') {
            $response->setStatusCode(204);
        }
    }
}