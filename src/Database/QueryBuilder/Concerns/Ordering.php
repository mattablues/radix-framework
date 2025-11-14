<?php

declare(strict_types=1);

namespace Radix\Database\QueryBuilder\Concerns;

trait Ordering
{
    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $this->orderBy[] = $this->wrapColumn($column) . " $direction";
        return $this;
    }

    public function groupBy(string ...$columns): self
    {
        foreach ($columns as $column) {
            $this->groupBy[] = $this->wrapColumn($column);
        }
        return $this;
    }

    public function having(string $column, string $operator, mixed $value): self
    {
        $formattedColumn = $this->wrapAlias($column);
        $this->having = "$formattedColumn $operator ?";
        $this->addHavingBinding($value);
        return $this;
    }

    /**
     * @param array<int, mixed> $bindings
     */
    public function havingRaw(string $expression, array $bindings = []): self
    {
        $this->having = $expression;
        // Lägg direkt i having-bucket
        $this->addHavingBindings($bindings);
        return $this;
    }

    public function orderByRaw(string $expression): self
    {
        $this->orderBy[] = $expression;
        return $this;
    }

    public function orderByNullsLast(string $column, string $direction = 'ASC'): self
    {
        $this->orderBy[] = $this->wrapColumn($column) . " $direction NULLS LAST";
        return $this;
    }

    public function orderByNullsFirst(string $column, string $direction = 'ASC'): self
    {
        $this->orderBy[] = $this->wrapColumn($column) . " $direction NULLS FIRST";
        return $this;
    }
}