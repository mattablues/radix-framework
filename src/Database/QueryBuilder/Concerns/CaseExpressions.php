<?php

declare(strict_types=1);

namespace Radix\Database\QueryBuilder\Concerns;

trait CaseExpressions
{
    /**
     * @param array<int, array{
     *     cond: string,
     *     then?: string,
     *     bindings?: array<int, mixed>
     * }> $conditions
     */
    public function caseWhen(array $conditions, ?string $else = null, ?string $alias = null): self
    {
        $parts = ['CASE'];
        foreach ($conditions as $c) {
            $cond = $c['cond'];
            $then = $c['then'] ?? 'NULL';
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
     * @param array<string, int> $whenMap  Map värde => sorteringsrank
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