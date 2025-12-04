<?php

declare(strict_types=1);

namespace Radix\Http\EventListeners;

use Radix\Config\Config;
use Radix\Http\Event\ResponseEvent;

final class CorsListener
{
    private Config $config;

    public function __construct(?Config $config = null)
    {
        if ($config !== null) {
            $this->config = $config;
            return;
        }

        /** @var Config $resolved */
        $resolved = app('config');
        $this->config = $resolved;
    }

    public function __invoke(ResponseEvent $event): void
    {
        $request = $event->request();
        $response = $event->response();

        /** @var array<string, mixed> $cors */
        $cors = $this->config->get('cors', []);

        $enabled = (bool) ($cors['enabled'] ?? false);
        if (!$enabled) {
            return;
        }

        // Begränsa CORS till valda path-prefix (t.ex. /api/v1/)
        $paths = $cors['paths'] ?? [];
        if (is_array($paths) && $paths !== []) {
            $uri = $request->uri ?? '';
            $matched = false;
            foreach ($paths as $prefix) {
                if (is_string($prefix) && $prefix !== '' && str_starts_with($uri, $prefix)) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                return;
            }
        }

        $allowOrigins = $cors['allow_origins'] ?? ['*'];
        if (!is_array($allowOrigins) || $allowOrigins === []) {
            $allowOrigins = ['*'];
        }

        $allowMethods = $cors['allow_methods'] ?? ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];
        if (!is_array($allowMethods) || $allowMethods === []) {
            $allowMethods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];
        }

        $allowHeaders = $cors['allow_headers'] ?? ['Authorization', 'Content-Type', 'X-Requested-With'];
        if (!is_array($allowHeaders) || $allowHeaders === []) {
            $allowHeaders = ['Authorization', 'Content-Type', 'X-Requested-With'];
        }

        $exposeHeaders = $cors['expose_headers'] ?? [];
        if (!is_array($exposeHeaders)) {
            $exposeHeaders = [];
        }

        $maxAgeRaw = $cors['max_age'] ?? 600;

        if (is_int($maxAgeRaw)) {
            $maxAge = $maxAgeRaw;
        } elseif (is_string($maxAgeRaw) && ctype_digit($maxAgeRaw)) {
            $maxAge = (int) $maxAgeRaw;
        } else {
            $maxAge = 600;
        }

        $allowCredentials = (bool) ($cors['allow_credentials'] ?? false);

        // Bestäm origin
        $originHeader = $request->header('Origin');
        $originToUse = '*';

        if ($allowCredentials) {
            if (is_string($originHeader) && $originHeader !== '') {
                $originToUse = $originHeader;
            }
        } else {
            if (in_array('*', $allowOrigins, true)) {
                $originToUse = '*';
            } elseif (is_string($originHeader) && $originHeader !== '') {
                if (in_array($originHeader, $allowOrigins, true)) {
                    $originToUse = $originHeader;
                } else {
                    // Ingen match → ingen CORS-header
                    return;
                }
            }
        }

        $responseHeaders = $response->getHeaders();

        if (!array_key_exists('Access-Control-Allow-Origin', $responseHeaders)) {
            $response->setHeader('Access-Control-Allow-Origin', $originToUse);
        }

        $response->setHeader('Access-Control-Allow-Methods', implode(',', $allowMethods));
        $response->setHeader('Access-Control-Allow-Headers', implode(',', $allowHeaders));

        if ($allowCredentials) {
            $response->setHeader('Access-Control-Allow-Credentials', 'true');
        }

        // Expose-Headers: kombinera befintliga + konfig
        $existingExpose = $responseHeaders['Access-Control-Expose-Headers'] ?? '';
        $existingList = array_filter(array_map('trim', explode(',', (string) $existingExpose)));
        $expose = array_values(array_unique(array_merge($existingList, $exposeHeaders)));
        if ($expose !== []) {
            $response->setHeader('Access-Control-Expose-Headers', implode(',', $expose));
        }

        if ($maxAge > 0) {
            $response->setHeader('Access-Control-Max-Age', (string) $maxAge);
        }

        // Preflight: OPTIONS → 204 No Content
        if (strtoupper($request->method) === 'OPTIONS') {
            $response->setStatusCode(204);
        }
    }
}
