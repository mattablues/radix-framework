<?php

declare(strict_types=1);

namespace Radix\Database\QueryBuilder\Concerns;

trait Windows
{
    /** @var string[] */
    protected array $windowExpressions = [];

    public function rowNumber(string $alias, array $partitionBy = [], array $orderBy = []): self
    {
        $this->windowExpressions[] = $this->buildWindowExpr('ROW_NUMBER()', $partitionBy, $orderBy, $alias);
        return $this;
    }

    public function rank(string $alias, array $partitionBy = [], array $orderBy = []): self
    {
        $this->windowExpressions[] = $this->buildWindowExpr('RANK()', $partitionBy, $orderBy, $alias);
        return $this;
    }

    public function denseRank(string $alias, array $partitionBy = [], array $orderBy = []): self
    {
        $this->windowExpressions[] = $this->buildWindowExpr('DENSE_RANK()', $partitionBy, $orderBy, $alias);
        return $this;
    }

    public function sumOver(string $column, string $alias, array $partitionBy = [], array $orderBy = []): self
    {
        $func = 'SUM(' . $this->wrapColumn($column) . ')';
        $this->windowExpressions[] = $this->buildWindowExpr($func, $partitionBy, $orderBy, $alias);
        return $this;
    }

    public function avgOver(string $column, string $alias, array $partitionBy = [], array $orderBy = []): self
    {
        $func = 'AVG(' . $this->wrapColumn($column) . ')';
        $this->windowExpressions[] = $this->buildWindowExpr($func, $partitionBy, $orderBy, $alias);
        return $this;
    }

    public function minOver(string $column, string $alias, array $partitionBy = [], array $orderBy = []): self
    {
        $func = 'MIN(' . $this->wrapColumn($column) . ')';
        $this->windowExpressions[] = $this->buildWindowExpr($func, $partitionBy, $orderBy, $alias);
        return $this;
    }

    public function maxOver(string $column, string $alias, array $partitionBy = [], array $orderBy = []): self
    {
        $func = 'MAX(' . $this->wrapColumn($column) . ')';
        $this->windowExpressions[] = $this->buildWindowExpr($func, $partitionBy, $orderBy, $alias);
        return $this;
    }

    public function windowRaw(string $expression, ?string $alias = null): self
    {
        $this->windowExpressions[] = $alias
            ? $expression . ' AS ' . $this->wrapAlias($alias)
            : $expression;
        return $this;
    }

    protected function compileWindowSelects(): array
    {
        return $this->windowExpressions ?? [];
    }

    private function buildWindowExpr(string $fn, array $partitionBy, array $orderBy, string $alias): string
    {
        $parts = [];
        if (!empty($partitionBy)) {
            $parts[] = 'PARTITION BY ' . implode(', ', array_map(fn($c) => $this->wrapColumn($c), $partitionBy));
        }
        if (!empty($orderBy)) {
            $orderList = array_map(function ($item) {
                if (is_string($item)) {
                    // default ASC
                    return $this->wrapColumn($item) . ' ASC';
                }
                // [$col, $dir]
                [$col, $dir] = $item;
                $dir = strtoupper($dir) === 'DESC' ? 'DESC' : 'ASC';
                return $this->wrapColumn($col) . ' ' . $dir;
            }, $orderBy);
            $parts[] = 'ORDER BY ' . implode(', ', $orderList);
        }

        $over = ' OVER (' . implode(' ', $parts) . ')';
        return $fn . $over . ' AS ' . $this->wrapAlias($alias);
    }
}