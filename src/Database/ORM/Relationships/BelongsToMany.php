<?php

declare(strict_types=1);

namespace Radix\Database\ORM\Relationships;

use Radix\Database\Connection;
use Radix\Database\ORM\Model;
use Radix\Support\StringHelper;

class BelongsToMany
{
    private Connection $connection;
    private string $relatedModelClass; // ändrat: spara klassnamn
    private string $pivotTable;
    private string $foreignPivotKey;
    private string $relatedPivotKey;
    private string $parentKeyName;
    private ?Model $parent = null;

    public function __construct(
        Connection $connection,
        string $relatedModel,
        string $pivotTable,
        string $foreignPivotKey,
        string $relatedPivotKey,
        string $parentKeyName
    ) {
        $this->connection = $connection;
        $this->relatedModelClass = $this->resolveModelClass($relatedModel);
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

    public function get(): array
    {
        // Parent-flöde
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

        $query = "
            SELECT related.*
            FROM `$relatedTable` AS related
            INNER JOIN `$this->pivotTable` AS pivot
              ON related.id = pivot.`$this->relatedPivotKey`
            WHERE pivot.`$this->foreignPivotKey` = ?";

        $results = $this->connection->fetchAll($query, [$parentValue]);

        return array_map(fn($data) => $this->createModelInstance($data, $this->relatedModelClass), $results);
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

    private function createModelInstance(array $data, string $classOrTable): Model
    {
        $modelClass = $this->resolveModelClass($classOrTable);
        $model = new $modelClass();
        $model->fill($data);
        $model->markAsExisting();

        return $model;
    }
}