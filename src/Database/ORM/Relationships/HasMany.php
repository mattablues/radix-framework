<?php

declare(strict_types=1);

namespace Radix\Database\ORM\Relationships;

use Radix\Database\Connection;
use Radix\Database\ORM\Model;

class HasMany
{
    private Connection $connection;
    private string $modelClass;
    private string $foreignKey;
    private string $localKey;

    public function __construct(Connection $connection, string $modelClass, string $foreignKey, $localKey)
    {
        $this->connection = $connection;

        if (!class_exists($modelClass)) {
            throw new \Exception("Class '$modelClass' not found for HasMany relation.");
        }

        $this->modelClass = $modelClass;
        $this->foreignKey = $foreignKey;
        $this->localKey = $localKey;
    }

    public function get(): array
    {
        $modelClass = $this->resolveModelClass($this->modelClass); // Säkerställ rätt klassnamn
        $modelInstance = new $modelClass();
        $table = $modelInstance->getTable();

        $query = "SELECT * FROM `$table` WHERE `$this->foreignKey` = ?";
        $results = $this->connection->fetchAll($query, [$this->localKey]);

        return array_map(fn($data) => $this->createModelInstance($data, $modelClass), $results);
    }

    public function first(): ?Model
    {
        $results = $this->get();

        if (empty($results)) {
            return null;
        }

        return reset($results); // Returnera den första instansen
    }


    private function createModelInstance(array $data, string $classOrTable): Model
    {
        $modelClass = $this->resolveModelClass($classOrTable);
        $model = new $modelClass();
        $model->fill($data);
        $model->markAsExisting();

        return $model;
    }

    private function resolveModelClass(string $classOrTable): string
    {
        // Om ett fullständigt kvalificerat klassnamn redan anges, använd direkt
        if (class_exists($classOrTable)) {
            return $classOrTable;
        }

        // Annars tolkas detta från `App\Models`
        $resolvedClass = 'App\\Models\\' . ucfirst($classOrTable);

        if (class_exists($resolvedClass)) {
            return $resolvedClass;
        }

        throw new \Exception("Model class '$resolvedClass' not found.");
    }
}