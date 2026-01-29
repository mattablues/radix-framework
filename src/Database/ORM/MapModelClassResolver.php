<?php

declare(strict_types=1);

namespace Radix\Database\ORM;

final readonly class MapModelClassResolver implements ModelClassResolverInterface
{
    /**
     * @var array<string, class-string<Model>>
     */
    private array $mapLower;

    /**
     * @param array<string, class-string<Model>> $map
     */
    public function __construct(
        array $map,
        private ModelClassResolverInterface $fallback
    ) {
        $lower = [];
        foreach ($map as $k => $v) {
            if (is_string($k) && $k !== '') {
                $lower[strtolower($k)] = $v;
            }
        }
        $this->mapLower = $lower;
    }

    /**
     * @param string $classOrTable
     * @return class-string<Model>
     * @phpstan-return class-string<Model>
     */
    public function resolve(string $classOrTable): string
    {
        // FQCN -> returnera direkt (ingen autoload här)
        if (str_contains($classOrTable, '\\')) {
            /** @var class-string<Model> $fqcn */
            $fqcn = $classOrTable;
            return $fqcn;
        }

        $key = strtolower($classOrTable);
        if (isset($this->mapLower[$key])) {
            return $this->mapLower[$key];
        }

        return $this->fallback->resolve($classOrTable);
    }
}
