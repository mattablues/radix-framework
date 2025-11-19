<?php

declare(strict_types=1);

namespace Radix\Database\QueryBuilder\Concerns;

use Closure;
use InvalidArgumentException;
use LogicException;
use Radix\Database\QueryBuilder\QueryBuilder;

trait BuildsWhere
{
    public function where(string|Closure $column, string $operator = null, mixed $value = null, string $boolean = 'AND'): self
    {
        if ($column instanceof Closure) {
            $query = new \Radix\Database\QueryBuilder\QueryBuilder();
            $column($query);

            if (!empty($query->where)) {
                $this->where[] = [
                    'type' => 'nested',
                    'query' => $query,
                    'boolean' => $boolean,
                ];
                $this->mergeBindings($query);
            }
        } else {
            if (empty(trim($column))) {
                throw new InvalidArgumentException("The column name in WHERE clause cannot be empty.");
            }

            // Normalisera operator till en ren sträng (default '=')
            $operator ??= '=';
            $opUpper  = strtoupper($operator);

            $validOperators = ['=', '!=', '<', '<=', '>', '>=', 'LIKE', 'NOT LIKE', 'IN', 'NOT IN', 'BETWEEN', 'IS', 'IS NOT'];
            if (!in_array($opUpper, $validOperators, true)) {
                throw new InvalidArgumentException("Invalid operator '$operator' in WHERE clause.");
            }

            if ($opUpper === 'IS' || $opUpper === 'IS NOT') {
                $this->where[] = [
                    'type' => 'raw',
                    'column' => $this->wrapColumn($column),
                    'operator' => $opUpper,
                    'value' => null,
                    'boolean' => $boolean,
                ];
            } elseif ($value instanceof QueryBuilder) {
                $valueSql = '(' . $value->toSql() . ')';
                $this->mergeBindings($value);
                $this->where[] = [
                    'type' => 'subquery',
                    'column' => $this->wrapColumn($column),
                    'operator' => $opUpper,
                    'value' => $valueSql,
                    'boolean' => $boolean,
                ];
            } else {
                $this->addWhereBinding($value);
                $this->where[] = [
                    'type' => 'raw',
                    'column' => $this->wrapColumn($column),
                    'operator' => $opUpper,
                    'value' => '?',
                    'boolean' => $boolean,
                ];
            }
        }

        return $this;
    }

    /**
     * @param array<int, string|int|float|bool> $values
     */
    public function whereIn(string $column, array $values, string $boolean = 'AND'): self
    {
        if (empty($values)) {
            throw new InvalidArgumentException("Argumentet 'values' måste innehålla minst ett värde för whereIn.");
        }

        $placeholders = implode(', ', array_fill(0, count($values), '?'));

        $this->where[] = [
            'type' => 'list',
            'column' => $this->wrapColumn($column),
            'operator' => 'IN',
            'value' => "($placeholders)",
            'boolean' => $boolean,
        ];

        $this->addWhereBindings($values);

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
            if (!is_array($condition)) {
                return true;
            }

            /** @var array{
             *   type?: string,
             *   column?: string,
             *   operator?: string,
             *   boolean?: string
             * } $condition
             */

            return !(
                ($condition['type'] ?? null) === 'raw'
                && ($condition['column'] ?? null) === $this->wrapColumn($column)
                && ($condition['operator'] ?? null) === 'IS NULL'
                && ($condition['boolean'] ?? null) === $boolean
            );
        });

        foreach ($this->where as $condition) {
            if (!is_array($condition)) {
                continue;
            }

            /** @var array{
             *   type?: string,
             *   column?: string,
             *   operator?: string,
             *   boolean?: string
             * } $condition
             */

            if (
                ($condition['type'] ?? null) === 'raw'
                && ($condition['column'] ?? null) === $this->wrapColumn($column)
                && ($condition['operator'] ?? null) === 'IS NOT NULL'
                && ($condition['boolean'] ?? null) === $boolean
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

    /**
     * @param array<int, mixed> $values
     */
    public function whereNotIn(string $column, array $values, string $boolean = 'AND'): self
    {
        if (empty($values)) {
            throw new InvalidArgumentException("Argumentet 'values' måste innehålla minst ett värde för whereNotIn.");
        }

        $placeholders = implode(', ', array_fill(0, count($values), '?'));

        $this->where[] = [
            'type' => 'list',
            'column' => $this->wrapColumn($column),
            'operator' => 'NOT IN',
            'value' => "($placeholders)",
            'boolean' => $boolean,
        ];

        $this->addWhereBindings($values);
        return $this;
    }

    /**
     * @param array{0: mixed, 1: mixed} $range
     */
    public function whereBetween(string $column, array $range, string $boolean = 'AND'): self
    {
        if (count($range) !== 2) {
            throw new InvalidArgumentException('whereBetween kräver exakt två värden.');
        }

        $this->where[] = [
            'type' => 'between',
            'column' => $this->wrapColumn($column),
            'operator' => 'BETWEEN',
            'value' => '? AND ?',
            'boolean' => $boolean,
        ];

        $this->addWhereBindings([$range[0], $range[1]]);
        return $this;
    }

    /**
     * @param array{0: mixed, 1: mixed} $range
     */
    public function whereNotBetween(string $column, array $range, string $boolean = 'AND'): self
    {
        if (count($range) !== 2) {
            throw new InvalidArgumentException('whereNotBetween kräver exakt två värden.');
        }

        $this->where[] = [
            'type' => 'between',
            'column' => $this->wrapColumn($column),
            'operator' => 'NOT BETWEEN',
            'value' => '? AND ?',
            'boolean' => $boolean,
        ];

        $this->addWhereBindings([$range[0], $range[1]]);
        return $this;
    }

    // NYTT: whereColumn (kolumn till kolumn)
    public function whereColumn(string $left, string $operator, string $right, string $boolean = 'AND'): self
    {
        $this->where[] = [
            'type' => 'column',
            'column' => $this->wrapColumn($left),
            'operator' => $operator,
            'value' => $this->wrapColumn($right),
            'boolean' => $boolean,
        ];
        return $this;
    }

    // NYTT: whereExists / whereNotExists
    public function whereExists(QueryBuilder $sub, string $boolean = 'AND'): self
    {
        $this->addWhereBindings($sub->getBindings());

        $this->where[] = [
            'type' => 'exists',
            'operator' => 'EXISTS',
            'value' => '(' . $sub->toSql() . ')',
            'boolean' => $boolean,
        ];
        return $this;
    }

    public function whereNotExists(QueryBuilder $sub, string $boolean = 'AND'): self
    {
        $this->addWhereBindings($sub->getBindings());

        $this->where[] = [
            'type' => 'exists',
            'operator' => 'NOT EXISTS',
            'value' => '(' . $sub->toSql() . ')',
            'boolean' => $boolean,
        ];
        return $this;
    }

    /**
     * @param array<int, mixed> $bindings
     */
    public function whereRaw(string $sql, array $bindings = [], string $boolean = 'AND'): self
    {
        $this->where[] = [
            'type' => 'raw_sql',
            'sql' => $sql,
            'boolean' => $boolean,
        ];

        $this->addWhereBindings($bindings);
        return $this;
    }

    protected function buildWhere(): string
    {
        if (empty($this->where)) {
            return '';
        }

        $conditions = [];
        foreach ($this->where as $condition) {
            if (!is_array($condition) || !isset($condition['type']) || !is_string($condition['type'])) {
                throw new LogicException('Invalid where condition structure.');
            }

            /**
             * @var array{
             *   type: 'raw'|'list'|'subquery'|'between'|'column'|'exists'|'raw_sql'|'nested',
             *   column?: string,
             *   operator?: string,
             *   value?: string|null,
             *   boolean?: string,
             *   sql?: string,
             *   query?: QueryBuilder
             * } $condition
             */

            switch ($condition['type']) {
                case 'raw':
                case 'list':
                case 'subquery':
                    $operator = $condition['operator'] ?? '';
                    $operatorUpper = strtoupper($operator);

                    if (in_array($operatorUpper, ['IS', 'IS NOT'], true) && ($condition['value'] ?? null) === null) {
                        $boolean = $condition['boolean'] ?? '';
                        $column = $condition['column'] ?? '';

                        $conditions[] = trim(
                            "{$boolean} {$column} {$operator} NULL"
                        );
                    } else {
                        $boolean = $condition['boolean'] ?? '';
                        $column = $condition['column'] ?? '';
                        $value = $condition['value'] ?? '';

                        $conditions[] = trim(
                            "{$boolean} {$column} {$operator} {$value}"
                        );
                    }
                    break;

                case 'between':
                    $boolean = $condition['boolean'] ?? '';
                    $column = $condition['column'] ?? '';
                    $operator = $condition['operator'] ?? '';
                    $value = $condition['value'] ?? '';

                    $conditions[] = trim("{$boolean} {$column} {$operator} {$value}");
                    break;

                case 'column':
                    $boolean = $condition['boolean'] ?? '';
                    $column = $condition['column'] ?? '';
                    $operator = $condition['operator'] ?? '';
                    $value = $condition['value'] ?? '';

                    $conditions[] = trim("{$boolean} {$column} {$operator} {$value}");
                    break;

                case 'exists':
                    $boolean = $condition['boolean'] ?? '';
                    $operator = $condition['operator'] ?? '';
                    $value = $condition['value'] ?? '';

                    $conditions[] = trim("{$boolean} {$operator} {$value}");
                    break;

                case 'raw_sql':
                    $boolean = $condition['boolean'] ?? '';
                    $sql = $condition['sql'] ?? '';

                    $conditions[] = trim("{$boolean} ({$sql})");
                    break;

                case 'nested':
                    if (!isset($condition['query']) || !$condition['query'] instanceof QueryBuilder) {
                        throw new LogicException('Nested where requires a QueryBuilder instance.');
                    }

                    $nestedWhere = $condition['query']->buildWhere();
                    $nestedWhere = preg_replace('/^WHERE\s+/i', '', $nestedWhere);
                    $boolean = $condition['boolean'] ?? '';

                    $conditions[] = "{$boolean} ($nestedWhere)";
                    break;

                default:
                    throw new LogicException("Unknown condition type: {$condition['type']}");
            }
        }

        $sql = implode(' ', $conditions);
        return 'WHERE ' . preg_replace('/^(AND|OR)\s+/i', '', trim($sql));
    }
}
