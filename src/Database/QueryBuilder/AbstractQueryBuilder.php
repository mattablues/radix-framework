<?php

declare(strict_types=1);

namespace Radix\Database\QueryBuilder;

use Radix\Database\Connection;
use Radix\Database\ORM\Model;

abstract class AbstractQueryBuilder
{
    /** @var array<int, mixed> */
    protected array $bindings = [];
    protected ?Connection $connection = null;
    /** @var array<string, callable> */
    protected array $eagerLoadConstraints = []; // nya: closures per relation
    /**
     * @var class-string<Model>|null
     */
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
            $rows = $this->getConnection()->fetchAll($sql, $this->bindings);

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

                        // Säkerställ att vi jobbar med objekt (relationer ska alltid returnera objekt)
                        if (!is_object($relObj)) {
                            throw new \LogicException("Relation '$relation' on model '$this->modelClass' did not return an object.");
                        }

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
                            // Constraint via QueryBuilder
                            $closure($qb);
                            $relationData = method_exists($relObj, 'get') ? $relObj->get() : $qb->get();
                        } else {
                            // Ingen (eller annan) constraint: använd relationens get() om den finns
                            if (method_exists($relObj, 'get')) {
                                $relationData = $relObj->get();
                            } elseif ($qb instanceof \Radix\Database\QueryBuilder\QueryBuilder) {
                                $relationData = $qb->get();
                            } else {
                                $relationData = null;
                            }
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


    /**
     * Säkerställ att en Connection finns och returnera den.
     *
     * @return Connection
     */
    protected function getConnection(): Connection
    {
        if ($this->connection === null) {
            throw new \LogicException('No Connection instance has been set. Use setConnection() to assign a database connection.');
        }

        return $this->connection;
    }
}