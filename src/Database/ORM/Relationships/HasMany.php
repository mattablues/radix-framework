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

    private function resolveModelClass(string $classOrTable): string
    {
        // Om klassnamnet redan är fullt kvalificerat
        if (class_exists($classOrTable)) {
            return $classOrTable; // Returnera direkt om klassen redan finns
        }

        // Försök interpretiera som singular modell
        $singularClass = 'App\\Models\\' . ucfirst(rtrim($classOrTable, 's')); // Plural 'tokens' → 'Token'
        $pluralClass = 'App\\Models\\' . ucfirst($classOrTable); // Utan trim = plural

        if (class_exists($singularClass)) {
            return $singularClass;
        }

        if (class_exists($pluralClass)) {
            return $pluralClass;
        }

        // Kasta fel om modellen inte hittas
        throw new \Exception("Model class '$classOrTable' not found.");
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