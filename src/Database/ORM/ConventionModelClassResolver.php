<?php

declare(strict_types=1);

namespace Radix\Database\ORM;

use Radix\Support\StringHelper;
use RuntimeException;

final class ConventionModelClassResolver implements ModelClassResolverInterface
{
    public function __construct(
        private string $modelNamespace = ''
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

        $ns = trim($this->modelNamespace);

        if ($ns === '') {
            throw new RuntimeException('ORM model namespace is not configured. Provide it when constructing ConventionModelClassResolver.');
        }

        // Normalisera så vi alltid har trailing backslash
        if (!str_ends_with($ns, '\\')) {
            $ns .= '\\';
        }

        /** @var class-string<Model> $fqcn */
        $fqcn = $ns . ucfirst(StringHelper::singularize($classOrTable));

        return $fqcn;
    }
}
