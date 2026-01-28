<?php

declare(strict_types=1);

namespace Radix\Database\ORM;

interface ModelClassResolverInterface
{
    /**
     * @param string $classOrTable Antingen FQCN eller tabellnamn
     * @return class-string
     */
    public function resolve(string $classOrTable): string;
}
