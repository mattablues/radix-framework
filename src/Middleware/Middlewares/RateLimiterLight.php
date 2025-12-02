<?php

declare(strict_types=1);

namespace Radix\Middleware\Middlewares;

final class RateLimiterLight extends RateLimiter
{
    public function __construct()
    {
        parent::__construct(redis: null, limit: 120, windowSeconds: 60, bucket: 'light', fileCache: null);
    }
}
