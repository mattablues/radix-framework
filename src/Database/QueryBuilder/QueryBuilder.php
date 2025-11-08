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

        if (is_array($rows)) {
            return new Collection($rows);
        }

        return $rows; // är redan Collection
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

    public function pluck(string $valueColumn, ?string $keyColumn = null): array
    {
        if ($keyColumn === null) {
            $this->select([$valueColumn]);
        } else {
            // Se till att båda kolumnerna hämtas
            $this->select([$valueColumn, $keyColumn]);
        }

        $rows = $this->connection->fetchAll($this->toSql(), $this->getBindings());

        if ($keyColumn === null) {
            return array_map(static function (array $row) use ($valueColumn) {
                return $row[$valueColumn] ?? null;
            }, $rows);
        }

        $out = [];
        foreach ($rows as $row) {
            $out[$row[$keyColumn] ?? null] = $row[$valueColumn] ?? null;
        }
        return $out;
    }

    // Hjälpare: finns/inte finns
    public function doesntExist(): bool
    {
        return !$this->exists();
    }

    // Hjälpare: första eller exception
    public function firstOrFail(): mixed
    {
        $model = $this->first();
        if ($model === null) {
            throw new \RuntimeException('No records found for firstOrFail().');
        }
        return $model;
    }

    // Villkorad chaining
    public function when(bool $condition, \Closure $then, ?\Closure $else = null): self
    {
        if ($condition) {
            $then($this);
        } elseif ($else !== null) {
            $else($this);
        }
        return $this;
    }

    // Tap/hook
    public function tap(\Closure $callback): self
    {
        $callback($this);
        return $this;
    }

    // Ordering sugar
    public function orderByDesc(string $column): self
    {
        return $this->orderBy($column, 'DESC');
    }

    public function latest(string $column = 'created_at'): self
    {
        return $this->orderByDesc($column);
    }

    public function oldest(string $column = 'created_at'): self
    {
        return $this->orderBy($column, 'ASC');
    }

    // Chunking: iterera i bitar och skicka Collection till callback
    public function chunk(int $size, \Closure $callback): void
    {
        if ($size <= 0) {
            throw new \InvalidArgumentException('Chunk size must be greater than 0.');
        }

        $page = 1;
        do {
            $clone = clone $this;
            $offset = ($page - 1) * $size;
            $results = $clone->limit($size)->offset($offset)->get();
            if ($results->isEmpty()) {
                break;
            }

            $callback($results, $page);
            $page++;
        } while ($results->count() === $size);
    }

    // Lazy: generator som yield:ar modeller (minnesvänligt)
    public function lazy(int $size = 1000): \Generator
    {
        $page = 1;
        while (true) {
            $clone = clone $this;
            $offset = ($page - 1) * $size;
            $batch = $clone->limit($size)->offset($offset)->get();
            if ($batch->isEmpty()) {
                break;
            }
            foreach ($batch as $model) {
                yield $model;
            }
            if ($batch->count() < $size) {
                break;
            }
            $page++;
        }
    }

    // Rå SQL med interpolerade bindningar för debug
    public function getRawSql(): string
    {
        return $this->debugSqlInterpolated();
    }

    public function dump(): self
    {
        echo $this->debugSqlInterpolated(), PHP_EOL;
        return $this;
    }

    /**
     * Returnera SQL med värden insatta för debug.
     *
     * @return string
     */
    public function debugSql(): string
    {
        // Visa parametriserad SQL (behåll frågetecken)
        return $this->toSql();
    }

    public function debugSqlInterpolated(): string
    {
        // Visa “prettified” SQL med insatta värden (endast för debug)
        $query = $this->toSql();
        foreach ($this->getBindings() as $binding) {
            if (is_string($binding)) {
                $replacement = "'" . addslashes($binding) . "'";
            } elseif (is_null($binding)) {
                $replacement = 'NULL';
            } elseif (is_bool($binding)) {
                $replacement = $binding ? '1' : '0';
            } elseif ($binding instanceof \DateTimeInterface) {
                $replacement = "'" . $binding->format('Y-m-d H:i:s') . "'";
            } else {
                $replacement = (string)$binding;
            }
            $query = preg_replace('/\?/', $replacement, $query, 1);
        }
        return $query;
    }
}