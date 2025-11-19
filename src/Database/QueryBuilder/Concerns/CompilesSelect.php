<?php

declare(strict_types=1);

namespace Radix\Database\QueryBuilder\Concerns;

use Radix\Database\QueryBuilder\QueryBuilder;
use RuntimeException;
use Stringable;

trait CompilesSelect
{
    /**
     * @param array<int, string> $columns
     */
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

                $wrappedParameters = implode(
                    ', ',
                    array_map([$this, 'wrapColumn'], array_map('trim', explode(',', $parameters)))
                );
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
            $cte = $this->compileCtePrefix();
            $sql = (is_string($this->mutationSql ?? null) && $this->mutationSql !== '')
                ? $this->mutationSql
                : $this->compileMutationSql();

            if ($cte !== '') {
                $sql = $cte . ' ' . $sql;
            }
            return $sql;
        }

        if ($this->type !== 'SELECT') {
            throw new RuntimeException("Query type '$this->type' är inte implementerad.");
        }

        // Window expressions
        $windowExpr = $this->compileWindowSelects();
        if (!empty($windowExpr)) {
            $this->columns = array_merge($this->columns, $windowExpr);
        }

        $distinct = $this->distinct ? 'DISTINCT ' : '';

        $columns = implode(', ', array_map(function ($col) {
            if (!is_string($col)) {
                // Tillåt Stringable, annars kasta tydligt fel så vi inte jobbar på mixed
                if ($col instanceof Stringable) {
                    $colStr = (string) $col;
                } else {
                    throw new RuntimeException('Ogiltig kolumntyp i SELECT: ' . get_debug_type($col));
                }
            } else {
                $colStr = $col;
            }

            // Rå uttryck / funktioner som inte ska wrappas
            if (
                preg_match('/[A-Z]+\(/i', $colStr) === 1
                || str_starts_with($colStr, 'COUNT')
                || str_contains($colStr, 'NOW')
                || str_starts_with($colStr, '(')
            ) {
                return $colStr;
            }

            // Vanlig kolumn -> wrappa
            return $this->wrapColumn($colStr);
        }, $this->columns));

        $ctePrefix = $this->compileCtePrefix();
        $prefix = $ctePrefix !== '' ? $ctePrefix . ' ' : '';

        $sql = $prefix . "SELECT $distinct$columns FROM $this->table";

        if (!empty($this->joins)) {
            $sql .= ' ' . implode(' ', $this->joins);
        }

        // Standard WHERE byggd via BuildsWhere
        $where = $this->buildWhere();
        $hasStandardWhere = !empty($where);

        // Rå WHERE-sträng (utan parenteser)
        $rawWhere = is_string($this->whereRawString ?? null) ? trim((string) $this->whereRawString) : '';

        if ($hasStandardWhere && $rawWhere !== '') {
            $sql .= " $where AND " . $rawWhere;
        } elseif ($hasStandardWhere) {
            $sql .= " $where";
        } elseif ($rawWhere !== '') {
            $sql .= " WHERE " . $rawWhere;
        }

        // GROUP BY / ROLLUP / GROUPING SETS
        if (!empty($this->groupBy)) {
            $group = $this->compileGroupByClause();
            if ($group !== '') {
                $sql .= ' ' . $group;
            }
        } elseif (!empty($this->groupingSets)) {
            $group = $this->compileGroupByClause();
            if ($group !== '') {
                $sql .= ' ' . $group;
            }
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

        if ($this->lockMode !== null) {
            $sql .= $this->compileLockSuffix();
        }

        $this->compileAllBindings();

        return $sql;
    }

    // OBS: Ingen compileMutationSql() här för att undvika krock med CompilesMutations.
}
