<?php

declare(strict_types=1);

namespace Radix\Database\ORM\Relationships;

use Radix\Database\Connection;
use Radix\Database\ORM\Model;
use Radix\Support\StringHelper;

class HasOne
{
    private Connection $connection;
    private string $modelClass;
    private string $foreignKey;
    private string $localKeyName;
    private ?Model $parent = null;

    public function __construct(Connection $connection, string $modelClass, string $foreignKey, string $localKeyName)
    {
        $resolvedClass = $this->resolveModelClass($modelClass);
        if (!class_exists($resolvedClass)) {
            throw new \Exception("Model class '$resolvedClass' not found.");
        }

        $this->connection = $connection;
        $this->modelClass = $resolvedClass;
        $this->foreignKey = $foreignKey;
        $this->localKeyName = $localKeyName;
    }

    public function setParent(Model $parent): self
    {
        $this->parent = $parent;
        return $this;
    }

    public function get(): ?Model
    {
        // Om parent finns: hämta värdet från parent
        if ($this->parent !== null) {
            $localValue = $this->parent->getAttribute($this->localKeyName);
            if ($localValue === null) {
                return null;
            }
        } else {
            // Backwards compatibility: tillåt att localKeyName redan är ett värde ('id' numeriskt)
            $localValue = $this->localKeyName;
        }

        $modelInstance = new $this->modelClass();
        $table = $modelInstance->getTable();

        $query = "SELECT * FROM `$table` WHERE `$this->foreignKey` = ? LIMIT 1";
        $result = $this->connection->fetchOne($query, [$localValue]);

        if ($result !== false && is_array($result)) {
            return $this->createModelInstance($result);
        }

        return null;
    }

    public function first(): ?Model
    {
        return $this->get();
    }

    private function createModelInstance(array $data): Model
    {
        $model = new $this->modelClass();
        $model->fill($data); // Fyll modellen, 'exists' exkluderas här
        $model->markAsExisting(); // Använd metod för att explicit sätta flaggan

        return $model;
    }

    /**
     * Hjälpmetod för att lösa det fullständiga modellklassnamnet.
     */
    private function resolveModelClass(string $classOrTable): string
    {
        if (class_exists($classOrTable)) {
            return $classOrTable; // Returnera direkt
        }

        // Använd den delade singulariseringen
        $singularClass = 'App\\Models\\' . ucfirst(StringHelper::singularize($classOrTable));

        if (class_exists($singularClass)) {
            return $singularClass;
        }

        throw new \Exception("Model class '$classOrTable' not found. Expected '$singularClass'.");
    }
}