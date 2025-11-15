<?php

declare(strict_types=1);

namespace Radix\Database\ORM\Relationships;

use Radix\Database\Connection;
use Radix\Database\ORM\Model;
use Radix\Support\StringHelper;

class HasMany
{
    private Connection $connection;
    /** @var class-string<Model> */
    private string $modelClass;
    private string $foreignKey;
    private string $localKeyName;
    private ?Model $parent = null;

    public function __construct(Connection $connection, string $modelClass, string $foreignKey, string $localKeyName)
    {
        $resolvedClass = $this->resolveModelClass($modelClass);
        if (!class_exists($resolvedClass) || !is_subclass_of($resolvedClass, Model::class)) {
            throw new \Exception("Model class '$resolvedClass' must exist and extend " . Model::class . '.');
        }

        $this->connection   = $connection;
        /** @var class-string<Model> $resolvedClass */
        $this->modelClass   = $resolvedClass;
        $this->foreignKey   = $foreignKey;
        $this->localKeyName = $localKeyName;
    }

    public function setParent(Model $parent): self
    {
        $this->parent = $parent;
        return $this;
    }

    /**
     * @return array<int, Model>
     */
    public function get(): array
    {
        // Hämta local key-värde från parent om satt, annars använd localKeyName som värde (backcompat)
        if ($this->parent !== null) {
            $localValue = $this->parent->getAttribute($this->localKeyName);
            if ($localValue === null) {
                return [];
            }
        } else {
            $localValue = $this->localKeyName;
        }

        /** @var class-string<Model> $modelClass */
        $modelClass   = $this->modelClass;
        $modelInstance = new $modelClass();
        /** @var Model $modelInstance */
        $table        = $modelInstance->getTable();

        $sql = "SELECT * FROM `$table` WHERE `$this->foreignKey` = ?";
        /** @var array<int, array<string, mixed>> $rows */
        $rows = $this->connection->fetchAll($sql, [$localValue]);

        /** @var array<int, Model> $results */
        $results = [];
        foreach ($rows as $row) {
            $results[] = $this->createModelInstance($row, $this->modelClass);
        }

        return $results;
    }

    public function first(): ?Model
    {
        $rows = $this->get();
        if ($rows === []) {
            return null;
        }
        return $rows[0];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createModelInstance(array $data, string $classOrTable): Model
    {
        $modelClass = $this->resolveModelClass($classOrTable);

        if (!is_subclass_of($modelClass, Model::class)) {
            throw new \LogicException(
                "HasMany relation resolved model class '$modelClass' must extend " . Model::class . '.'
            );
        }

        /** @var class-string<Model> $modelClass */
        $model = new $modelClass();
        /** @var Model $model */
        $model->hydrateFromDatabase($data);
        $model->markAsExisting();

        if ($this->parent !== null) {
            // Koppla tillbaka parent som relation
            $model->setRelation(
                strtolower((new \ReflectionClass($this->parent))->getShortName()),
                $this->parent
            );
        }

        return $model;
    }

    /**
     * Hjälpmetod för att lösa modellklass från klassnamn eller tabellnamn.
     */
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
}