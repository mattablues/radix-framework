<?php

declare(strict_types=1);

namespace Radix\Http;

interface RequestInterface
{
    public static function createFromGlobals(): self;
}