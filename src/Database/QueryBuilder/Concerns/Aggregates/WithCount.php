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

trait WithCount
{
    /**
     * @param string|array<int, string> $relations
     */
    public function withCount(string|array $relations): self
    {
        if ($this->modelClass === null) {
            throw new LogicException("Model class is not set. Use setModelClass() before calling withCount().");
        }

        $relations = (array) $relations;
        foreach ($relations as $relation) {
            $this->withCountRelations[] = $relation;
            $this->addRelationCountSelect($relation);
        }

        return $this;
    }

    protected function addRelationCountSelect(string $relation): void
    {
        /** @var \Radix\Database\ORM\Model $parent */
        $parent = new $this->modelClass();
        $parentTable = trim((string) $this->table, '`');
        $parentPk = $parent::getPrimaryKey();

        if (!method_exists($parent, $relation)) {
            throw new InvalidArgumentException("Relation '$relation' is not defined in model {$this->modelClass}.");
        }

        $snake = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $relation) ?? $relation);

        $rel = $parent->$relation();

        if ($rel instanceof \Radix\Database\ORM\Relationships\HasMany) {
            /** @var \Radix\Database\ORM\Relationships\HasMany $rel */
            $relatedClass = $rel->getRelatedModelClass();

            $relatedTable = $relation;


            if (class_exists($relatedClass) && is_subclass_of($relatedClass, Model::class)) {
                /** @var class-string<Model> $relatedClass */
                $relatedInstance = new $relatedClass();
                /** @var Model $relatedInstance */
                $relatedTable = $relatedInstance->getTable();
            }

            $foreignKey = $rel->getForeignKey();

            $this->columns[]
                = "(SELECT COUNT(*) FROM `$relatedTable` WHERE `$relatedTable`.`$foreignKey` = `$parentTable`.`$parentPk`) AS `{$snake}_count`";
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
                = "(SELECT COUNT(*) FROM `$relatedTable` AS r INNER JOIN `$throughTable` AS t ON t.`$secondLocal` = r.`$secondKey` WHERE t.`$firstKey` = `$parentTable`.`$parentPk` LIMIT 1) AS `{$snake}_count`";
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
                = "(SELECT COUNT(*) FROM `$relatedTable` AS r INNER JOIN `$throughTable` AS t ON t.`$secondLocal` = r.`$secondKey` WHERE t.`$firstKey` = `$parentTable`.`$parentPk`) AS `{$snake}_count`";
            return;
        }

        if ($rel instanceof \Radix\Database\ORM\Relationships\BelongsToMany) {
            $pivotTable = $rel->getPivotTable();
            $foreignPivotKey = $rel->getForeignPivotKey();

            /** @var string $pivotTable */
            /** @var string $foreignPivotKey */
            $this->columns[]
                = "(SELECT COUNT(*) FROM `$pivotTable` WHERE `$pivotTable`.`$foreignPivotKey` = `$parentTable`.`$parentPk`) AS `{$snake}_count`";
            return;
        }

        if ($rel instanceof \Radix\Database\ORM\Relationships\HasOne) {
            /** @var HasOne $rel */
            $foreignKey = $rel->getForeignKey();

            $modelClass = $rel->getRelatedModelClass();

            /** @var class-string<Model> $modelClass */
            $relatedInstance = new $modelClass();
            /** @var Model $relatedInstance */
            $relatedTable = $relatedInstance->getTable();

            $this->columns[]
                = "(SELECT COUNT(*) FROM `$relatedTable` WHERE `$relatedTable`.`$foreignKey` = `$parentTable`.`$parentPk`) AS `{$snake}_count`";
            return;
        }

        if ($rel instanceof \Radix\Database\ORM\Relationships\BelongsTo) {
            /** @var BelongsTo $rel */
            $ownerKey = $rel->getOwnerKey();
            $parentForeignKey = $rel->getForeignKey();
            $relatedTable = $rel->getRelatedTable();

            if ($ownerKey === '' || $parentForeignKey === '' || $relatedTable === '') {
                throw new LogicException('BelongsTo relation keys/tables must be strings for withCount().');
            }

            $this->columns[]
                = "(SELECT COUNT(*) FROM `$relatedTable` WHERE `$relatedTable`.`$ownerKey` = `$parentTable`.`$parentForeignKey`) AS `{$snake}_count`";
            return;
        }

        throw new InvalidArgumentException("withCount() does not support relation type for '$relation'.");
    }

    public function withCountWhere(string $relation, string $column, mixed $value, ?string $alias = null): self
    {
        if ($this->modelClass === null) {
            throw new LogicException("Model class is not set. Use setModelClass() before calling withCountWhere().");
        }

        /** @var \Radix\Database\ORM\Model $parent */
        $parent = new $this->modelClass();
        $parentTable = trim((string) $this->table, '`');
        $parentPk = $parent::getPrimaryKey();

        if (!method_exists($parent, $relation)) {
            throw new InvalidArgumentException("Relation '$relation' is not defined in model {$this->modelClass}.");
        }

        $rel = $parent->$relation();
        $snake = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $relation) ?? $relation);

        // Bygg värde‑SQL utan att casta mixed direkt till string
        $valSql = $this->withCountWhereValueToSql($value);

        if ($alias !== null) {
            $aggAlias = $alias;
        } else {
            $suffix = $this->withCountWhereScalarToAliasSuffix($value);
            $aggAlias = "{$snake}_count_" . $suffix;
        }

        if ($rel instanceof \Radix\Database\ORM\Relationships\HasMany) {
            /** @var \Radix\Database\ORM\Relationships\HasMany $rel */
            $relatedClass = $rel->getRelatedModelClass();

            $relatedTable = $relation;

            if (class_exists($relatedClass) && is_subclass_of($relatedClass, Model::class)) {
                /** @var class-string<Model> $relatedClass */
                $relatedInstance = new $relatedClass();
                /** @var Model $relatedInstance */
                $relatedTable = $relatedInstance->getTable();
            }

            $foreignKey = $rel->getForeignKey();

            $this->columns[]
                = "(SELECT COUNT(*) FROM `$relatedTable` WHERE `$relatedTable`.`$foreignKey` = `$parentTable`.`$parentPk` AND `$relatedTable`.`$column` = $valSql) AS `$aggAlias`";
            $this->withAggregateExpressions[] = $aggAlias;
            return $this;
        }

        if ($rel instanceof \Radix\Database\ORM\Relationships\HasOneThrough) {
            /** @var \Radix\Database\ORM\Relationships\HasOneThrough $rel */
            $relatedClassOrTable = $rel->getRelated();
            $throughClassOrTable = $rel->getThrough();

            $firstKey = $rel->getFirstKey();
            $secondKey = $rel->getSecondKey();
            $secondLocal = $rel->getSecondLocal();

            $resolve = function (string $classOrTable): string {
                if (class_exists($classOrTable) && is_subclass_of($classOrTable, Model::class)) {
                    /** @var class-string<Model> $classOrTable */
                    $m = new $classOrTable();
                    /** @var Model $m */
                    return $m->getTable();
                }
                return $classOrTable;
            };

            $relatedTable = $resolve($relatedClassOrTable);
            $throughTable = $resolve($throughClassOrTable);

            $this->columns[]
                = "(SELECT COUNT(*) FROM `$relatedTable` AS r INNER JOIN `$throughTable` AS t ON t.`$secondLocal` = r.`$secondKey` WHERE t.`$firstKey` = `$parentTable`.`$parentPk` AND r.`$column` = $valSql LIMIT 1) AS `$aggAlias`";
            $this->withAggregateExpressions[] = $aggAlias;
            return $this;
        }

        if ($rel instanceof \Radix\Database\ORM\Relationships\HasManyThrough) {
            /** @var \Radix\Database\ORM\Relationships\HasManyThrough $rel */
            $relatedClassOrTable = $rel->getRelated();
            $throughClassOrTable = $rel->getThrough();

            $firstKey = $rel->getFirstKey();
            $secondKey = $rel->getSecondKey();
            $secondLocal = $rel->getSecondLocal();

            $resolve = function (string $classOrTable): string {
                if (class_exists($classOrTable) && is_subclass_of($classOrTable, Model::class)) {
                    /** @var class-string<Model> $classOrTable */
                    $m = new $classOrTable();
                    /** @var Model $m */
                    return $m->getTable();
                }
                return $classOrTable;
            };

            $relatedTable = $resolve($relatedClassOrTable);
            $throughTable = $resolve($throughClassOrTable);

            $this->columns[]
                = "(SELECT COUNT(*) FROM `$relatedTable` AS r INNER JOIN `$throughTable` AS t ON t.`$secondLocal` = r.`$secondKey` WHERE t.`$firstKey` = `$parentTable`.`$parentPk` AND r.`$column` = $valSql) AS `$aggAlias`";
            $this->withAggregateExpressions[] = $aggAlias;
            return $this;
        }

        if ($rel instanceof \Radix\Database\ORM\Relationships\HasOne) {
            /** @var \Radix\Database\ORM\Relationships\HasOne $rel */
            $foreignKey = $rel->getForeignKey();
            $modelClass = $rel->getRelatedModelClass();

            /** @var class-string<Model> $modelClass */
            $relatedInstance = new $modelClass();
            /** @var Model $relatedInstance */
            $relatedTable = $relatedInstance->getTable();

            $this->columns[]
                = "(SELECT COUNT(*) FROM `$relatedTable` WHERE `$relatedTable`.`$foreignKey` = `$parentTable`.`$parentPk` AND `$relatedTable`.`$column` = $valSql) AS `$aggAlias`";
            $this->withAggregateExpressions[] = $aggAlias;
            return $this;
        }

        if ($rel instanceof \Radix\Database\ORM\Relationships\BelongsToMany) {
            $pivotTable = $rel->getPivotTable();
            $foreignPivotKey = $rel->getForeignPivotKey();

            $relatedClass = $rel->getRelatedModelClass();

            if (!is_subclass_of($relatedClass, Model::class)) {
                throw new LogicException(
                    "Related model class '$relatedClass' must extend " . Model::class . " for withCount()."
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
                = "(SELECT COUNT(*) FROM `$pivotTable` AS pivot INNER JOIN `$relatedTable` AS related ON related.`id` = pivot.`$relatedPivotKey` WHERE pivot.`$foreignPivotKey` = `$parentTable`.`$parentPk` AND related.`$column` = $valSql) AS `$aggAlias`";
            $this->withAggregateExpressions[] = $aggAlias;
            return $this;
        }

        if ($rel instanceof \Radix\Database\ORM\Relationships\BelongsTo) {
            /** @var BelongsTo $rel */
            $ownerKey = $rel->getOwnerKey();
            $parentForeignKey = $rel->getForeignKey();
            $relatedTable = $rel->getRelatedTable();

            // Getter-kontraktet garanterar string, så vi validerar "giltig string"
            if ($ownerKey === '' || $parentForeignKey === '' || $relatedTable === '') {
                throw new LogicException('BelongsTo relation keys/tables must be strings for withCountWhere().');
            }

            $this->columns[]
                = "(SELECT COUNT(*) FROM `$relatedTable` WHERE `$relatedTable`.`$ownerKey` = `$parentTable`.`$parentForeignKey` AND `$relatedTable`.`$column` = $valSql) AS `$aggAlias`";
            $this->withAggregateExpressions[] = $aggAlias;
            return $this;
        }

        throw new InvalidArgumentException("withCountWhere() does not support relation type for '$relation'.");
    }

    /**
     * Bygg SQL-literal för ett scalar/null-värde.
     * Returtyp string gör casten observerbar för mutation tests (strict_types=1).
     */
    private function withCountWhereValueToSql(mixed $value): string
    {
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_string($value)) {
            return "'" . addslashes($value) . "'";
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if ($value === null) {
            return 'NULL';
        }

        throw new InvalidArgumentException('withCountWhere() value must be scalar or null.');
    }

    /**
     * Suffix till auto-alias. Returtyp string gör casten observerbar för mutation tests.
     */
    private function withCountWhereScalarToAliasSuffix(mixed $value): string
    {
        if (!is_scalar($value)) {
            return 'value';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }
}
