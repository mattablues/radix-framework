<?php

declare(strict_types=1);

namespace Radix\Database\ORM;

final readonly class MapModelClassResolver implements ModelClassResolverInterface
{
    /**
     * @var array<string, class-string>
     */
    private array $mapLower;

    /**
     * @param array<array-key, class-string> $map
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

    public function resolve(string $classOrTable): string
    {
        // Om någon redan skickar in en FQCN som existerar: respektera den
        if (class_exists($classOrTable)) {
            return $classOrTable;
        }

        $key = strtolower($classOrTable);
        if (isset($this->mapLower[$key])) {
            return $this->mapLower[$key];
        }

        return $this->fallback->resolve($classOrTable);
    }
}
