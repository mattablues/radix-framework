<?php

declare(strict_types=1);

namespace Radix\Database\QueryBuilder\Concerns;

trait Wrapping
{
    protected function wrapColumn(string $column): string
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_.]*$/', $column)) {
            return $column;
        }

        if (str_contains($column, '.')) {
            [$table, $col] = explode('.', $column, 2);
            return $this->wrapAlias($table) . '.' . "`$col`";
        }

        return "`$column`";
    }

    protected function wrapAlias(string $alias): string
    {
        return preg_match('/^`.*`$/', $alias) ? $alias : "`$alias`";
    }

    protected function wrapTable(string $table): string
    {
        return $this->wrapAlias($table);
    }

    // ... existing code ...
    public function testWrapColumn(string $column): string
    {
        return $this->wrapColumn($column);
    }

    public function testWrapAlias(string $alias): string
    {
        return $this->wrapAlias($alias);
    }

    // Hjälp för att kunna sätta FROM som raw (subquery/cte)
    public function fromRaw(string $raw): self
    {
        $this->table = $raw;
        return $this;
    }
}