<?php

declare(strict_types=1);

namespace Radix\Viewer;

interface TemplateViewerInterface
{
    /**
     * @param array<string,mixed> $data
     */
    public function render(string $template, array $data = [], string $version = ''): string;

    public function enableDebugMode(bool $debug): void;

    public function registerFilter(string $name, callable $callback, string $type = 'string'): void;

    /**
     * @param array<string,mixed> $data
     */
    public function invalidateCache(string $template, array $data = [], string $version = ''): void;

    public function shared(string $name, mixed $value): void;

    /**
     * @param array<string,mixed> $data
     */
    public static function view(string $template, array $data = []): string;
}
