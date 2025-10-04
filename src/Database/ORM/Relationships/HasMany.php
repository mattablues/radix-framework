<?php

declare(strict_types=1);

namespace Radix\Database\ORM\Relationships;

use Radix\Database\Connection;
use Radix\Database\ORM\Model;
use Radix\Support\StringHelper;

class HasMany
{
    private Connection $connection;
    private string $modelClass;
    private string $foreignKey;
    private string $localKeyName;
    private ?Model $parent = null;

    public function __construct(Connection $connection, string $modelClass, string $foreignKey, string $localKeyName)
    {
        $this->connection = $connection;

        if (!class_exists($modelClass)) {
            throw new \Exception("Class '$modelClass' not found for HasMany relation.");
        }

        $this->modelClass = $modelClass;
        $this->foreignKey = $foreignKey;
        $this->localKeyName = $localKeyName;
    }

    public function setParent(Model $parent): self
    {
        $this->parent = $parent;
        return $this;
    }

    public function get(): array
    {
        $modelClass = $this->resolveModelClass($this->modelClass);
        $modelInstance = new $modelClass();
        $table = $modelInstance->getTable();

        if ($this->parent === null) {
            throw new \LogicException("HasMany parent model is not set. Ensure the parent model is hydrated before loading relation.");
        }

        $localValue = $this->parent->getAttribute($this->localKeyName);
        if ($localValue === null) {
            return [];
        }

        $query = "SELECT * FROM `$table` WHERE `$this->foreignKey` = ?";
        $results = $this->connection->fetchAll($query, [$localValue]);

        return array_map(fn($data) => $this->createModelInstance($data, $modelClass), $results);
    }

    public function first(): ?Model
    {
        $results = $this->get();
        if (empty($results)) {
            return null;
        }
        return reset($results);
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