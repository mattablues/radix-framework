<?php

declare(strict_types=1);

namespace Radix\Database\QueryBuilder;

use Radix\Database\Connection;

abstract class AbstractQueryBuilder
{
    /** @var array<int, mixed> */
    protected array $bindings = [];
    protected ?Connection $connection = null;
    /** @var array<string, callable> */
    protected array $eagerLoadConstraints = []; // nya: closures per relation
    protected ?string $modelClass = null;

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

    /**
     * Kör SQL och hydrera resultat.
     *
     * @return array<int, mixed>|\Radix\Collection\Collection
     *         (override i QueryBuilder returnerar alltid Collection)
     */
    public function get() /* Collection i QueryBuilder-override */
    {
        if (is_null($this->modelClass)) {
            throw new \LogicException("Model class is not set. Use setModelClass() before calling get().");
        }

        $sql = $this->toSql();
        $rows = $this->connection->fetchAll($sql, $this->bindings);

        $results = [];
        foreach ($rows as $row) {
            $model = new $this->modelClass();
            $model->hydrateFromDatabase($row);
            $model->markAsExisting();

            if (!empty($this->eagerLoadRelations)) {
                foreach ($this->eagerLoadRelations as $relation) {
                    if (!method_exists($model, $relation)) {
                        throw new \InvalidArgumentException("Relation '$relation' is not defined in the model '$this->modelClass'.");
                    }
                    $relObj = $model->$relation();
                    if (method_exists($relObj, 'setParent')) {
                        $relObj->setParent($model);
                    }

                    $relationData = null;
                    $closure = $this->eagerLoadConstraints[$relation] ?? null;

                    $qb = null;
                    if (method_exists($relObj, 'query')) {
                        $qb = $relObj->query();
                    }

                    if ($closure instanceof \Closure && $qb instanceof \Radix\Database\QueryBuilder\QueryBuilder) {
                        $closure($qb);
                        $relationData = method_exists($relObj, 'get') ? $relObj->get() : $qb->get();
                    } else {
                        $relationData = method_exists($relObj, 'get') ? $relObj->get() : null;
                    }

                    // Viktigt: behåll relationens typ (array för many, Model|null för one)
                    $model->setRelation($relation, $relationData);
                }
            }

            $results[] = $model;
        }

        // Return-typen överstyras i QueryBuilder::get() (Collection)
        return $results;
    }

    abstract public function toSql(): string;
}