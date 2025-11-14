<?php

declare(strict_types=1);

namespace Radix\Database\ORM\Relationships;

use Radix\Database\Connection;
use Radix\Database\ORM\Model;
use Radix\Support\StringHelper;

/**
 * HasOneThrough: En "has one" relation via en mellanmodell/tabell.
 *
 * Exempelmodellstruktur:
 *  - Category (parent)
 *  - Subject (through) har column: category_id
 *  - Vote (related) har column: subject_id
 *
 * Nycklar:
 *  - $firstKey:   kolumn på through-tabellen som pekar till parent (ex: subjects.category_id)
 *  - $secondKey:  kolumn på related-tabellen som pekar till through (ex: votes.subject_id)
 *  - $localKey:   kolumn på parent-modellen som matchas mot $firstKey (ex: categories.id)
 *  - $secondLocal: primärnyckel på through-tabellen som matchas mot $secondKey (ex: subjects.id)
 *
 * Användning:
 *  $category->hasOneThrough(Vote::class, Subject::class, 'category_id', 'subject_id', 'id', 'id')->first();
 */
class HasOneThrough
{
    private Connection $connection;
    private string $related;     // Klassnamn ELLER tabellnamn
    private string $through;     // Klassnamn ELLER tabellnamn
    private string $firstKey;    // t.ex. subjects.category_id
    private string $secondKey;   // t.ex. votes.subject_id
    private string $localKey;    // t.ex. categories.id
    private string $secondLocal; // t.ex. subjects.id
    private ?Model $parent = null;

    public function __construct(
        Connection $connection,
        string $related,
        string $through,
        string $firstKey,
        string $secondKey,
        string $localKey = 'id',
        string $secondLocal = 'id'
    ) {
        $this->connection  = $connection;
        $this->related     = $related;
        $this->through     = $through;
        $this->firstKey    = $firstKey;
        $this->secondKey   = $secondKey;
        $this->localKey    = $localKey;
        $this->secondLocal = $secondLocal;
    }

    public function setParent(Model $parent): self
    {
        $this->parent = $parent;
        return $this;
    }

    public function first(): ?Model
    {
        if ($this->parent === null) {
            throw new \LogicException('HasOneThrough parent saknas.');
        }

        $relatedClass = $this->resolveModelClass($this->related);
        $throughClass = $this->resolveModelClass($this->through);

        $relatedModel = new $relatedClass();
        $throughModel = new $throughClass();

        $relatedTable = $relatedModel->getTable();
        $throughTable = $throughModel->getTable();

        $parentValue = $this->parent->getAttribute($this->localKey);
        if ($parentValue === null) {
            return null;
        }

        $sql = "SELECT r.*
                  FROM `$relatedTable` r
                  JOIN `$throughTable` t ON t.`$this->secondLocal` = r.`$this->secondKey`
                 WHERE t.`$this->firstKey` = ?
                 LIMIT 1";

        $row = $this->connection->fetchOne($sql, [$parentValue]);
        if (!$row) {
            return null;
        }

        return $this->createModelInstance($row, $relatedClass);
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
        $model = new $modelClass();
        $model->hydrateFromDatabase($data);
        $model->markAsExisting();
        return $model;
    }
}