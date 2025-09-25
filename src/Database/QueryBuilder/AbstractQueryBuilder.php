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
        // Kontrollera att modelClass är inställd
        if (is_null($this->modelClass)) {
            throw new \LogicException("Model class is not set. Use setModelClass() before calling get().");
        }

        // Hämta resultaten direkt från frågan - Observera att du bör ha en implementering av `toSql()`
        $sql = $this->toSql();
        $results = $this->connection->fetchAll($sql, $this->bindings);

        // Om det finns relationer att förladda
        if (!empty($this->eagerLoadRelations)) {
            foreach ($results as &$result) {
                // Skapa en instans av den aktuella modellen
                $modelInstance = new $this->modelClass();
                $modelInstance->fill($result);

                foreach ($this->eagerLoadRelations as $relation) {
                    if (!method_exists($modelInstance, $relation)) {
                        throw new \InvalidArgumentException("Relation '$relation' is not defined in the model '{$this->modelClass}'.");
                    }

                    // Ladda relationen
                    $relationData = $modelInstance->$relation()->get();

                    // Hantera HasMany specifikt och säkerställ array-hantering
                    if (is_array($relationData)) {
                        $modelInstance->setRelation($relation, $relationData);
                    } else {
                        $modelInstance->setRelation($relation, $relationData ?? null); // Hantera null för tomma relationer
                    }
                }

                $result = $modelInstance; // Uppdatera resultatet med en korrekt laddad modell
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