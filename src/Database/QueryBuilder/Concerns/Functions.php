<?php

declare(strict_types=1);

namespace Radix\Database\QueryBuilder\Concerns;

trait Functions
{
    public function count(string $column = '*', string $alias = 'count'): self
    {
        if ($this->columns === ['*']) {
            $this->columns = [];
        }
        $column = $this->wrapColumn($column);
        $this->columns[] = "COUNT($column) AS `" . addslashes($alias) . "`";
        return $this;
    }

    public function avg(string $column, string $alias = 'average'): self
    {
        if ($this->columns === ['*']) {
            $this->columns = [];
        }
        $column = $this->wrapColumn($column);
        $this->columns[] = "AVG($column) AS `$alias`";
        return $this;
    }

    public function sum(string $column, string $alias = 'sum'): self
    {
        if ($this->columns === ['*']) {
            $this->columns = [];
        }
        $column = $this->wrapColumn($column);
        $this->columns[] = "SUM($column) AS `$alias`";
        return $this;
    }

    public function max(string $column, string $alias = 'max'): self
    {
        if ($this->columns === ['*']) {
            $this->columns = [];
        }
        $column = $this->wrapColumn($column);
        $this->columns[] = "MAX($column) AS `$alias`";
        return $this;
    }

    public function min(string $column, string $alias = 'min'): self
    {
        if ($this->columns === ['*']) {
            $this->columns = [];
        }
        $column = $this->wrapColumn($column);
        $this->columns[] = "MIN($column) AS `$alias`";
        return $this;
    }

    public function addExpression(string $expression): self
    {
        $this->columns[] = $expression;
        return $this;
    }

    public function selectRaw(string $rawExpression): self
    {
        if ($this->columns === ['*']) {
            $this->columns = [];
        }
        $this->columns[] = $rawExpression;
        return $this;
    }

    /**
     * @param array<int, string> $columns
     */
    public function concat(array $columns, string $alias): self
    {
        if ($this->columns === ['*']) {
            $this->columns = [];
        }

        $wrappedColumns = array_map(function ($col) {
            if (preg_match("/^'.*'$/", $col)) {
                return $col;
            }
            return $this->wrapColumn($col);
        }, $columns);

        $concatExpression = 'CONCAT(' . implode(', ', $wrappedColumns) . ')';
        $this->columns[] = "$concatExpression AS `$alias`";
        return $this;
    }

    public function upper(string $column, string $alias = null): self
    {
        if ($this->columns === ['*']) {
            $this->columns = [];
        }
        $column = $this->wrapColumn($column);
        $alias = $alias ?: "upper_$column";
        $this->columns[] = "UPPER($column) AS `$alias`";
        return $this;
    }

    public function lower(string $column, string $alias = null): self
    {
        if ($this->columns === ['*']) {
            $this->columns = [];
        }
        $column = $this->wrapColumn($column);
        $alias = $alias ?: "lower_$column";
        $this->columns[] = "LOWER($column) AS `$alias`";
        return $this;
    }

    public function year(string $column, string $alias = null): self
    {
        if ($this->columns === ['*']) {
            $this->columns = [];
        }
        $column = $this->wrapColumn($column);
        $alias = $alias ?: "year_$column";
        $this->columns[] = "YEAR($column) AS `$alias`";
        return $this;
    }

    public function month(string $column, string $alias = null): self
    {
        if ($this->columns === ['*']) {
            $this->columns = [];
        }
        $column = $this->wrapColumn($column);
        $alias = $alias ?: "month_$column";
        $this->columns[] = "MONTH($column) AS `$alias`";
        return $this;
    }

    public function date(string $column, string $alias = null): self
    {
        if ($this->columns === ['*']) {
            $this->columns = [];
        }
        $column = $this->wrapColumn($column);
        $alias = $alias ?: "date_$column";
        $this->columns[] = "DATE($column) AS `$alias`";
        return $this;
    }

    public function round(string $column, int $decimals = 0, string $alias = null): self
    {
        $column = $this->wrapColumn($column);
        $alias = $alias ?: "round_$column";
        $this->columns[] = "ROUND($column, $decimals) AS `$alias`";
        return $this;
    }

    public function ceil(string $column, string $alias = null): self
    {
        if ($this->columns === ['*']) {
            $this->columns = [];
        }
        $column = $this->wrapColumn($column);
        $alias = $alias ?: "ceil_$column";
        $this->columns[] = "CEIL($column) AS `$alias`";
        return $this;
    }

    public function floor(string $column, string $alias = null): self
    {
        if ($this->columns === ['*']) {
            $this->columns = [];
        }
        $column = $this->wrapColumn($column);
        $alias = $alias ?: "floor_$column";
        $this->columns[] = "FLOOR($column) AS `$alias`";
        return $this;
    }

    public function abs(string $column, string $alias = null): self
    {
        if ($this->columns === ['*']) {
            $this->columns = [];
        }
        $column = $this->wrapColumn($column);
        $alias = $alias ?: "abs_$column";
        $this->columns[] = "ABS($column) AS `$alias`";
        return $this;
    }
}
