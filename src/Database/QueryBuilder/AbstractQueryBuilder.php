<?php

declare(strict_types=1);

namespace Radix\Database\QueryBuilder;

use Radix\Database\Connection;
use Radix\Database\ORM\Model;

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
        // Kontrollera att anslutningen är inställd
        if ($this->connection === null) {
            throw new \LogicException('No Connection instance has been set. Use setConnection() to assign a database connection.');
        }

        // Generera SQL-frågan
        $sql = $this->toSql();
        $results = $this->connection->fetchAll($sql, $this->bindings);

        // Om det finns relationer att förladda, bearbeta dessa
        if (!empty($this->eagerLoadRelations)) {
            foreach ($results as &$result) {
                $modelInstance = new $this->modelClass();
                $modelInstance->fill($result);

                foreach ($this->eagerLoadRelations as $relation) {
                    if (!method_exists($modelInstance, $relation)) {
                        throw new \InvalidArgumentException("Relation '$relation' is not defined in the model '$this->modelClass'.");
                    }

                    $relationData = $modelInstance->$relation()->get();

                    // Se till att endast modellinstanser associeras
                    if ($relationData instanceof Model) {
                        $modelInstance->setRelation($relation, $relationData);
                    } else {
                        $modelInstance->setRelation($relation, null); // Sätt null om relationen inte hittades
                    }
                }

                $result = $modelInstance;
            }
            unset($result);
        }

        return $results;
    }

    /**
     * Placeholder för att generera SQL. Implementeras i barnklassen.
     */
    abstract public function toSql(): string;
}