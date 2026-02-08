<?php

declare(strict_types=1);

namespace Radix\Database\QueryBuilder\Concerns\Aggregates;

use InvalidArgumentException;
use LogicException;
use Radix\Database\ORM\Model;
use Radix\Database\ORM\Relationships\BelongsTo;
use Radix\Database\ORM\Relationships\HasManyThrough;
use Radix\Database\ORM\Relationships\HasOne;
use Radix\Database\ORM\Relationships\HasOneThrough;

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
            throw new LogicException("Model class is not set. Use setModelClass() before calling withAggregate().");
        }

        $fn = strtoupper($fn);
        if (!in_array($fn, ['SUM', 'AVG', 'MIN', 'MAX'], true)) {
            throw new InvalidArgumentException("Unsupported aggregate function: $fn");
        }

        $computedAlias = $alias ?: "{$relation}_" . strtolower($fn);

        $this->addRelationAggregateSelect($relation, $column, $fn, $alias);
        $this->withAggregateExpressions[] = $computedAlias;

        return $this;
    }

    protected function addRelationAggregateSelect(string $relation, string $column, string $fn, ?string $alias = null): void
    {
        /** @var Model $parent */
        $parent = new $this->modelClass();
        $parentTable = trim((string) $this->table, '`');
        $parentPk = $parent::getPrimaryKey();

        if (!method_exists($parent, $relation)) {
            throw new InvalidArgumentException("Relation '$relation' is not defined in model $this->modelClass.");
        }

        $rel = $parent->$relation();
        $aggAlias = $alias ?: (strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $relation) ?? $relation) . '_' . strtolower($fn));

        if ($rel instanceof \Radix\Database\ORM\Relationships\HasMany) {
            /** @var \Radix\Database\ORM\Relationships\HasMany $rel */
            $relatedClass = $rel->getRelatedModelClass();

            $relatedInstance = new $relatedClass();
            /** @var Model $relatedInstance */
            $relatedTable = $relatedInstance->getTable();

            $foreignKey = $rel->getForeignKey();

            $this->columns[]
                = "(SELECT $fn(`$relatedTable`.`$column`) FROM `$relatedTable` WHERE `$relatedTable`.`$foreignKey` = `$parentTable`.`$parentPk`) AS `$aggAlias`";
            return;
        }

        if ($rel instanceof \Radix\Database\ORM\Relationships\HasOneThrough) {
            /** @var HasOneThrough $rel */

            $relatedClassOrTable = $rel->getRelated();
            $throughClassOrTable = $rel->getThrough();

            $firstKey = $rel->getFirstKey();
            $secondKey = $rel->getSecondKey();
            $secondLocal = $rel->getSecondLocal();

            $resolveTable = function (string $classOrTable): string {
                if (class_exists($classOrTable) && is_subclass_of($classOrTable, Model::class)) {
                    /** @var class-string<Model> $classOrTable */
                    $m = new $classOrTable();
                    /** @var Model $m */
                    return $m->getTable();
                }
                return $classOrTable;
            };

            $relatedTable = $resolveTable($relatedClassOrTable);
            $throughTable = $resolveTable($throughClassOrTable);

            $this->columns[]
                = "(SELECT $fn(`r`.`$column`) FROM `$relatedTable` AS r INNER JOIN `$throughTable` AS t ON t.`$secondLocal` = r.`$secondKey` WHERE t.`$firstKey` = `$parentTable`.`$parentPk` LIMIT 1) AS `$aggAlias`";
            return;
        }

        if ($rel instanceof \Radix\Database\ORM\Relationships\HasManyThrough) {
            /** @var HasManyThrough $rel */

            $relatedClassOrTable = $rel->getRelated();
            $throughClassOrTable = $rel->getThrough();

            $firstKey = $rel->getFirstKey();
            $secondKey = $rel->getSecondKey();
            $secondLocal = $rel->getSecondLocal();

            $resolveTable = function (string $classOrTable): string {
                if (class_exists($classOrTable) && is_subclass_of($classOrTable, Model::class)) {
                    /** @var class-string<Model> $classOrTable */
                    $m = new $classOrTable();
                    /** @var Model $m */
                    return $m->getTable();
                }
                return $classOrTable;
            };

            $relatedTable = $resolveTable($relatedClassOrTable);
            $throughTable = $resolveTable($throughClassOrTable);

            $this->columns[]
                = "(SELECT $fn(`r`.`$column`) FROM `$relatedTable` AS r INNER JOIN `$throughTable` AS t ON t.`$secondLocal` = r.`$secondKey` WHERE t.`$firstKey` = `$parentTable`.`$parentPk`) AS `$aggAlias`";
            return;
        }

        if ($rel instanceof \Radix\Database\ORM\Relationships\HasOne) {
            /** @var HasOne $rel */
            $foreignKey = $rel->getForeignKey();

            $modelClass = $rel->getRelatedModelClass();

            $relatedInstance = new $modelClass();
            /** @var Model $relatedInstance */
            $relatedTable = $relatedInstance->getTable();

            $this->columns[]
                = "(SELECT $fn(`$relatedTable`.`$column`) FROM `$relatedTable` WHERE `$relatedTable`.`$foreignKey` = `$parentTable`.`$parentPk`) AS `$aggAlias`";
            return;
        }

        if ($rel instanceof \Radix\Database\ORM\Relationships\BelongsTo) {
            /** @var BelongsTo $rel */
            $ownerKey = $rel->getOwnerKey();
            $parentForeignKey = $rel->getForeignKey();
            $relatedTable = $rel->getRelatedTable();

            $this->columns[]
                = "(SELECT $fn(`$relatedTable`.`$column`) FROM `$relatedTable` WHERE `$relatedTable`.`$ownerKey` = `$parentTable`.`$parentForeignKey`) AS `$aggAlias`";
            return;
        }

        if ($rel instanceof \Radix\Database\ORM\Relationships\BelongsToMany) {
            $pivotTable = $rel->getPivotTable();
            $foreignPivotKey = $rel->getForeignPivotKey();

            $relatedClass = $rel->getRelatedModelClass();

            if (!is_subclass_of($relatedClass, Model::class)) {
                throw new LogicException(
                    "Related model class '$relatedClass' must extend " . Model::class . " for withAggregate()."
                );
            }

            /** @var string $pivotTable */
            /** @var string $foreignPivotKey */
            /** @var class-string<Model> $relatedClass */
            $relatedInstance = new $relatedClass();
            /** @var Model $relatedInstance */
            $relatedTable = $relatedInstance->getTable();

            $relatedPivotKey = $rel->getRelatedPivotKey();

            $this->columns[]
                = "(SELECT $fn(`related`.`$column`) FROM `$relatedTable` AS related INNER JOIN `$pivotTable` AS pivot ON related.`id` = pivot.`$relatedPivotKey` WHERE pivot.`$foreignPivotKey` = `$parentTable`.`$parentPk`) AS `$aggAlias`";
            return;
        }

        throw new InvalidArgumentException("withAggregate() does not support relation type for '$relation'.");
    }
}
