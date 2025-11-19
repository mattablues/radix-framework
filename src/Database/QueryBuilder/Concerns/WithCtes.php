<?php

declare(strict_types=1);

namespace Radix\Database\QueryBuilder\Concerns;

use Radix\Database\QueryBuilder\QueryBuilder;

trait WithCtes
{
    /**
     * @var array<
     *     int,
     *     array{
     *         name: string,
     *         sql: string,
     *         bindings: array<int, mixed>,
     *         recursive: bool,
     *         columns: array<int, string>
     *     }
     * >
     */
    protected array $ctes = [];

    // Bytt namn för att undvika kollision med EagerLoad::with()
    public function withCte(string $name, QueryBuilder $sub): self
    {
        $this->ctes[] = [
            'name' => $name,
            'sql' => $sub->toSql(),
            'bindings' => $sub->getBindings(),
            'recursive' => false,
            'columns' => [],
        ];
        return $this;
    }

    /**
     * Bytt namn för symmetri
     *
     * @param array<int, mixed> $bindings
     */
    public function withCteRaw(string $raw, array $bindings = []): self
    {
        $this->ctes[] = [
            'name' => '',
            'sql' => $raw,
            'bindings' => $bindings,
            'recursive' => false,
            'columns' => [],
        ];
        return $this;
    }

    /**
     * @param array<int, string> $columns
     */
    public function withRecursive(string $name, QueryBuilder $anchor, QueryBuilder $recursive, array $columns = []): self
    {
        $union = $anchor->toSql() . ' UNION ALL ' . $recursive->toSql();
        $bindings = array_merge($anchor->getBindings(), $recursive->getBindings());

        $this->ctes[] = [
            'name' => $name,
            'sql' => $union,
            'bindings' => $bindings,
            'recursive' => true,
            'columns' => $columns,
        ];
        return $this;
    }

    protected function compileCtePrefix(): string
    {
        if (empty($this->ctes)) {
            return '';
        }

        $hasRecursive = (bool) array_reduce($this->ctes, fn($c, $cte) => $c || $cte['recursive'], false);
        $parts = [];
        foreach ($this->ctes as $cte) {
            if ($cte['name'] === '') {
                $parts[] = $cte['sql'];
                continue;
            }

            $cols = '';
            if (!empty($cte['columns'])) {
                $cols = ' (' . implode(', ', array_map(fn($c) => $this->wrapAlias($c), $cte['columns'])) . ')';
            }
            $parts[] = $this->wrapAlias($cte['name']) . $cols . ' AS (' . $cte['sql'] . ')';
        }

        $prefix = 'WITH ' . ($hasRecursive ? 'RECURSIVE ' : '') . implode(', ', $parts);
        return $prefix;
    }

    /**
     * @return array<int, mixed>
     */
    protected function compileCteBindings(): array
    {
        if (empty($this->ctes)) {
            return [];
        }

        $all = [];
        foreach ($this->ctes as $cte) {
            $all = array_merge($all, $cte['bindings']);
        }
        return $all;
    }
}
