<?php

declare(strict_types=1);

namespace Radix\Database\QueryBuilder\Concerns;

use Radix\Database\QueryBuilder\QueryBuilder;

trait BuildsWhere
{
    public function where(string|QueryBuilder|\Closure $column, string $operator = null, mixed $value = null, string $boolean = 'AND'): self
    {
        if ($column instanceof \Closure) {
            // Hanterar Closure som skickas för inkapslade villkor
            $query = new \Radix\Database\QueryBuilder\QueryBuilder(); // Skapa en ny instans av QueryBuilder
            $column($query);

            // Kontrollera om några WHERE-betingelser skapades i underfrågan
            if (!empty($query->where)) {
                $this->where[] = [
                    'type' => 'nested',
                    'query' => $query,
                    'boolean' => $boolean
                ];
                $this->bindings = array_merge($this->bindings, $query->getBindings());
            }
        } else {
            if (empty(trim($column))) {
                throw new \InvalidArgumentException("The column name in WHERE clause cannot be empty.");
            }

            $validOperators = ['=', '!=', '<', '<=', '>', '>=', 'LIKE', 'NOT LIKE', 'IN', 'NOT IN', 'BETWEEN', 'IS', 'IS NOT'];
            if (!in_array(strtoupper($operator), $validOperators, true)) {
                throw new \InvalidArgumentException("Invalid operator '{$operator}' in WHERE clause.");
            }

            // Hantera "IS NULL" och "IS NOT NULL"
            if (strtoupper($operator) === 'IS' || strtoupper($operator) === 'IS NOT') {
                $this->where[] = [
                    'type' => 'raw',
                    'column' => $this->wrapColumn($column),
                    'operator' => $operator,
                    'value' => null,
                    'boolean' => $boolean,
                ];
            } elseif ($value instanceof QueryBuilder) {
                // Subqueries stöds här
                $valueSql = '(' . $value->toSql() . ')';
                $this->bindings = array_merge($this->bindings, $value->getBindings());
                $this->where[] = [
                    'type' => 'subquery',
                    'column' => $this->wrapColumn($column),
                    'operator' => $operator,
                    'value' => $valueSql,
                    'boolean' => $boolean,
                ];
            } else {
                // Hantera direktvärden
                $this->bindings[] = $value;
                $this->where[] = [
                    'type' => 'raw',
                    'column' => $this->wrapColumn($column),
                    'operator' => $operator,
                    'value' => '?',
                    'boolean' => $boolean,
                ];
            }
        }

        return $this;
    }

    public function whereIn(string $column, array $values, string $boolean = 'AND'): self
    {
        if (empty($values)) {
            throw new \InvalidArgumentException("Argumentet 'values' måste innehålla minst ett värde för whereIn.");
        }

        $placeholders = implode(', ', array_fill(0, count($values), '?'));

        $this->where[] = [
            'type' => 'list',
            'column' => $this->wrapColumn($column),
            'operator' => 'IN',
            'value' => "($placeholders)",
            'boolean' => $boolean,
        ];

        $this->bindings = array_merge($this->bindings, $values);

        return $this;
    }

    public function orWhere(string $column, string $operator, mixed $value): self
    {
        return $this->where($column, $operator, $value, 'OR');
    }

    public function whereNull(string $column, string $boolean = 'AND'): self
    {
        if ($column === 'deleted_at' && $this->getWithSoftDeletes()) {
            return $this;
        }

        $this->where[] = [
            'type' => 'raw',
            'column' => $this->wrapColumn($column),
            'operator' => 'IS NULL',
            'value' => null,
            'boolean' => $boolean,
        ];

        return $this;
    }

    public function whereNotNull(string $column, string $boolean = 'AND'): self
    {
        $this->where = array_filter($this->where, function ($condition) use ($column, $boolean) {
            return !(
                $condition['type'] === 'raw' &&
                $condition['column'] === $this->wrapColumn($column) &&
                $condition['operator'] === 'IS NULL' &&
                $condition['boolean'] === $boolean
            );
        });

        foreach ($this->where as $condition) {
            if (
                $condition['type'] === 'raw' &&
                $condition['column'] === $this->wrapColumn($column) &&
                $condition['operator'] === 'IS NOT NULL' &&
                $condition['boolean'] === $boolean
            ) {
                return $this;
            }
        }

        $this->where[] = [
            'type' => 'raw',
            'column' => $this->wrapColumn($column),
            'operator' => 'IS NOT NULL',
            'value' => null,
            'boolean' => $boolean,
        ];

        return $this;
    }

    public function orWhereNotNull(string $column): self
    {
        return $this->whereNotNull($column, 'OR');
    }

    protected function buildWhere(): string
    {
        if (empty($this->where)) {
            return '';
        }

        $conditions = [];
        foreach ($this->where as $condition) {
            switch ($condition['type']) {
                case 'raw':
                case 'list':
                case 'subquery':
                    if (in_array(strtoupper($condition['operator']), ['IS', 'IS NOT'], true) && $condition['value'] === null) {
                        $conditions[] = trim("{$condition['boolean']} {$condition['column']} {$condition['operator']} NULL");
                    } else {
                        $conditions[] = trim("{$condition['boolean']} {$condition['column']} {$condition['operator']} {$condition['value']}");
                    }
                    break;

                case 'nested':
                    $nestedWhere = $condition['query']->buildWhere();
                    $nestedWhere = preg_replace('/^WHERE\s+/i', '', $nestedWhere);
                    $conditions[] = "{$condition['boolean']} ({$nestedWhere})";
                    break;

                default:
                    throw new \LogicException("Unknown condition type: {$condition['type']}");
            }
        }

        $sql = implode(' ', $conditions);
        return 'WHERE ' . preg_replace('/^(AND|OR)\s+/i', '', trim($sql));
    }
}