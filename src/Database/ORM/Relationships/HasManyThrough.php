<?php

declare(strict_types=1);

namespace Radix\Database\ORM\Relationships;

use LogicException;
use Radix\Database\Connection;
use Radix\Database\ORM\Model;
use Radix\Database\ORM\ModelClassResolverInterface;
use Radix\Database\ORM\Relationships\Concerns\EnsuresModelClassLoaded;
use Radix\Support\StringHelper;

/**
 * HasManyThrough: En "has many" relation via en mellanmodell/tabell.
 *
 * Typiskt scenario:
 *  - Category (parent)
 *  - Subject (through) har column: category_id -> pekar på categories.id
 *  - Vote (related) har column: subject_id     -> pekar på subjects.id
 *
 * Nycklar:
 *  - $firstKey:    kolumn på through-tabellen som pekar till parent (ex: subjects.category_id)
 *  - $secondKey:   kolumn på related-tabellen som pekar till through (ex: votes.subject_id)
 *  - $localKey:    kolumn på parent som matchas mot $firstKey (default 'id') (ex: categories.id)
 *  - $secondLocal: kolumn på through som matchas mot $secondKey (default 'id') (ex: subjects.id)
 *
 * SQL som genereras (förenklad):
 *  SELECT r.*
 *  FROM related AS r
 *  JOIN through AS t ON t.secondLocal = r.secondKey
 *  WHERE t.firstKey = parent.localKeyValue
 *
 * Användning i en modell:
 *  public function votes(): HasManyThrough {
 *      return $this->hasManyThrough(Vote::class, Subject::class, 'category_id', 'subject_id', 'id', 'id');
 *  }
 *
 * Hämta poster:
 *  $category->votes()->get();   // array av Vote
 *  $category->votes()->first(); // första Vote eller null
 */
class HasManyThrough
{
    use EnsuresModelClassLoaded;

    private Connection $connection;
    private string $related;      // Klassnamn ELLER tabellnamn
    private string $through;      // Klassnamn ELLER tabellnamn
    private string $firstKey;     // subjects.category_id
    private string $secondKey;    // votes.subject_id
    private string $localKey;     // categories.id
    private string $secondLocal;  // subjects.id
    private ?Model $parent = null;

    public function __construct(
        Connection $connection,
        string $related,
        string $through,
        string $firstKey,
        string $secondKey,
        string $localKey = 'id',
        string $secondLocal = 'id',
        private readonly ?ModelClassResolverInterface $modelClassResolver = null
    ) {
        $this->connection   = $connection;
        $this->related      = $related;
        $this->through      = $through;
        $this->firstKey     = $firstKey;
        $this->secondKey    = $secondKey;
        $this->localKey     = $localKey;
        $this->secondLocal  = $secondLocal;
    }

    public function setParent(Model $parent): self
    {
        $this->parent = $parent;
        return $this;
    }

    /**
     * @return array<int,Model>
     */
    public function get(): array
    {
        if ($this->parent === null) {
            throw new LogicException('HasManyThrough parent saknas.');
        }

        /** @var Model $parent */
        $parent = $this->parent;

        $relatedClass = $this->resolveModelClass($this->related);
        $throughClass = $this->resolveModelClass($this->through);

        $this->ensureModelClassLoaded($relatedClass);
        $this->ensureModelClassLoaded($throughClass);

        /** @var class-string<Model> $relatedClass */
        /** @var class-string<Model> $throughClass */
        $relatedModel = new $relatedClass();
        $throughModel = new $throughClass();

        /** @var Model $relatedModel */
        /** @var Model $throughModel */
        $relatedTable = $relatedModel->getTable();
        $throughTable = $throughModel->getTable();

        $parentValue = $parent->getAttribute($this->localKey);
        if ($parentValue === null) {
            return [];
        }

        $sql = "SELECT r.*
                  FROM `$relatedTable` r
                  JOIN `$throughTable` t ON t.`$this->secondLocal` = r.`$this->secondKey`
                 WHERE t.`$this->firstKey` = ?";

        $rows = $this->connection->fetchAll($sql, [$parentValue]);

        return array_map(fn(array $data) => $this->createModelInstance($data, $relatedClass), $rows);
    }

    public function first(): ?Model
    {
        $all = $this->get();
        return $all[0] ?? null;
    }

    private function resolveModelClass(string $classOrTable): string
    {
        if (strpos($classOrTable, '\\') !== false) {
            return $classOrTable;
        }

        if ($this->modelClassResolver !== null) {
            return $this->modelClassResolver->resolve($classOrTable);
        }

        return 'App\\Models\\' . ucfirst(StringHelper::singularize($classOrTable));
    }

    /**
     * @param array<string, mixed> $data
     * @param class-string<Model>  $modelClass
     */
    private function createModelInstance(array $data, string $modelClass): Model
    {
        /** @var class-string<Model> $modelClass */
        $model = new $modelClass();
        $model->hydrateFromDatabase($data);
        $model->markAsExisting();
        return $model;
    }
}
