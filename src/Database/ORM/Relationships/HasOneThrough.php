<?php

declare(strict_types=1);

namespace Radix\Database\ORM\Relationships;

use Radix\Database\Connection;
use Radix\Database\ORM\Model;
use Radix\Support\StringHelper;

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

        // Säkerställ att båda klasserna ärver Model
        if (!is_subclass_of($relatedClass, Model::class)) {
            throw new \LogicException("HasOneThrough related class '$relatedClass' must extend " . Model::class . '.');
        }
        if (!is_subclass_of($throughClass, Model::class)) {
            throw new \LogicException("HasOneThrough through class '$throughClass' must extend " . Model::class . '.');
        }

        /** @var class-string<Model> $relatedClass */
        /** @var class-string<Model> $throughClass */
        $relatedModel = new $relatedClass();
        $throughModel = new $throughClass();

        /** @var Model $relatedModel */
        /** @var Model $throughModel */
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
        /** @var class-string<Model> $modelClass */
        $model = new $modelClass();
        $model->hydrateFromDatabase($data);
        $model->markAsExisting();
        return $model;
    }
}