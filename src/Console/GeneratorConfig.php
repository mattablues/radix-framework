<?php

declare(strict_types=1);

namespace Radix\Console;

final readonly class GeneratorConfig
{
    public function __construct(
        public string $rootNamespace = 'App'
    ) {}

    public function ns(string $suffix): string
    {
        $base = trim($this->rootNamespace, '\\');
        $suffix = trim($suffix, '\\');

        return $suffix === '' ? $base : $base . '\\' . $suffix;
    }
}
