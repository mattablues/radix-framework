<?php

declare(strict_types=1);

namespace Radix\Database\ORM;

use Radix\Support\StringHelper;
use RuntimeException;

final class ConventionModelClassResolver implements ModelClassResolverInterface
{
    public function __construct(
        private readonly string $modelNamespace = 'App\\Models\\'
    ) {}

    public function resolve(string $classOrTable): string
    {
        if (class_exists($classOrTable)) {
            return $classOrTable;
        }

        $fqcn = $this->modelNamespace . ucfirst(StringHelper::singularize($classOrTable));

        if (class_exists($fqcn)) {
            return $fqcn;
        }

        throw new RuntimeException("Model class '{$classOrTable}' not found. Expected '{$fqcn}'.");
    }
}
