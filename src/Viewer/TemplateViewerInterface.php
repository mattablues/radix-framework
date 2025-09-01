<?php

declare(strict_types=1);

namespace Radix\Viewer;

interface TemplateViewerInterface
{
    public function render(string $template, array $data = [], string $version = ''): string;

    public function enableDebugMode(bool $debug): void;

    public function registerFilter(string $name, callable $callback, string $type = 'string'): void;

    public function invalidateCache(string $template, array $data = [], string $version = ''): void;

    public function shared(string $name, mixed $value): void;

    public static function view(string $template, array $data = []): string;
}