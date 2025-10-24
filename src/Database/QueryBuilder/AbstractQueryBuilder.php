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

            // withCount: lägg in {relation}_count som int
            if (!empty($this->withCountRelations)) {
                foreach ($this->withCountRelations as $rel) {
                    $k = $rel . '_count';
                    if (array_key_exists($k, $row)) {
                        $model->setRelation($k, (int)$row[$k]);
                    }
                }
            }

            // withAggregate/withCountWhere: lägg in alias och typkasta
            if (!empty($this->withAggregateExpressions)) {
                foreach ($this->withAggregateExpressions as $aggAlias) {
                    if (array_key_exists($aggAlias, $row)) {
                        $val = $row[$aggAlias];

                        // Försök hitta motsvarande uttryck för aliaset i columns för typning
                        $typed = false;
                        if (property_exists($this, 'columns') && !empty($this->columns)) {
                            foreach ($this->columns as $colExpr) {
                                $expr = (string)$colExpr;
                                if (preg_match('/\b(COUNT|SUM|AVG|MIN|MAX)\s*\([^)]*\)\s+AS\s+`?'.preg_quote($aggAlias, '/').'`?/i', $expr, $m)) {
                                    $fn = strtoupper($m[1]);
                                    if ($fn === 'COUNT') {
                                        $val = (int)$val;
                                    } elseif ($fn === 'AVG') {
                                        $val = (float)$val;
                                    } else { // SUM/MIN/MAX
                                        $val = is_numeric($val) && (string)(int)$val === (string)$val ? (int)$val : (float)$val;
                                    }
                                    $typed = true;
                                    break;
                                }
                            }
                        }

                        if (!$typed && is_numeric($val)) {
                            // fallback: försök rimlig typning
                            $val = (string)(int)$val === (string)$val ? (int)$val : (float)$val;
                        }

                        $model->setRelation($aggAlias, $val);
                    }
                }
            }

            // Detektera enkla aggregat i SELECT (ex COUNT(...) AS `count`) och lägg som relation med korrekt typ
            if (property_exists($this, 'columns') && !empty($this->columns)) {
                foreach ($this->columns as $colExpr) {
                    $expr = (string)$colExpr;

                    if (preg_match('/\b(COUNT|SUM|AVG|MIN|MAX|ROUND|CEIL|FLOOR|ABS|YEAR|MONTH)\s*\([^)]*\)\s+AS\s+`?([a-zA-Z0-9_]+)`?/i', $expr, $m)) {
                        $fn = strtoupper($m[1]);
                        $alias = $m[2];
                        if (array_key_exists($alias, $row)) {
                            $val = $row[$alias];

                            if ($fn === 'COUNT') {
                                $val = (int)$val;
                            } elseif ($fn === 'AVG') {
                                $val = (float)$val;
                            } elseif (in_array($fn, ['SUM','MIN','MAX','ROUND','CEIL','FLOOR','ABS','YEAR','MONTH'], true)) {
                                $val = is_numeric($val) && (string)(int)$val === (string)$val ? (int)$val : (float)$val;
                            }

                            $model->setRelation($alias, $val);
                        }
                    }
                }
            }

            // Eager load
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

    protected function allSelectedAreExpressionsOrAliases(): bool
    {
        if (empty($this->columns)) {
            return false;
        }

        foreach ($this->columns as $col) {
            $c = is_string($col) ? trim($col) : '';

            $looksLikeExpr =
                preg_match('/\b(COUNT|SUM|AVG|MIN|MAX|UPPER|LOWER|ROUND|CEIL|FLOOR|ABS|YEAR|MONTH|DATE|NOW)\s*\(/i', $c) ||
                str_starts_with($c, '(') ||
                preg_match('/\bAS\b/i', $c);

            if (!$looksLikeExpr) {
                return false;
            }
        }

        return true;
    }

//    public function get(): array
//    {
//        if (is_null($this->modelClass)) {
//            throw new \LogicException("Model class is not set. Use setModelClass() before calling get().");
//        }
//
//        $sql = $this->toSql();
//        $rows = $this->connection->fetchAll($sql, $this->bindings);
//
//        $results = [];
//        foreach ($rows as $row) {
//            $model = new $this->modelClass();
//            $model->fill($row);
//            $model->markAsExisting();
//
//            // Sätt *_count som relationer
//            if (!empty($this->withCountRelations)) {
//                foreach ($this->withCountRelations as $rel) {
//                    $k = $rel . '_count';
//                    if (array_key_exists($k, $row)) {
//                        $model->setRelation($k, (int)$row[$k]);
//                    }
//                }
//            }
//
//            // Sätt aggregat (t.ex. total_votes) som relationer
//            if (!empty($this->withAggregateExpressions)) {
//                foreach ($this->withAggregateExpressions as $aggAlias) {
//                    if (array_key_exists($aggAlias, $row)) {
//                        $model->setRelation($aggAlias, $row[$aggAlias]);
//                    }
//                }
//            }
//
//            // Eager load
//            if (!empty($this->eagerLoadRelations)) {
//                foreach ($this->eagerLoadRelations as $relation) {
//                    if (!method_exists($model, $relation)) {
//                        throw new \InvalidArgumentException("Relation '$relation' is not defined in the model '{$this->modelClass}'.");
//                    }
//                    $relObj = $model->$relation();
//                    if (method_exists($relObj, 'setParent')) {
//                        $relObj->setParent($model);
//                    }
//                    $relationData = $relObj->get();
//                    $model->setRelation($relation, is_array($relationData) ? $relationData : ($relationData ?? null));
//                }
//            }
//
//            $results[] = $model;
//        }
//
//        return $results;
//    }

    /**
     * Placeholder för att generera SQL. Implementeras i barnklassen.
     */
    abstract public function toSql(): string;
}