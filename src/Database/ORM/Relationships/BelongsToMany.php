<?php

declare(strict_types=1);

namespace Radix\Database\ORM\Relationships;

use Radix\Database\Connection;
use Radix\Database\ORM\Model;
use Radix\Support\StringHelper;

class BelongsToMany
{
    private Connection $connection;
    /** @var class-string<Model> */
    private string $relatedModelClass; // ändrat: spara klassnamn
    private string $pivotTable;
    private string $foreignPivotKey;
    private string $relatedPivotKey;
    private string $parentKeyName;
    private ?Model $parent = null;
    /** @var array<int, string> */
    private array $pivotColumns = [];
    private ?\Radix\Database\QueryBuilder\QueryBuilder $builder = null;

    public function __construct(
        Connection $connection,
        string $relatedModel,
        string $pivotTable,
        string $foreignPivotKey,
        string $relatedPivotKey,
        string $parentKeyName
    ) {
        $this->connection = $connection;
        $resolved = $this->resolveModelClass($relatedModel);
        if (!is_subclass_of($resolved, Model::class)) {
            throw new \LogicException("BelongsToMany related model '$resolved' must extend " . Model::class . '.');
        }
        /** @var class-string<Model> $resolved */
        $this->relatedModelClass = $resolved;
        $this->pivotTable = $pivotTable;
        $this->foreignPivotKey = $foreignPivotKey;
        $this->relatedPivotKey = $relatedPivotKey;
        $this->parentKeyName = $parentKeyName;
    }

    public function setParent(Model $parent): self
    {
        $this->parent = $parent;
        return $this;
    }

    // Ny: bygg en QueryBuilder för relaterade med JOIN pivot och standard WHERE på parent
    public function query(): \Radix\Database\QueryBuilder\QueryBuilder
    {
        if ($this->builder instanceof \Radix\Database\QueryBuilder\QueryBuilder) {
            return $this->builder;
        }

        /** @var Model $relatedInstance */
        $relatedInstance = new $this->relatedModelClass();
        $relatedTable = $relatedInstance->getTable();

        $qb = (new \Radix\Database\QueryBuilder\QueryBuilder())
            ->setConnection($this->connection)
            ->setModelClass($this->relatedModelClass)
            ->from("$relatedTable AS related")
            ->join($this->pivotTable . ' AS pivot', 'related.id', '=', "pivot.$this->relatedPivotKey");

        // WHERE pivot.foreignPivotKey = parent.id
        if ($this->parent !== null) {
            $parentValue = $this->parent->getAttribute($this->parentKeyName);
            if ($parentValue !== null) {
                $qb->where("pivot.$this->foreignPivotKey", '=', $parentValue);
            } else {
                // tomt resultat om parent saknar id
                $qb->where('1', '=', 0);
            }
        } else {
            // Backcompat: anta parentKeyName är ett värde
            $qb->where("pivot.$this->foreignPivotKey", '=', $this->parentKeyName);
        }

        // Kolumner: related.* + ev. pivot-aliased
        $columns = ['related.*'];
        foreach ($this->pivotColumns as $col) {
            $columns[] = "pivot.`$col` AS `pivot_$col`";
        }
        $qb->select($columns);

        $this->builder = $qb;
        return $this->builder;
    }

    public function withPivot(string ...$columns): self
    {
        $this->pivotColumns = array_values(array_unique(array_filter($columns, fn($c) => $c !== '')));
        // Om builder redan finns, uppdatera select-kolumnerna så pivot-fält inkluderas
        if ($this->builder instanceof \Radix\Database\QueryBuilder\QueryBuilder) {
            $cols = ['related.*'];
            foreach ($this->pivotColumns as $col) {
                $cols[] = "pivot.`$col` AS `pivot_$col`";
            }
            $this->builder->select($cols);
        }
        return $this;
    }

    /**
     * Hämta relaterade modeller.
     *
     * @return array<int, Model>
     */
    public function get(): array
    {
        // 1) Om query()-builder finns (används av eager loading med closure), kör den
        if ($this->builder instanceof \Radix\Database\QueryBuilder\QueryBuilder) {
            $modelsCollection = $this->builder->get(); // Collection<Model>

            /** @var array<int, Model> $models */
            $models = [];
            foreach ($modelsCollection as $m) {
                if (!$m instanceof Model) {
                    throw new \LogicException('BelongsToMany::get() expected Collection<Model>.');
                }

                // Mappa pivot_* alias till relation 'pivot'
                if (!empty($this->pivotColumns)) {
                    $pivotData = [];
                    foreach ($this->pivotColumns as $col) {
                        $alias = "pivot_$col";
                        $val = $m->getAttribute($alias);
                        if ($val !== null) {
                            $pivotData[$col] = $val;
                        }
                    }
                    if (!empty($pivotData)) {
                        $m->setRelation('pivot', $pivotData);
                    }
                }

                $models[] = $m;
            }

            return $models;
        }

        // 2) Fallback: manuell SQL
        if ($this->parent !== null) {
            $parentValue = $this->parent->getAttribute($this->parentKeyName);
            if ($parentValue === null) {
                return [];
            }
        } else {
            // Backwards compatibility: anta att parentKeyName redan är ett värde
            $parentValue = $this->parentKeyName;
        }

        /** @var Model $relatedInstance */
        $relatedInstance = new $this->relatedModelClass();
        $relatedTable = $relatedInstance->getTable();

        // Bygg select för pivot-kolumner
        $pivotSelects = [];
        foreach ($this->pivotColumns as $col) {
            $pivotSelects[] = "pivot.`$col` AS `pivot_$col`";
        }
        $pivotSelectSql = empty($pivotSelects) ? '' : ', ' . implode(', ', $pivotSelects);

        $query = "
            SELECT related.*{$pivotSelectSql}
            FROM `$relatedTable` AS related
            INNER JOIN `$this->pivotTable` AS pivot
              ON related.id = pivot.`$this->relatedPivotKey`
            WHERE pivot.`$this->foreignPivotKey` = ?";

        /** @var array<int, array<string, mixed>> $results */
        $results = $this->connection->fetchAll($query, [$parentValue]);

        /** @var array<int, Model> $models */
        $models = array_map(
            fn(array $data): Model => $this->createModelInstance($data, $this->relatedModelClass),
            $results
        );

        // Injicera pivot-data på modellerna om withPivot använts
        if (!empty($this->pivotColumns)) {
            foreach ($models as $i => $model) {
                $pivotData = [];
                foreach ($this->pivotColumns as $col) {
                    $key = "pivot_$col";
                    if (array_key_exists($key, $results[$i])) {
                        $pivotData[$col] = $results[$i][$key];
                    }
                }
                if (!empty($pivotData)) {
                    $model->setRelation('pivot', $pivotData);
                }
            }
        }

        return $models;
    }

    /**
     * Attach relaterade ids till pivot-tabellen.
     *
     * @param  int|string|array<int, int|array<string, mixed>>  $ids
     *        - 5
     *        - [1, 2, 3]
     *        - [1 => ['extra' => 'x'], 2 => ['extra' => 'y']]
     * @param  array<string, mixed>  $attributes
     */
    public function attach(int|string|array $ids, array $attributes = [], bool $ignoreDuplicates = true): void
    {
        $parentId = $this->requireParentId();
        $rows = $this->normalizeAttachInput($ids, $attributes);

        foreach ($rows as $relatedId => $attrs) {
            // Kolla om redan finns om vi vill ignorera dubletter
            if ($ignoreDuplicates && $this->existsInPivot($parentId, $relatedId)) {
                // Uppdatera endast extra attribut om de skickats
                if (!empty($attrs)) {
                    $set = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($attrs)));
                    $sql = "UPDATE `$this->pivotTable` SET $set WHERE `$this->foreignPivotKey` = ? AND `$this->relatedPivotKey` = ?";
                    $bindings = array_values($attrs);
                    $bindings[] = $parentId;
                    $bindings[] = $relatedId;
                    $this->connection->execute($sql, $bindings);
                }
                continue;
            }

            // INSERT
            $payload = array_merge($attrs, [
                $this->foreignPivotKey => $parentId,
                $this->relatedPivotKey => $relatedId,
            ]);

            $columns = array_keys($payload);
            $placeholders = implode(', ', array_fill(0, count($columns), '?'));
            $colsSql = '`' . implode('`, `', $columns) . '`';
            $sql = "INSERT INTO `$this->pivotTable` ($colsSql) VALUES ($placeholders)";
            $this->connection->execute($sql, array_values($payload));
        }
    }

    /**
     * Detach relaterade ids från pivot-tabellen.
     *
     * @param  int|array<int, int|array<string, mixed>>|null  $ids
     *        - null  => ta bort alla för parent
     *        - 5     => ta bort en
     *        - [1,2] => ta bort flera
     *        - [1 => ['ignored']] är tillåtet för backcompat, värdena används inte
     */
    public function detach(array|int $ids = null): void
    {
        $parentId = $this->requireParentId();

        if ($ids === null) {
            $sql = "DELETE FROM `$this->pivotTable` WHERE `$this->foreignPivotKey` = ?";
            $this->connection->execute($sql, [$parentId]);
            return;
        }

        // Tillåt [1,2] eller [1 => attrs]
        $ids = is_array($ids) ? $ids : [$ids];
        $normalized = [];
        foreach ($ids as $k => $v) {
            $normalized[] = is_int($k) ? (int)$v : (int)$k;
        }
        if (empty($normalized)) {
            return;
        }

        $in = implode(', ', array_fill(0, count($normalized), '?'));
        $sql = "DELETE FROM `$this->pivotTable` WHERE `$this->foreignPivotKey` = ? AND `$this->relatedPivotKey` IN ($in)";
        $this->connection->execute($sql, array_merge([$parentId], $normalized));
    }

    /**
     * @param array<int, int|array<string, mixed>> $idsWithAttributes
     */
    public function sync(array $idsWithAttributes, bool $detaching = true): void
    {
        $parentId = $this->requireParentId();

        $target = $this->normalizeAttachInput($idsWithAttributes);
        $existing = $this->getExistingRelatedIds($parentId);

        // Detach som saknas
        if ($detaching) {
            $toDetach = array_values(array_diff($existing, array_keys($target)));
            if (!empty($toDetach)) {
                $this->detach($toDetach);
            }
        }

        // Attach/Update
        foreach ($target as $relatedId => $attrs) {
            if (in_array($relatedId, $existing, true)) {
                if (!empty($attrs)) {
                    $set = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($attrs)));
                    $sql = "UPDATE `$this->pivotTable` SET $set WHERE `$this->foreignPivotKey` = ? AND `$this->relatedPivotKey` = ?";
                    $bindings = array_values($attrs);
                    $bindings[] = $parentId;
                    $bindings[] = $relatedId;
                    $this->connection->execute($sql, $bindings);
                }
            } else {
                $this->attach($relatedId, $attrs);
            }
        }
    }

    public function first(): ?Model
    {
        $results = $this->get();
        if (empty($results)) {
            return null;
        }
        return reset($results);
    }

    public function getPivotTable(): string
    {
        return $this->pivotTable;
    }

    public function getForeignPivotKey(): string
    {
        return $this->foreignPivotKey;
    }

    public function getRelatedModelClass(): string
    {
        return $this->relatedModelClass;
    }

    public function getParentKeyName(): string
    {
        return $this->parentKeyName;
    }

    private function resolveModelClass(string $classOrTable): string
    {
        if (class_exists($classOrTable)) {
            return $classOrTable;
        }

        $singularClass = 'App\\Models\\' . ucfirst(StringHelper::singularize($classOrTable));
        if (class_exists($singularClass)) {
            return $singularClass;
        }

        throw new \Exception("Model class '$classOrTable' not found. Expected '$singularClass'.");
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createModelInstance(array $data, string $classOrTable): Model
    {
        $modelClass = $this->resolveModelClass($classOrTable);

        if (!is_subclass_of($modelClass, Model::class)) {
            throw new \LogicException(
                "BelongsToMany relation resolved model class '$modelClass' must extend " . Model::class . "."
            );
        }

        /** @var class-string<Model> $modelClass */
        $model = new $modelClass();
        /** @var Model $model */
        $model->hydrateFromDatabase($data);
        $model->markAsExisting();

        return $model;
    }

    // --- Hjälpmetoder ---

    private function requireParentId(): int
    {
        if ($this->parent === null) {
            throw new \RuntimeException('Parent-modell måste vara satt via setParent() för pivot-operationer.');
        }
        $id = $this->parent->getAttribute($this->parentKeyName);
        if ($id === null) {
            throw new \RuntimeException('Parent-modellen saknar primärnyckel.');
        }

        if (!is_int($id) && !is_string($id)) {
            throw new \RuntimeException('Parent-id måste vara int eller string, fick: ' . get_debug_type($id));
        }

        return (int) $id;
    }

    /**
     * @param int|string|array<int, int|array<string, mixed>> $ids
     * @param array<string, mixed> $attributes
     * @return array<int, array<string, mixed>>
     */
    private function normalizeAttachInput(int|string|array $ids, array $attributes = []): array
    {
        if (!is_array($ids)) {
            // ids är int|string här enligt signaturen
            return [$this->normalizeSingleId($ids) => $attributes];
        }

        // Lista: [1,2] -> [1=>attrs,2=>attrs]
        if (array_keys($ids) === range(0, count($ids) - 1)) {
            $out = [];
            foreach ($ids as $id) {
                // enligt phpdoc: int|array<string,mixed>; vi accepterar bara int
                if (!is_int($id)) {
                    throw new \InvalidArgumentException(
                        'BelongsToMany ids-list måste innehålla heltal, fick ' . get_debug_type($id)
                    );
                }
                $out[$this->normalizeSingleId($id)] = $attributes;
            }
            return $out;
        }

        // Assoc: [id => attrs] (id enligt phpdoc: int)
        $out = [];
        foreach ($ids as $k => $v) {
            if (!is_int($k)) {
                throw new \InvalidArgumentException(
                    'BelongsToMany ids-nycklar måste vara heltal, fick ' . get_debug_type($k)
                );
            }
            $id = $this->normalizeSingleId($k);
            $out[$id] = is_array($v) ? $v : $attributes;
        }
        return $out;
    }

   private function existsInPivot(int $parentId, int $relatedId): bool
    {
        $sql = "SELECT 1 FROM `$this->pivotTable` WHERE `$this->foreignPivotKey` = ? AND `$this->relatedPivotKey` = ? LIMIT 1";
        $row = $this->connection->fetchOne($sql, [$parentId, $relatedId]);
        return $row !== null;
    }

     /**
     * @return array<int, int>
     */
    private function getExistingRelatedIds(int $parentId): array
    {
        $sql = "SELECT `$this->relatedPivotKey` AS rid FROM `$this->pivotTable` WHERE `$this->foreignPivotKey` = ?";
        /** @var array<int, array<string, mixed>> $rows */
        $rows = $this->connection->fetchAll($sql, [$parentId]);

        return array_map(
            /**
             * @param array<string, mixed> $r
             */
            function (array $r): int {
                $rid = $r['rid'] ?? null;

                if (is_int($rid)) {
                    return $rid;
                }
                if (is_string($rid)) {
                    $trimmed = trim($rid);
                    if ($trimmed !== '' && ctype_digit($trimmed)) {
                        return (int) $trimmed;
                    }
                }

                throw new \RuntimeException(
                    'BelongsToMany::getExistingRelatedIds(): ogiltig rid-typ: ' . get_debug_type($rid)
                );
            },
            $rows
        );
    }

    /**
     * Normalisera ett enskilt id till int.
     *
     * @param int|string $id
     */
    private function normalizeSingleId(int|string $id): int
    {
        if (is_int($id)) {
            return $id;
        }

        $trimmed = trim($id);
        if ($trimmed === '' || !ctype_digit($trimmed)) {
            throw new \InvalidArgumentException('BelongsToMany id måste vara ett heltal eller numerisk sträng, fick: ' . $id);
        }

        return (int) $trimmed;
    }
}