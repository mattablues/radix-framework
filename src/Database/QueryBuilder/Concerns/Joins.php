<?php

declare(strict_types=1);

namespace Radix\Database\QueryBuilder\Concerns;

use Radix\Database\QueryBuilder\QueryBuilder;

trait Joins
{
    public function join(string $table, string $first, string $operator, string $second, string $type = 'INNER'): self
    {
        $this->joins[] = "$type JOIN " . $this->wrapColumn($table) . " ON " . $this->wrapColumn($first) . " $operator " . $this->wrapColumn($second);
        return $this;
    }

    public function leftJoin(string $table, string $first, string $operator, string $second): self
    {
        $this->joins[] = "LEFT JOIN " . $this->wrapColumn($table) . " ON " . $this->wrapColumn($first) . " $operator " . $this->wrapColumn($second);
        return $this;
    }

    public function fullJoin(string $table, string $first, string $operator, string $second): self
    {
        $this->joins[] = "FULL OUTER JOIN " . $this->wrapColumn($table) . " ON " . $this->wrapColumn($first) . " $operator " . $this->wrapColumn($second);
        return $this;
    }

    public function joinSub(
        QueryBuilder $subQuery,
        string $alias,
        string $first,
        string $operator,
        string $second,
        string $type = 'INNER'
    ): self {
        $subQuerySql = '(' . $subQuery->toSql() . ') AS ' . $this->wrapAlias($alias);
        $this->bindings = array_merge($this->bindings, $subQuery->getBindings());
        $this->joins[] = "$type JOIN $subQuerySql ON " . $this->wrapColumn($first) . " $operator " . $this->wrapColumn($second);
        return $this;
    }
}