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
    private int|string $localKey;

    public function __construct(Connection $connection, string $modelClass, string $foreignKey, int|string $localKey)
    {
        // Försök att tolka den fullständiga modellen
        $resolvedClass = $this->resolveModelClass($modelClass);

        if (!class_exists($resolvedClass)) {
            throw new \Exception("Model class '$resolvedClass' not found.");
        }

        $this->connection = $connection;
        $this->modelClass = $resolvedClass;
        $this->foreignKey = $foreignKey;
        $this->localKey = $localKey;
    }

    public function get(): ?Model
    {
        $modelInstance = new $this->modelClass();
        $table = $modelInstance->getTable();

        $query = "SELECT * FROM `$table` WHERE `$this->foreignKey` = ? LIMIT 1";
        $result = $this->connection->fetchOne($query, [$this->localKey]);

        if ($result !== false && is_array($result)) {
            // Returnera en ny modellinstans om data hittas
            return $this->createModelInstance($result);
        }

        // Returnera null om inget hittades
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

        // Använd den delade singulariseringsmetoden
        $singularClass = 'App\\Models\\' . ucfirst(StringHelper::singularize($classOrTable));

        if (class_exists($singularClass)) {
            return $singularClass;
        }

        throw new \Exception("Model class '$classOrTable' not found. Expected '$singularClass'.");
    }
}