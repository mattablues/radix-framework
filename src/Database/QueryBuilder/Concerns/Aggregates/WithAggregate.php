<?php

declare(strict_types=1);

namespace Radix\Database\QueryBuilder\Concerns\Aggregates;

trait WithAggregate
{
    public function withSum(string $relation, string $column, ?string $alias = null): self
    {
        return $this->withAggregate($relation, $column, 'SUM', $alias);
    }

    public function withAvg(string $relation, string $column, ?string $alias = null): self
    {
        return $this->withAggregate($relation, $column, 'AVG', $alias);
    }

    public function withMin(string $relation, string $column, ?string $alias = null): self
    {
        return $this->withAggregate($relation, $column, 'MIN', $alias);
    }

    public function withMax(string $relation, string $column, ?string $alias = null): self
    {
        return $this->withAggregate($relation, $column, 'MAX', $alias);
    }

    public function withAggregate(string $relation, string $column, string $fn, ?string $alias = null): self
    {
        if ($this->modelClass === null) {
            throw new \LogicException("Model class is not set. Use setModelClass() before calling withAggregate().");
        }

        $fn = strtoupper($fn);
        if (!in_array($fn, ['SUM', 'AVG', 'MIN', 'MAX'], true)) {
            throw new \InvalidArgumentException("Unsupported aggregate function: $fn");
        }

        $computedAlias = $alias ?: "{$relation}_" . strtolower($fn);

        $this->addRelationAggregateSelect($relation, $column, $fn, $alias);
        $this->withAggregateExpressions[] = $computedAlias;

        return $this;
    }

    protected function addRelationAggregateSelect(string $relation, string $column, string $fn, ?string $alias = null): void
    {
        /** @var \Radix\Database\ORM\Model $parent */
        $parent = new $this->modelClass();
        $parentTable = trim($this->table, '`');
        $parentPk = $parent::getPrimaryKey();

        if (!method_exists($parent, $relation)) {
            throw new \InvalidArgumentException("Relation '$relation' is not defined in model $this->modelClass.");
        }

        $rel = $parent->$relation();
        $aggAlias = $alias ?: "{$relation}_" . strtolower($fn);

        if ($rel instanceof \Radix\Database\ORM\Relationships\HasMany) {
            $relatedModelClass = (new \ReflectionClass($rel))->getProperty('modelClass');
            $relatedModelClass->setAccessible(true);
            $relatedClass = $relatedModelClass->getValue($rel);
            $relatedInstance = class_exists($relatedClass) ? new $relatedClass() : null;
            $relatedTable = $relatedInstance ? $relatedInstance->getTable() : $relation;

            $fkProp = (new \ReflectionClass($rel))->getProperty('foreignKey');
            $fkProp->setAccessible(true);
            $foreignKey = $fkProp->getValue($rel);

            $this->columns[] = "(SELECT $fn(`$relatedTable`.`$column`) FROM `$relatedTable` WHERE `$relatedTable`.`$foreignKey` = `$parentTable`.`$parentPk`) AS `$aggAlias`";
            return;
        }

        if ($rel instanceof \Radix\Database\ORM\Relationships\HasOneThrough) {
            $ref = new \ReflectionClass($rel);

            $relatedProp = $ref->getProperty('related');
            $relatedProp->setAccessible(true);
            $relatedClassOrTable = $relatedProp->getValue($rel);

            $throughProp = $ref->getProperty('through');
            $throughProp->setAccessible(true);
            $throughClassOrTable = $throughProp->getValue($rel);

            $firstKeyProp = $ref->getProperty('firstKey');
            $firstKeyProp->setAccessible(true);
            $firstKey = $firstKeyProp->getValue($rel);

            $secondKeyProp = $ref->getProperty('secondKey');
            $secondKeyProp->setAccessible(true);
            $secondKey = $secondKeyProp->getValue($rel);

            $secondLocalProp = $ref->getProperty('secondLocal');
            $secondLocalProp->setAccessible(true);
            $secondLocal = $secondLocalProp->getValue($rel);

            $resolveTable = function (string $classOrTable): string {
                if (class_exists($classOrTable)) {
                    $m = new $classOrTable();
                    return $m->getTable();
                }
                return $classOrTable;
            };

            $relatedTable = $resolveTable($relatedClassOrTable);
            $throughTable = $resolveTable($throughClassOrTable);

            $this->columns[] =
                "(SELECT $fn(`r`.`$column`) FROM `$relatedTable` AS r INNER JOIN `$throughTable` AS t ON t.`$secondLocal` = r.`$secondKey` WHERE t.`$firstKey` = `$parentTable`.`$parentPk` LIMIT 1) AS `$aggAlias`";
            return;
        }

        if ($rel instanceof \Radix\Database\ORM\Relationships\HasManyThrough) {
            $ref = new \ReflectionClass($rel);

            $relatedProp = $ref->getProperty('related');
            $relatedProp->setAccessible(true);
            $relatedClassOrTable = $relatedProp->getValue($rel);

            $throughProp = $ref->getProperty('through');
            $throughProp->setAccessible(true);
            $throughClassOrTable = $throughProp->getValue($rel);

            $firstKeyProp = $ref->getProperty('firstKey');
            $firstKeyProp->setAccessible(true);
            $firstKey = $firstKeyProp->getValue($rel);

            $secondKeyProp = $ref->getProperty('secondKey');
            $secondKeyProp->setAccessible(true);
            $secondKey = $secondKeyProp->getValue($rel);

            $secondLocalProp = $ref->getProperty('secondLocal');
            $secondLocalProp->setAccessible(true);
            $secondLocal = $secondLocalProp->getValue($rel);

            $resolveTable = function (string $classOrTable): string {
                if (class_exists($classOrTable)) {
                    $m = new $classOrTable();
                    return $m->getTable();
                }
                return $classOrTable;
            };

            $relatedTable = $resolveTable($relatedClassOrTable);
            $throughTable = $resolveTable($throughClassOrTable);

            $this->columns[] =
                "(SELECT $fn(`r`.`$column`) FROM `$relatedTable` AS r INNER JOIN `$throughTable` AS t ON t.`$secondLocal` = r.`$secondKey` WHERE t.`$firstKey` = `$parentTable`.`$parentPk`) AS `$aggAlias`";
            return;
        }

        if ($rel instanceof \Radix\Database\ORM\Relationships\HasOne) {
            $fkProp = (new \ReflectionClass($rel))->getProperty('foreignKey');
            $fkProp->setAccessible(true);
            $foreignKey = $fkProp->getValue($rel);

            $mcProp = (new \ReflectionClass($rel))->getProperty('modelClass');
            $mcProp->setAccessible(true);
            $modelClass = $mcProp->getValue($rel);
            $relatedInstance = new $modelClass();
            $relatedTable = $relatedInstance->getTable();

            $this->columns[] = "(SELECT $fn(`$relatedTable`.`$column`) FROM `$relatedTable` WHERE `$relatedTable`.`$foreignKey` = `$parentTable`.`$parentPk`) AS `$aggAlias`";
            return;
        }

        if ($rel instanceof \Radix\Database\ORM\Relationships\BelongsTo) {
            $ownerKeyProp = (new \ReflectionClass($rel))->getProperty('ownerKey');
            $ownerKeyProp->setAccessible(true);
            $ownerKey = $ownerKeyProp->getValue($rel);

            $fkProp = (new \ReflectionClass($rel))->getProperty('foreignKey');
            $fkProp->setAccessible(true);
            $parentForeignKey = $fkProp->getValue($rel);

            $tableProp = (new \ReflectionClass($rel))->getProperty('relatedTable');
            $tableProp->setAccessible(true);
            $relatedTable = $tableProp->getValue($rel);

            $this->columns[] = "(SELECT $fn(`$relatedTable`.`$column`) FROM `$relatedTable` WHERE `$relatedTable`.`$ownerKey` = `$parentTable`.`$parentForeignKey`) AS `$aggAlias`";
            return;
        }

        if ($rel instanceof \Radix\Database\ORM\Relationships\BelongsToMany) {
            $pivotTable = $rel->getPivotTable();
            $foreignPivotKey = $rel->getForeignPivotKey();

            $relatedClass = $rel->getRelatedModelClass();
            /** @var \Radix\Database\ORM\Model $relatedInstance */
            $relatedInstance = new $relatedClass();
            $relatedTable = $relatedInstance->getTable();

            $relatedPivotKeyProp = (new \ReflectionClass($rel))->getProperty('relatedPivotKey');
            $relatedPivotKeyProp->setAccessible(true);
            $relatedPivotKey = $relatedPivotKeyProp->getValue($rel);

            $this->columns[] =
                "(SELECT $fn(`related`.`$column`) FROM `$relatedTable` AS related INNER JOIN `$pivotTable` AS pivot ON related.`id` = pivot.`$relatedPivotKey` WHERE pivot.`$foreignPivotKey` = `$parentTable`.`$parentPk`) AS `$aggAlias`";
            return;
        }

        throw new \InvalidArgumentException("withAggregate() does not support relation type for '$relation'.");
    }
}