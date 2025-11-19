<?php

declare(strict_types=1);

namespace Radix\Database\QueryBuilder\Concerns;

trait GroupingSets
{
    protected bool $useRollup = false;
    /** @var array<int, array<int, string>> */
    protected array $groupingSets = [];

    /**
     * @param array<int, string> $groupBy
     */
    public function rollup(array $groupBy): self
    {
        // För MySQL: GROUP BY cols WITH ROLLUP
        $this->groupBy = array_map([$this, 'wrapColumn'], $groupBy);
        $this->useRollup = true;
        return $this;
    }

    /**
     * @param array<int, array<int, string>> $sets  // lista av listor av kolumnnamn
     */
    public function groupingSets(array $sets): self
    {
        // För Postgres (framtid). Vi sparar sets; rendering kan vara no-op i MySQL/SQLite.
        $this->groupingSets = array_map(
            fn(array $s) => array_map([$this, 'wrapColumn'], $s),
            $sets
        );
        return $this;
    }

    protected function compileGroupByClause(): string
    {
        if (empty($this->groupBy) && empty($this->groupingSets)) {
            return '';
        }

        if (!empty($this->groupingSets)) {
            // Postgres-style: GROUP BY GROUPING SETS ((a,b),(a),(b),())
            $setsSql = array_map(
                fn(array $s) => '(' . implode(', ', $s) . ')',
                $this->groupingSets
            );
            return 'GROUP BY GROUPING SETS ' . '(' . implode(', ', $setsSql) . ')';
        }

        $base = 'GROUP BY ' . implode(', ', $this->groupBy);
        if ($this->useRollup) {
            return $base . ' WITH ROLLUP';
        }
        return $base;
    }
}
