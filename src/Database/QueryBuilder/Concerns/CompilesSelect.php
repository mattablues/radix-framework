<?php

declare(strict_types=1);

namespace Radix\Database\QueryBuilder\Concerns;

use Radix\Database\QueryBuilder\QueryBuilder;

trait CompilesSelect
{
    public function select(array|string $columns = ['*']): self
    {
        $this->type = 'SELECT';

        if ($this->columns === ['*']) {
            $this->columns = [];
        }

        $this->columns = array_map(function ($column) {
            if (preg_match('/^(.+)\s+AS\s+(.+)$/i', $column, $matches)) {
                $columnPart = $this->wrapColumn(trim($matches[1]));
                $aliasPart = $this->wrapAlias(trim($matches[2]));
                return "$columnPart AS $aliasPart";
            }

            if (preg_match('/^([A-Z_]+)\((.*)\)\s+AS\s+(.+)$/i', $column, $matches)) {
                $function = $matches[1];
                $parameters = $matches[2];
                $alias = $matches[3];

                $wrappedParameters = implode(', ',
                    array_map([$this, 'wrapColumn'], array_map('trim', explode(',', $parameters))));
                $wrappedAlias = $this->wrapAlias($alias);

                return strtoupper($function) . "($wrappedParameters) AS $wrappedAlias";
            }

            return $this->wrapColumn($column);
        }, (array) $columns);

        return $this;
    }

    public function distinct(bool $value = true): self
    {
        $this->distinct = $value;
        return $this;
    }

    // NYTT: selectSub
    public function selectSub(QueryBuilder $sub, string $alias): self
    {
        if ($this->columns === ['*']) {
            $this->columns = [];
        }

        // Lägg subqueryns bindningar i select-bucket (Bindings-trait finns alltid)
        foreach ($sub->getBindings() as $b) {
            $this->addSelectBinding($b);
        }

        $this->columns[] = '(' . $sub->toSql() . ') AS ' . $this->wrapAlias($alias);
        return $this;
    }

    public function toSql(): string
    {
        if ($this->type === 'INSERT' || $this->type === 'UPDATE' || $this->type === 'DELETE') {
            return $this->compileMutationSql();
        }

        if ($this->type !== 'SELECT') {
            throw new \RuntimeException("Query type '$this->type' är inte implementerad.");
        }

        $distinct = $this->distinct ? 'DISTINCT ' : '';

        $columns = implode(', ', array_map(function ($col) {
            if (preg_match('/[A-Z]+\(/i', $col) || str_starts_with($col, 'COUNT') || str_contains($col, 'NOW') || str_starts_with($col, '(')) {
                return $col;
            }
            return $this->wrapColumn($col);
        }, $this->columns));

        $sql = "SELECT $distinct$columns FROM $this->table";

        if (!empty($this->joins)) {
            $sql .= ' ' . implode(' ', $this->joins);
        }

        $where = $this->buildWhere();
        if (!empty($where)) {
            $sql .= " $where";
        }

        if (!empty($this->groupBy)) {
            $sql .= ' GROUP BY ' . implode(', ', $this->groupBy);
        }

        if (!empty($this->having)) {
            $sql .= " HAVING $this->having";
        }

        if (!empty($this->orderBy)) {
            $sql .= ' ORDER BY ' . implode(', ', $this->orderBy);
        }

        if ($this->limit !== null) {
            $sql .= " LIMIT $this->limit";
        }

        if ($this->offset !== null) {
            $sql .= " OFFSET $this->offset";
        }

        if (!empty($this->unions)) {
            $sql .= ' ' . implode(' ', $this->unions);
        }

        $this->compileAllBindings();

        return $sql;
    }

    // OBS: Ingen compileMutationSql() här för att undvika krock med CompilesMutations.
}