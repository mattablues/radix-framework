<?php

declare(strict_types=1);

namespace Radix\Database\QueryBuilder;

use Radix\Database\Connection;

abstract class AbstractQueryBuilder
{
    protected array $bindings = [];
    protected ?Connection $connection = null;

    /**
     * Sätt `Connection`-instans för QueryBuilder.
     */
    public function setConnection(Connection $connection): static
    {
        $this->connection = $connection;
        return $this;
    }

    /**
     * Kör SQL-frågan.
     */
    public function execute(): \PDOStatement
    {
        if ($this->connection === null) {
            throw new \LogicException('No Connection instance has been set. Use setConnection() to assign a database connection.');
        }

        $sql = $this->toSql();
        return $this->connection->execute($sql, $this->bindings);
    }

    public function get(): array
    {
        if (is_null($this->modelClass)) {
            throw new \LogicException("Model class is not set. Use setModelClass() before calling get().");
        }

        $sql = $this->toSql();
        $rows = $this->connection->fetchAll($sql, $this->bindings);

        // Hydrera alltid till Model (även rena aggregat)
        $results = [];
        foreach ($rows as $row) {
            $model = new $this->modelClass();
            $model->hydrateFromDatabase($row);
            $model->markAsExisting();

            // Obs: Inga aggregat eller *_count sätts som relationer här längre.
            // De finns redan i $attributes via hydrateFromDatabase.

            // Eager load endast riktiga relationer (behåll denna del)
            if (!empty($this->eagerLoadRelations)) {
                foreach ($this->eagerLoadRelations as $relation) {
                    if (!method_exists($model, $relation)) {
                        throw new \InvalidArgumentException("Relation '$relation' is not defined in the model '{$this->modelClass}'.");
                    }
                    $relObj = $model->$relation();
                    if (method_exists($relObj, 'setParent')) {
                        $relObj->setParent($model);
                    }
                    $relationData = $relObj->get();
                    $model->setRelation($relation, is_array($relationData) ? $relationData : ($relationData ?? null));
                }
            }

            $results[] = $model;
        }

        return $results;
    }
}