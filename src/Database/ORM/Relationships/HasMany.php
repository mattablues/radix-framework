<?php

declare(strict_types=1);

namespace Radix\Database\ORM\Relationships;

use Radix\Database\Connection;
use Radix\Database\ORM\Model;
use Radix\Database\QueryBuilder\QueryBuilder;
use Radix\Support\StringHelper;

class HasMany
{
    private Connection $connection;
    private string $modelClass;
    private string $foreignKey;
    private string $localKeyName;
    private ?Model $parent = null;
    private ?QueryBuilder $builder = null;

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

    public function query(): QueryBuilder
    {
        if ($this->builder instanceof QueryBuilder) {
            return $this->builder;
        }

        /** @var Model $instance */
        $instance = new $this->modelClass();
        $table = $instance->getTable();

        $qb = (new QueryBuilder())
            ->setConnection($this->connection)
            ->setModelClass($this->modelClass)
            ->from($table);

        if ($this->parent !== null) {
            $value = $this->parent->getAttribute($this->localKeyName);
            if ($value !== null) {
                $qb->where($this->foreignKey, '=', $value);
            }
        }

        $this->builder = $qb;
        return $this->builder;
    }

    public function get(): array
    {
        if ($this->builder instanceof QueryBuilder) {
            $col = $this->builder->get();
            return $col->toArray();
        }

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
        return $results[0];
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
        $model->hydrateFromDatabase($data);
        $model->markAsExisting();
        return $model;
    }
}