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
        $this->bindings[] = $value;
        return $this;
    }
}