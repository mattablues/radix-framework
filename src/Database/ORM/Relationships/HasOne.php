<?php

declare(strict_types=1);

namespace Radix\Database\ORM\Relationships;

use Radix\Database\Connection;
use Radix\Database\ORM\Model;

class HasOne
{
    private Connection $connection;
    private string $modelClass;
    private string $foreignKey;
    private $localKey;

    public function __construct(Connection $connection, string $modelClass, string $foreignKey, $localKey)
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
            // Kalla bara på createModelInstance om resultatet är en array
            return $this->createModelInstance($result);
        }

        // Returnera null om inget hittades eller om datatyp inte är en array
        return null;
    }

    public function first(): ?Model
    {
        $result = $this->get();

        // Direkt returnera resultatet om det redan är en modell
        if ($result instanceof Model) {
            return $result;
        }

        // Annars skapa en modellinstans
        return $result ? $this->createModelInstance($result) : null;
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
        // Om klassnamn redan är fullständigt kvalificerat
        if (class_exists($classOrTable)) {
            return $classOrTable;
        }

        // Tolka som modell i `App\Models`
        $resolvedClass = 'App\\Models\\' . ucfirst($classOrTable);

        if (class_exists($resolvedClass)) {
            return $resolvedClass;
        }

        // Om modellen inte hittas, returnera som det är
        return $classOrTable;
    }
}