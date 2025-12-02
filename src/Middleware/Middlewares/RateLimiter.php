<?php

declare(strict_types=1);

namespace Radix\Middleware\Middlewares;

use Radix\Http\JsonResponse;
use Radix\Http\Request;
use Radix\Http\RequestHandlerInterface;
use Radix\Http\Response;
use Radix\Middleware\MiddlewareInterface;
use Radix\Support\FileCache;
use Redis;
use RuntimeException;

class RateLimiter implements MiddlewareInterface
{
    /** @var Redis|null */
    private $redis;
    private bool $isRedis;

    public function __construct(
        Redis|string|null $redis = null,
        private readonly int $limit = 60,
        private readonly int $windowSeconds = 60,
        private readonly ?string $bucket = null,
        private readonly ?FileCache $fileCache = null,
    ) {
        $this->redis = $redis instanceof Redis ? $redis : null;
        $this->isRedis = $this->redis instanceof Redis;
    }

    public function process(Request $request, RequestHandlerInterface $next): Response
    {
        $bucket = $this->bucket ?: 'default';
        $ip = $request->ip();
        $now = time(); // fånga en gång
        $windowId = (int) floor($now / $this->windowSeconds);
        $resetAt = ($windowId + 1) * $this->windowSeconds;

        $key = sprintf('ratelimit:%s:%s:%d', $bucket, $ip, $windowId);

        $count = $this->increment($key, $this->windowSeconds);

        $remaining = max(0, $this->limit - $count);

        if ($count > $this->limit) {
            $res = new JsonResponse();
            $res->setStatusCode(429);
            $res->setHeader('Content-Type', 'application/json; charset=utf-8');
            $res->setHeader('Retry-After', (string) max(1, $resetAt - $now));
            $res->setHeader('X-RateLimit-Limit', (string) $this->limit);
            $res->setHeader('X-RateLimit-Remaining', '0');
            $res->setHeader('X-RateLimit-Reset', (string) $resetAt);
            $res->setBody(json_encode([
                'error' => [
                    'code' => 'too_many_requests',
                    'message' => 'Rate limit exceeded',
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
            return $res;
        }

        $response = $next->handle($request);
        $response->setHeader('X-RateLimit-Limit', (string) $this->limit);
        $response->setHeader('X-RateLimit-Remaining', (string) $remaining);
        $response->setHeader('X-RateLimit-Reset', (string) $resetAt);
        return $response;
    }

    private function increment(string $key, int $ttl): int
    {
        if ($this->isRedis) {
            /** @var Redis $redis */
            $redis = $this->redis;

            $val = $redis->incr($key);
            if ((int) $val === 1) {
                $redis->expire($key, $ttl);
            }
            error_log(sprintf("[Redis] Key: %s, Value: %d, TTL: %d", $key, $val, $ttl));
            return (int) $val;
        }

        // Om RATELIMIT_CACHE_PATH är satt: använd alltid den
        $envPath = getenv('RATELIMIT_CACHE_PATH');
        if (is_string($envPath) && $envPath !== '') {
            $cacheDir = rtrim($envPath, '/\\');
            $cache = new FileCache($cacheDir);
        } else {
            // Annars: behåll gamla beteendet (injicerad FileCache eller tempdir)
            $cacheDir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'radix_ratelimit';
            $cache = $this->fileCache ?? new FileCache($cacheDir);
        }

        $now = time();

        /** @var array{c:int,e:int}|null $payload */
        $payload = $cache->get($key);
        $count = 0;
        $expireAt = $now + max(2, $ttl);

        if (is_array($payload)) {
            $count = isset($payload['c']) ? (int) $payload['c'] : 0;
            $existingExpireAt = isset($payload['e']) ? (int) $payload['e'] : 0;

            if ($existingExpireAt > $now) {
                $expireAt = $existingExpireAt; // Retain current expiration if still valid
            }
        }

        $count++;
        $effectiveTtl = max(1, $expireAt - $now); // Ensuring absolute minimum of 1s TTL

        // Prevent recalculating TTL when it's too short
        if ($effectiveTtl < $ttl) {
            $effectiveTtl = max(2, $ttl);
        }

        // Save data into cache
        if (!$cache->set($key, ['c' => $count, 'e' => $expireAt], $effectiveTtl)) {
            throw new RuntimeException(sprintf(
                'Failed to update cache for Key: %s, Count: %d, ExpireAt: %d, EffectiveTtl: %d',
                $key,
                $count,
                $expireAt,
                $effectiveTtl
            ));
        }

        return (int) $count;
    }
}
