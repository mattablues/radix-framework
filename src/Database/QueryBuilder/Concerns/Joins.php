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

    public function rightJoin(string $table, string $first, string $operator, string $second): self
    {
        $this->joins[] = "RIGHT JOIN " . $this->wrapColumn($table) . " ON " . $this->wrapColumn($first) . " $operator " . $this->wrapColumn($second);
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
        $this->mergeBindings($subQuery);
        $this->joins[] = "$type JOIN $subQuerySql ON " . $this->wrapColumn($first) . " $operator " . $this->wrapColumn($second);
        return $this;
    }

    /**
     * @param array<int, mixed> $bindings
     */
    public function joinRaw(string $raw, array $bindings = []): self
    {
        $this->joins[] = $raw;
        // Lägg direkt i join-bucket (Bindings-trait finns alltid)
        $this->addJoinBindings($bindings);
        return $this;
    }
}