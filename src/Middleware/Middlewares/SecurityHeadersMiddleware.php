<?php

declare(strict_types=1);

namespace Radix\Middleware\Middlewares;

use Radix\Config\Config;
use Radix\Http\Request;
use Radix\Http\RequestHandlerInterface;
use Radix\Http\Response;
use Radix\Middleware\MiddlewareInterface;

final readonly class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public function __construct(private Config $config)
    {
    }

    public function process(Request $request, RequestHandlerInterface $next): Response
    {
        $response = $next->handle($request);

        $isApi = preg_match('#^/api/v\d+(/|$)#', $request->uri) === 1;

        $response = $response
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

        if (!$isApi) {
            $response = $response->setHeader('X-Frame-Options', 'DENY');
        }

        // HSTS från config
        $enableHsts = (bool) ($this->config->get('csp.enable_hsts', true));
        $https = $request->server['HTTPS'] ?? '';
        if ($enableHsts && is_string($https) && $https !== '' && strtolower($https) !== 'off') {
            $response = $response->setHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
        }

        // Bygg CSP från config
        $section = $isApi ? 'csp.api' : 'csp.web';
        $directives = $this->config->get($section, []);

        $cspParts = [];
        if (is_array($directives)) {
            foreach ($directives as $name => $values) {
                if (!is_array($values)) {
                    continue;
                }
                $evaluated = [];
                foreach ($values as $v) {
                    // Stöd för lazy värden (callables i config)
                    if ($v instanceof \Closure) {
                        $v = $v();
                    }
                    if (is_string($v) && $v !== '') {
                        $evaluated[] = $v;
                    }
                }
                if ($evaluated !== []) {
                    $cspParts[] = $name . ' ' . implode(' ', $evaluated);
                }
            }
        }
        $csp = implode('; ', $cspParts);

        return $response->setHeader('Content-Security-Policy', $csp);
    }
}