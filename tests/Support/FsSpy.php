<?php

declare(strict_types=1);

namespace Radix\Support;

final class FsSpy
{
    public static int $mkdirCallCount = 0;
    public static ?string $lastMkdirPath = null;
    public static ?int $lastMkdirPermissions = null;

    public static function reset(): void
    {
        self::$mkdirCallCount = 0;
        self::$lastMkdirPath = null;
        self::$lastMkdirPermissions = null;
    }
}
