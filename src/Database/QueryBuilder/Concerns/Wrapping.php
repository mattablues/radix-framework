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
            return $this->wrapAlias($table) . '.' . "`{$col}`";
        }

        return "`{$column}`";
    }

    protected function wrapAlias(string $alias): string
    {
        return preg_match('/^`.*`$/', $alias) ? $alias : "`$alias`";
    }

    public function testWrapColumn(string $column): string
    {
        return $this->wrapColumn($column);
    }

    public function testWrapAlias(string $alias): string
    {
        return $this->wrapAlias($alias);
    }
}