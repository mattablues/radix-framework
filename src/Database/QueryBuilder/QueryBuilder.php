<?php

declare(strict_types=1);

namespace Radix\Database\QueryBuilder;

use Radix\Collection\Collection;
use Radix\Database\ORM\Model;
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
    /**
     * @var array<int, string>|array<string, mixed>
     */
    protected array $columns = ['*'];
    protected ?string $table = null;
    /** @var array<int, mixed> */
    protected array $joins = [];
    /** @var array<int, mixed> */
    protected array $where = [];
    /** @var array<int, string> */
    protected array $groupBy = [];
    /** @var array<int, mixed> */
    protected array $orderBy = [];
    protected ?string $having = null;
    protected ?int $limit = null;
    protected ?int $offset = null;
    /** @var array<int, mixed> */
    protected array $bindings = [];
    /** @var array<int, mixed> */
    protected array $unions = [];
    protected bool $distinct = false;
    /**
     * @var class-string<Model>|null
     */
    protected ?string $modelClass = null;
    /** @var array<int, string> */
    protected array $eagerLoadRelations = [];
    protected bool $withSoftDeletes = false;
    /** @var array<int, string> */
    protected array $withCountRelations = [];
    /** @var array<int, string> */
    protected array $withAggregateExpressions = [];
    /** @var array<int, mixed>|null */
    protected ?array $upsertUnique = null;
    /** @var array<string, mixed>|null */
    protected ?array $upsertUpdate = null;

    // Befintliga buckets (behåll namnen)
    /** @var array<int, mixed> */
    protected array $bindingsSelect = [];
    /** @var array<int, mixed> */
    protected array $bindingsWhere = [];
    /** @var array<int, mixed> */
    protected array $bindingsJoin = [];
    /** @var array<int, mixed> */
    protected array $bindingsHaving = [];
    /** @var array<int, mixed> */
    protected array $bindingsOrder = [];
    /** @var array<int, mixed> */
    protected array $bindingsUnion = [];
    /** @var array<int, mixed> */
    protected array $bindingsMutation = [];

    public function setModelClass(string $modelClass): self
    {
        if (!class_exists($modelClass)) {
            throw new \InvalidArgumentException("Model class '$modelClass' does not exist.");
        }
        if (!is_subclass_of($modelClass, Model::class)) {
            throw new \InvalidArgumentException("Model class '$modelClass' must extend " . Model::class . ".");
        }
        $this->modelClass = $modelClass;
        return $this;
    }

    /**
     * @return Model|null
     */
    public function first(): ?Model
    {
        if ($this->modelClass === null) {
            throw new \LogicException("Model class is not set. Use setModelClass() before calling first().");
        }

        $this->limit(1);

        /** @var Collection $results */
        $results = $this->get(); // alltid Collection av modeller

        if ($results->isEmpty()) {
            return null;
        }

        /** @var mixed $result */
        $result = $results->first();

        // I praktiken ska detta alltid vara en Model, eftersom AbstractQueryBuilder::get()
        // hydratiserar till $this->modelClass som är en subklass av Model.
        if (!$result instanceof Model) {
            throw new \LogicException('QueryBuilder::first() expected instance of Model from Collection.');
        }

        /** @var Model $result */
        $result->markAsExisting();
        return $result;
    }

    /**
     * @return Collection<Model>
     */
    public function get(): Collection
    {
        if ($this->modelClass === null) {
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
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchAllRaw(): array
    {
        $sql = $this->toSql();
        return $this->getConnection()->fetchAll($sql, $this->bindings);

    }

    /**
     * Hämta första raden som assoc‑array (utan modell‑hydrering) eller null.
     *
     * @return array<string,mixed>|null
     */
    public function fetchRaw(): ?array
    {
        $sql = $this->toSql();
        return $this->getConnection()->fetchOne($sql, $this->bindings);
    }

    public function from(string $table): self
    {
        $table = trim($table);
        if (empty($table)) {
            throw new \InvalidArgumentException("Table name cannot be empty.");
        }

        if (preg_match('/\s+AS\s+/i', $table)) {
            $parts = preg_split('/\s+AS\s+/i', $table, 2);
            if ($parts === false) {
                throw new \RuntimeException("Failed to parse table alias from '{$table}'.");
            }

            [$tableName, $alias] = array_map('trim', $parts);

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
        $row = $this->getConnection()->fetchOne($this->toSql(), $this->bindings);
        if ($row === null) {
            return null;
        }
        // Hämta första nyckeln om alias/namn okänd
        $values = array_values($row);
        return $values[0] ?? null;
    }

    /**
     * @return array<int|string, mixed>
     */
    public function pluck(string $valueColumn, ?string $keyColumn = null): array
    {
        if ($keyColumn === null) {
            $this->select([$valueColumn]);
        } else {
            // Se till att båda kolumnerna hämtas
            $this->select([$valueColumn, $keyColumn]);
        }

        $rows = $this->getConnection()->fetchAll($this->toSql(), $this->getBindings());

        if ($keyColumn === null) {
            return array_map(static function (array $row) use ($valueColumn) {
                return $row[$valueColumn] ?? null;
            }, $rows);
        }

        $out = [];
        foreach ($rows as $row) {
            // $keyColumn är string här (ej null), men värdet i raden kan saknas
            $rawKey = $row[$keyColumn] ?? null;
            $key = $rawKey;

            // Normalisera nycklar till int|string om möjligt, annars hoppa över raden
            if (is_int($key) || is_string($key)) {
                $out[$key] = $row[$valueColumn] ?? null;
            }
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

    /**
     * @return \Generator<int, \Radix\Database\ORM\Model>
     */
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

    public function scalar(): mixed
    {
        $this->limit(1);
        $stmt = $this->execute();
        $row = $stmt->fetch(\PDO::FETCH_NUM);

        // PDO::fetch kan returnera array|false
        if (!is_array($row) || !array_key_exists(0, $row)) {
            return null;
        }

        return $row[0];
    }

    public function int(): ?int
    {
        $v = $this->scalar();
        if ($v === null) {
            return null;
        }

        if (is_int($v)) {
            return $v;
        }

        if (is_float($v)) {
            return (int) $v;
        }

        if (is_string($v)) {
            $trimmed = trim($v);
            if ($trimmed === '' || !is_numeric($trimmed)) {
                throw new \RuntimeException('Cannot convert scalar() result to int: ' . $v);
            }
            return (int) $trimmed;
        }

        if (is_bool($v)) {
            return $v ? 1 : 0;
        }

        // Fallback: ej konverterbart -> kasta exception
        throw new \RuntimeException('Cannot convert scalar() result to int: ' . get_debug_type($v));
    }

    public function float(): ?float
    {
        $v = $this->scalar();
        if ($v === null) {
            return null;
        }

        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }

        if (is_string($v) && is_numeric($v)) {
            return (float) $v;
        }

        if (is_bool($v)) {
            return $v ? 1.0 : 0.0;
        }

        if ($v instanceof \DateTimeInterface) {
            // t.ex. sekunder sedan epoch som float
            return (float) $v->getTimestamp();
        }

        // Fallback: ej konverterbart -> kasta exception
        throw new \RuntimeException('Cannot convert scalar() result to float: ' . get_debug_type($v));
    }

    public function string(): ?string
    {
        $v = $this->scalar();
        if ($v === null) {
            return null;
        }

        if (is_string($v)) {
            return $v;
        }

        if (is_int($v) || is_float($v)) {
            return (string) $v;
        }

        if (is_bool($v)) {
            return $v ? '1' : '0';
        }

        if ($v instanceof \DateTimeInterface) {
            return $v->format('Y-m-d H:i:s');
        }

        // Fallback: försök serialisera andra typer till JSON-sträng
        $encoded = json_encode($v);
        return $encoded !== false ? $encoded : '';
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
        $bindings = $this->getBindings();

        // Ersätt första förekomsten av ? i taget utan regex (snabbare och tydligare)
        foreach ($bindings as $binding) {
            if (is_string($binding)) {
                $replacement = "'" . addslashes($binding) . "'";
            } elseif ($binding === null) {
                $replacement = 'NULL';
            } elseif (is_bool($binding)) {
                $replacement = $binding ? '1' : '0';
            } elseif (is_int($binding) || is_float($binding)) {
                $replacement = (string) $binding;
            } elseif ($binding instanceof \DateTimeInterface) {
                $replacement = "'" . $binding->format('Y-m-d H:i:s') . "'";
            } else {
                // Sista fallback: json_encode andra typer, eller 'NULL' om det misslyckas
                $encoded = json_encode($binding);
                $replacement = $encoded !== false ? "'" . addslashes($encoded) . "'" : 'NULL';
            }

            $pos = strpos($query, '?');
            if ($pos === false) {
                break;
            }
            $query = substr($query, 0, $pos) . $replacement . substr($query, $pos + 1);
        }

        return $query;
    }
}