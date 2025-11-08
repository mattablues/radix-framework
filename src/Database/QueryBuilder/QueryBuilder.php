<?php

declare(strict_types=1);

namespace Radix\Database\QueryBuilder;

use Radix\Collection\Collection;
use Radix\Database\QueryBuilder\Concerns\Aggregates\WithAggregate;
use Radix\Database\QueryBuilder\Concerns\Aggregates\WithCount;
use Radix\Database\QueryBuilder\Concerns\Bindings;
use Radix\Database\QueryBuilder\Concerns\BuildsWhere;
use Radix\Database\QueryBuilder\Concerns\CaseExpressions;
use Radix\Database\QueryBuilder\Concerns\CompilesMutations;
use Radix\Database\QueryBuilder\Concerns\CompilesSelect;
use Radix\Database\QueryBuilder\Concerns\EagerLoad;
use Radix\Database\QueryBuilder\Concerns\Functions;
use Radix\Database\QueryBuilder\Concerns\GroupingSets;
use Radix\Database\QueryBuilder\Concerns\InsertSelect;
use Radix\Database\QueryBuilder\Concerns\Joins;
use Radix\Database\QueryBuilder\Concerns\JsonFunctions;
use Radix\Database\QueryBuilder\Concerns\Locks;
use Radix\Database\QueryBuilder\Concerns\Ordering;
use Radix\Database\QueryBuilder\Concerns\Pagination;
use Radix\Database\QueryBuilder\Concerns\SoftDeletes;
use Radix\Database\QueryBuilder\Concerns\Transactions;
use Radix\Database\QueryBuilder\Concerns\Unions;
use Radix\Database\QueryBuilder\Concerns\Windows;
use Radix\Database\QueryBuilder\Concerns\WithCtes;
use Radix\Database\QueryBuilder\Concerns\Wrapping;

class QueryBuilder extends AbstractQueryBuilder
{
    use WithCtes;
    use Locks;
    use Windows;
    use Wrapping;
    use BuildsWhere;
    use Bindings;
    use CompilesMutations;
    use CompilesSelect;
    use Joins;
    use Ordering;
    use Functions;
    use Unions;
    use Pagination;
    use SoftDeletes;
    use EagerLoad;
    use WithAggregate;
    use WithCount;
    use Transactions;
    use CaseExpressions;
    use InsertSelect;
    use JsonFunctions;
    use GroupingSets;

    protected string $type = 'SELECT';
    protected array $columns = ['*'];
    protected ?string $table = null;
    protected array $joins = [];
    protected array $where = [];
    protected array $groupBy = [];
    protected array $orderBy = [];
    protected ?string $having = null;
    protected ?int $limit = null;
    protected ?int $offset = null;
    protected array $bindings = [];
    protected array $unions = [];
    protected bool $distinct = false;
    protected ?string $modelClass = null;
    protected array $eagerLoadRelations = [];
    protected bool $withSoftDeletes = false;
    protected array $withCountRelations = [];
    protected array $withAggregateExpressions = [];
    protected ?array $upsertUnique = null;
    protected ?array $upsertUpdate = null;

    // Befintliga buckets (behåll namnen)
    protected array $bindingsSelect = [];
    protected array $bindingsWhere = [];
    protected array $bindingsJoin = [];
    protected array $bindingsHaving = [];
    protected array $bindingsOrder = [];
    protected array $bindingsUnion = [];
    protected array $bindingsMutation = [];

    public function setModelClass(string $modelClass): self
    {
        if (!class_exists($modelClass)) {
            throw new \InvalidArgumentException("Model class '$modelClass' does not exist.");
        }
        $this->modelClass = $modelClass;
        return $this;
    }

    public function first()
    {
        if (is_null($this->modelClass)) {
            throw new \LogicException("Model class is not set. Use setModelClass() before calling first().");
        }

        $this->limit(1);
        $results = $this->get(); // alltid Collection

        if ($results->isEmpty()) {
            return null;
        }

        $result = $results->first();

        if (!$result instanceof $this->modelClass) {
            $modelInstance = new $this->modelClass();
            $modelInstance->fill($result);
            $modelInstance->markAsExisting();
            return $modelInstance;
        }

        $result->markAsExisting();
        return $result;
    }

    public function get(): Collection
    {
        if (is_null($this->modelClass)) {
            throw new \LogicException("Model class is not set. Use setModelClass() before calling get().");
        }

        $rows = parent::get();
        return $rows instanceof Collection ? $rows : new Collection($rows);
    }

    /**
     * Hämta alla rader som assoc-arrayer (utan modell-hydrering).
     */
    public function fetchAllRaw(): array
    {
        if ($this->connection === null) {
            throw new \LogicException('No Connection instance has been set. Use setConnection() to assign a database connection.');
        }
        $sql = $this->toSql();
        return $this->connection->fetchAll($sql, $this->bindings);
    }

    /**
     * Hämta första raden som assoc-array (utan modell-hydrering) eller null.
     */
    public function fetchRaw(): ?array
    {
        if ($this->connection === null) {
            throw new \LogicException('No Connection instance has been set. Use setConnection() to assign a database connection.');
        }
        $sql = $this->toSql();
        return $this->connection->fetchOne($sql, $this->bindings);
    }

    public function from(string $table): self
    {
        $table = trim($table);
        if (empty($table)) {
            throw new \InvalidArgumentException("Table name cannot be empty.");
        }

        if (preg_match('/\s+AS\s+/i', $table)) {
            [$tableName, $alias] = preg_split('/\s+AS\s+/i', $table, 2);
            $this->table = $this->wrapColumn($tableName) . ' AS ' . $this->wrapAlias($alias);
        } else {
            $this->table = $this->wrapColumn($table);
        }

        return $this;
    }

    public function whereLike(string $column, string $value, string $boolean = 'AND'): self
    {
        return $this->where($column, 'LIKE', $value, $boolean);
    }

    public function limit(int $limit): self
    {
        $this->limit = $limit;
        return $this;
    }

    public function offset(int $offset): self
    {
        $this->offset = $offset;
        return $this;
    }

    // Hjälp: sätt samman alla buckets till $this->bindings innan körning
    protected function compileAllBindings(): void
    {
        $bindings = [];

        // CTE-bindningar först (traits finns alltid)
        $bindings = array_merge($bindings, $this->compileCteBindings());

        // Viktigt: mutation före where (för att få SET-bindningar före WHERE-bindningar)
        $bindings = array_merge(
            $bindings,
            $this->bindingsMutation ?? [],
            $this->bindingsWhere ?? [],
            $this->bindingsJoin ?? [],
            $this->bindingsHaving ?? [],
            $this->bindingsUnion ?? [],
            $this->bindingsSelect ?? [],
            $this->bindingsOrder ?? []
        );

        $this->bindings = $bindings;
    }

    public function value(string $column): mixed
    {
        $this->limit(1);
        $this->select([$column]);
        $row = $this->connection->fetchOne($this->toSql(), $this->bindings);
        if ($row === null) {
            return null;
        }
        // Hämta första nyckeln om alias/namn okänd
        $values = array_values($row);
        return $values[0] ?? null;
    }

    public function pluck(string $column, ?string $key = null): array
    {
        $this->select([$column]);
        $rows = $this->connection->fetchAll($this->toSql(), $this->getBindings());

        if ($key === null) {
            return array_map(static function (array $row) {
                $vals = array_values($row);
                return $vals[0] ?? null;
            }, $rows);
        }

        $out = [];
        foreach ($rows as $row) {
            $vals = array_values($row);
            $out[$row[$key] ?? null] = $vals[0] ?? null;
        }
        return $out;
    }
}