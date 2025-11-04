<?php

declare(strict_types=1);

namespace Radix\Database\QueryBuilder\Concerns;

trait CaseExpressions
{
    /**
     * Bygg ett CASE-uttryck för SELECT-listan.
     *
     * $conditions schema:
     * [
     *   ['cond' => '`status` = ?', 'bindings' => ['active'], 'then' => "'A'"],
     *   ['cond' => '`status` = ?', 'bindings' => ['paused'], 'then' => "'P'"],
     * ]
     */
    public function caseWhen(array $conditions, ?string $else = null, ?string $alias = null): self
    {
        $parts = ['CASE'];
        foreach ($conditions as $c) {
            $cond = (string)($c['cond'] ?? '');
            $then = (string)($c['then'] ?? 'NULL');
            $bindings = (array)($c['bindings'] ?? []);
            foreach ($bindings as $b) {
                $this->addSelectBinding($b);
            }
            $parts[] = "WHEN ($cond) THEN $then";
        }
        if ($else !== null) {
            $parts[] = "ELSE $else";
        }
        $parts[] = 'END';
        $expr = implode(' ', $parts);
        $this->columns[] = $alias ? ($expr . ' AS ' . $this->wrapAlias($alias)) : $expr;
        return $this;
    }

    /**
     * ORDER BY CASE helper.
     *
     * $whenMap: ['admin' => 1, 'editor' => 2, ...] appliceras på wrapColumn($column)
     */
    public function orderByCase(string $column, array $whenMap, string $else = 'ZZZ', string $direction = 'ASC'): self
    {
        $wrapped = $this->wrapColumn($column);
        $parts = ["CASE $wrapped"];
        foreach ($whenMap as $value => $rank) {
            // bind value i order-bucket
            $this->addOrderBinding($value);
            $parts[] = "WHEN ? THEN " . (int)$rank;
        }
        // Tvinga ELSE som citerad sträng (matchar testets förväntan)
        $elseQuoted = "'" . str_replace("'", "''", (string)$else) . "'";
        $parts[] = "ELSE " . $elseQuoted;
        $parts[] = "END";
        $dir = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
        $this->orderBy[] = implode(' ', $parts) . " $dir";
        return $this;
    }
}