<?php

declare(strict_types=1);

namespace Radix\Database\ORM\Relationships;

use Radix\Database\Connection;
use Radix\Database\ORM\Model;

class BelongsToMany
{
    private Connection $connection;
    private string $relatedTable;
    private string $pivotTable;
    private string $foreignPivotKey;
    private string $relatedPivotKey;
    private string $parentKey;

    public function __construct(
        Connection $connection,
        string $relatedTable,
        string $pivotTable,
        string $foreignPivotKey,
        string $relatedPivotKey,
        string $parentKey
    ) {
        $this->connection = $connection;
        $this->relatedTable = $relatedTable;
        $this->pivotTable = $pivotTable;
        $this->foreignPivotKey = $foreignPivotKey;
        $this->relatedPivotKey = $relatedPivotKey;
        $this->parentKey = $parentKey;
    }

    public function get(): array
    {
        $query = "
            SELECT related.*
            FROM `$this->relatedTable` AS related
            INNER JOIN `$this->pivotTable` AS pivot
            ON related.id = pivot.`$this->relatedPivotKey`
            WHERE pivot.`$this->foreignPivotKey` = ?";

        $results = $this->connection->fetchAll($query, [$this->parentKey]);

        return array_map(fn($data) => $this->createModelInstance($data, $this->relatedTable), $results);
    }

    public function first(): ?Model
    {
        // Hämta alla relaterade poster genom befintlig `get()`-metod
        $results = $this->get();

        // Kontrollera om resultaten är tomma
        if (empty($results)) {
            return null;
        }

        // Returnera den första modellen i listan
        return reset($results);
    }

    private function resolveModelClass(string $classOrTable): string
    {
        if (class_exists($classOrTable)) {
            return $classOrTable;
        }

        $resolvedClass = 'App\\Models\\' . ucfirst($classOrTable);

        if (class_exists($resolvedClass)) {
            return $resolvedClass;
        }

        throw new \Exception("Model class '$resolvedClass' not found.");
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