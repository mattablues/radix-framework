<?php

declare(strict_types=1);

namespace Radix\Middleware\Middlewares;

use Closure;
use Radix\Config\Config;
use Radix\Http\Request;
use Radix\Http\RequestHandlerInterface;
use Radix\Http\Response;
use Radix\Middleware\MiddlewareInterface;

/**
 * Sätter säkerhetsheaders, bl.a. CSP.
 */
final class SecurityHeaders implements MiddlewareInterface
{
    public function __construct(
        private readonly Config $config
    ) {}

    public function process(Request $request, RequestHandlerInterface $next): Response
    {
        $response = $next->handle($request);

        // Välj profil ("web" / "api") beroende på t.ex. path
        $context = $this->isApiRequest($request) ? 'api' : 'web';

        /** @var array<string, mixed> $cspConfig */
        $cspConfig = $this->config->get('csp', []);

        // Bygg CSP utifrån konfig
        if (isset($cspConfig[$context]) && is_array($cspConfig[$context])) {
            /** @var array<string, mixed> $directives */
            $directives = $cspConfig[$context];

            $policy = $this->buildCspHeader($directives);

            if ($policy !== '') {
                $response->setHeader('Content-Security-Policy', $policy);
            }
        }

        // Ev. HSTS för HTTPS
        if (
            !empty($cspConfig['enable_hsts'])
            && $this->isHttps($request)
        ) {
            // 1 år, includeSubDomains, preload
            $response->setHeader(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains; preload'
            );
        }

        // Andra klassiker
        $response->setHeader('X-Content-Type-Options', 'nosniff');
        $response->setHeader('X-Frame-Options', 'DENY');
        $response->setHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->setHeader('X-XSS-Protection', '0'); // moderna browsers, CSP istället

        return $response;
    }

    /**
     * @param array<string, mixed> $directives
     */
    private function buildCspHeader(array $directives): string
    {
        $parts = [];

        foreach ($directives as $directive => $value) {
            if (!is_string($directive)) {
                continue;
            }

            // Hoppa över icke-direktiv
            if (!preg_match('/^[a-z\-]+$/', $directive)) {
                continue;
            }

            $values = is_array($value) ? $value : [$value];

            $tokens = [];

            foreach ($values as $v) {
                if ($v instanceof Closure) {
                    $v = $v();
                }

                if (!is_string($v) || $v === '') {
                    continue;
                }

                $tokens[] = $v;
            }

            if ($tokens === []) {
                continue;
            }

            $parts[] = $directive . ' ' . implode(' ', $tokens);
        }

        return implode('; ', $parts);
    }

    private function isApiRequest(Request $request): bool
    {
        $uri = $request->uri ?? '';

        return str_starts_with($uri, '/api/');
    }

    private function isHttps(Request $request): bool
    {
        $server = $request->server;

        $https = $server['HTTPS'] ?? null;
        $scheme = $server['REQUEST_SCHEME'] ?? null;
        $forwardedProto = $server['HTTP_X_FORWARDED_PROTO'] ?? null;

        if ($https === 'on' || $https === '1') {
            return true;
        }

        if ($scheme === 'https') {
            return true;
        }

        if (is_string($forwardedProto) && str_contains($forwardedProto, 'https')) {
            return true;
        }

        return false;
    }
}
