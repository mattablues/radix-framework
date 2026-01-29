<?php

declare(strict_types=1);

namespace Radix\Database\ORM;

use Radix\Support\StringHelper;

final class ConventionModelClassResolver implements ModelClassResolverInterface
{
    public function __construct(
        private string $modelNamespace = 'App\\Models\\'
    ) {}

    /**
     * @param string $classOrTable
     * @return class-string<Model>
     * @phpstan-return class-string<Model>
     */
    public function resolve(string $classOrTable): string
    {
        // Resolvern ska ENDAST mappa namn -> FQCN (ingen class_exists/autoload här).
        if (str_contains($classOrTable, '\\')) {
            /** @var class-string<Model> $fqcn */
            $fqcn = $classOrTable;
            return $fqcn;
        }

        $ns = $this->modelNamespace !== '' ? $this->modelNamespace : 'App\\Models\\';

        /** @var class-string<Model> $fqcn */
        $fqcn = $ns . ucfirst(StringHelper::singularize($classOrTable));

        return $fqcn;
    }
}
