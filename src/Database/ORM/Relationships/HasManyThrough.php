<?php

declare(strict_types=1);

namespace Radix\Database\ORM\Relationships;

use Radix\Database\Connection;
use Radix\Database\ORM\Model;
use Radix\Support\StringHelper;

class HasManyThrough
{
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
        string $secondLocal = 'id'
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

    public function get(): array
    {
        if ($this->parent === null) {
            throw new \LogicException('HasManyThrough parent saknas.');
        }

        $relatedClass = $this->resolveModelClass($this->related);
        $throughClass = $this->resolveModelClass($this->through);

        $relatedModel = new $relatedClass();
        $throughModel = new $throughClass();

        $relatedTable = $relatedModel->getTable();
        $throughTable = $throughModel->getTable();

        $parentValue = $this->parent->getAttribute($this->localKey);
        if ($parentValue === null) {
            return [];
        }

        $sql = "SELECT r.*
                  FROM `{$relatedTable}` r
                  JOIN `{$throughTable}` t ON t.`{$this->secondLocal}` = r.`{$this->secondKey}`
                 WHERE t.`{$this->firstKey}` = ?";

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
        if (class_exists($classOrTable)) {
            return $classOrTable;
        }

        $singularClass = 'App\\Models\\' . ucfirst(StringHelper::singularize($classOrTable));
        if (class_exists($singularClass)) {
            return $singularClass;
        }

        throw new \Exception("Model class '$classOrTable' not found. Expected '$singularClass'.");
    }

    private function createModelInstance(array $data, string $classOrTable): Model
    {
        $modelClass = $this->resolveModelClass($classOrTable);
        $model = new $modelClass();
        $model->fill($data);
        $model->markAsExisting();
        return $model;
    }
}