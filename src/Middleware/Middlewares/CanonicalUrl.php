<?php

declare(strict_types=1);

namespace Radix\Middleware\Middlewares;

use Radix\Http\RedirectResponse;
use Radix\Http\Request;
use Radix\Http\RequestHandlerInterface;
use Radix\Http\Response;
use Radix\Middleware\MiddlewareInterface;

final class CanonicalUrl implements MiddlewareInterface
{
    public function process(Request $request, RequestHandlerInterface $next): Response
    {
        $appUrl = getenv('APP_URL');

        if (!is_string($appUrl) || $appUrl === '') {
            return $next->handle($request);
        }

        $canonical = parse_url($appUrl);

        if (!is_array($canonical)) {
            $canonical = [];
        }

        $canonicalScheme = strtolower($this->urlPart($canonical, 'scheme'));
        $canonicalHost = strtolower($this->urlPart($canonical, 'host'));

        if ($canonicalScheme === '' || $canonicalHost === '') {
            return $next->handle($request);
        }

        if ($this->isLocalHost($canonicalHost)) {
            return $next->handle($request);
        }

        $currentHost = $this->currentHost($request);

        if ($currentHost === '' || $this->isLocalHost($currentHost)) {
            return $next->handle($request);
        }

        $currentScheme = $this->currentScheme($request);

        if ($currentScheme === $canonicalScheme && $currentHost === $canonicalHost) {
            return $next->handle($request);
        }

        return new RedirectResponse(
            $this->buildTargetUrl($request, $canonicalScheme, $canonicalHost),
            301
        );
    }

    /**
     * @param array<string, mixed> $url
     */
    private function urlPart(array $url, string $key): string
    {
        if (!array_key_exists($key, $url)) {
            return '';
        }

        $value = $url[$key];

        return is_string($value) ? $value : '';
    }

    private function currentHost(Request $request): string
    {
        $host = $request->server['HTTP_HOST'] ?? '';

        if (!is_string($host) || $host === '') {
            return '';
        }

        $host = strtolower($host);
        $portPosition = strpos($host, ':');

        if ($portPosition !== false) {
            $host = substr($host, 0, $portPosition);
        }

        return $host;
    }

    private function currentScheme(Request $request): string
    {
        $https = $request->server['HTTPS'] ?? '';

        if (is_string($https) && strtolower($https) === 'on') {
            return 'https';
        }

        if ($https === '1' || $https === 1) {
            return 'https';
        }

        $forwardedProto = $request->server['HTTP_X_FORWARDED_PROTO'] ?? '';

        if (is_string($forwardedProto) && strtolower($forwardedProto) === 'https') {
            return 'https';
        }

        return 'http';
    }

    private function buildTargetUrl(Request $request, string $scheme, string $host): string
    {
        $requestUri = $request->server['REQUEST_URI'] ?? '/';

        if (!is_string($requestUri) || $requestUri === '') {
            $requestUri = '/';
        }

        if ($requestUri[0] !== '/') {
            $requestUri = '/' . $requestUri;
        }

        return $scheme . '://' . $host . $requestUri;
    }

    private function isLocalHost(string $host): bool
    {
        return $host === 'localhost'
            || $host === '127.0.0.1'
            || str_ends_with($host, '.test')
            || str_ends_with($host, '.local');
    }
}
