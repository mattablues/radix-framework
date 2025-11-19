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
use ReflectionClass;
use Throwable;

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

        // snake_case alias av relationsnamnet
        $snake = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $relation) ?? $relation);

        $rel = $parent->$relation();
        /** @var \Radix\Database\ORM\Relationships\HasMany
         *   |\Radix\Database\ORM\Relationships\HasOne
         *   |\Radix\Database\ORM\Relationships\HasOneThrough
         *   |\Radix\Database\ORM\Relationships\HasManyThrough
         *   |\Radix\Database\ORM\Relationships\BelongsTo
         *   |\Radix\Database\ORM\Relationships\BelongsToMany $rel
         */
        $relatedTableGuess = $relation;

        if ($rel instanceof \Radix\Database\ORM\Relationships\HasMany) {
            try {
                $relatedModelClass = 'App\\Models\\' . ucfirst(\Radix\Support\StringHelper::singularize($relation));
                if (class_exists($relatedModelClass) && is_subclass_of($relatedModelClass, Model::class)) {
                    /** @var class-string<Model> $relatedModelClass */
                    $relatedInstance = new $relatedModelClass();
                    /** @var Model $relatedInstance */
                    $relatedTable = $relatedInstance->getTable();
                } else {
                    $relatedTable = $relatedTableGuess;
                }
            } catch (Throwable) {
                $relatedTable = $relatedTableGuess;
            }

            /** @var \Radix\Database\ORM\Relationships\HasMany $rel */
            $ref = new ReflectionClass($rel);
            $fkProp = $ref->getProperty('foreignKey');
            $fkProp->setAccessible(true);
            /** @var string $foreignKey */
            $foreignKey = $fkProp->getValue($rel);

            $this->columns[]
                = "(SELECT COUNT(*) FROM `$relatedTable` WHERE `$relatedTable`.`$foreignKey` = `$parentTable`.`$parentPk`) AS `{$snake}_count`";
            return;
        }

        if ($rel instanceof \Radix\Database\ORM\Relationships\HasOneThrough) {
            /** @var HasOneThrough $rel */
            $ref = new ReflectionClass($rel);

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

            if (
                !is_string($relatedClassOrTable)
                || !is_string($throughClassOrTable)
                || !is_string($firstKey)
                || !is_string($secondKey)
                || !is_string($secondLocal)
            ) {
                throw new LogicException('HasOneThrough relation properties must be strings for withCount().');
            }

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
            $ref = new ReflectionClass($rel);

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

            if (
                !is_string($relatedClassOrTable)
                || !is_string($throughClassOrTable)
                || !is_string($firstKey)
                || !is_string($secondKey)
                || !is_string($secondLocal)
            ) {
                throw new LogicException('HasManyThrough relation properties must be strings for withCount().');
            }

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
            $ref = new ReflectionClass($rel);

            $fkProp = $ref->getProperty('foreignKey');
            $fkProp->setAccessible(true);
            /** @var string $foreignKey */
            $foreignKey = $fkProp->getValue($rel);

            $mcProp = $ref->getProperty('modelClass');
            $mcProp->setAccessible(true);
            /** @var class-string<Model> $modelClass */
            $modelClass = $mcProp->getValue($rel);

            $relatedInstance = new $modelClass();
            /** @var Model $relatedInstance */
            $relatedTable = $relatedInstance->getTable();

            $this->columns[]
                = "(SELECT COUNT(*) FROM `$relatedTable` WHERE `$relatedTable`.`$foreignKey` = `$parentTable`.`$parentPk`) AS `{$snake}_count`";
            return;
        }

        if ($rel instanceof \Radix\Database\ORM\Relationships\BelongsTo) {
            /** @var BelongsTo $rel */
            $ref = new ReflectionClass($rel);

            $ownerKeyProp = $ref->getProperty('ownerKey');
            $ownerKeyProp->setAccessible(true);
            $ownerKey = $ownerKeyProp->getValue($rel);

            $fkProp = $ref->getProperty('foreignKey');
            $fkProp->setAccessible(true);
            $parentForeignKey = $fkProp->getValue($rel);

            $tableProp = $ref->getProperty('relatedTable');
            $tableProp->setAccessible(true);
            $relatedTable = $tableProp->getValue($rel);

            if (!is_string($ownerKey) || !is_string($parentForeignKey) || !is_string($relatedTable)) {
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
        /** @var \Radix\Database\ORM\Relationships\HasMany
         *   |\Radix\Database\ORM\Relationships\HasOne
         *   |\Radix\Database\ORM\Relationships\HasOneThrough
         *   |\Radix\Database\ORM\Relationships\HasManyThrough
         *   |\Radix\Database\ORM\Relationships\BelongsTo
         *   |\Radix\Database\ORM\Relationships\BelongsToMany $rel
         */
        $snake = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $relation) ?? $relation);

        // Bygg värde‑SQL utan att casta mixed direkt till string
        if (is_int($value) || is_float($value)) {
            $valSql = (string) $value;
        } elseif (is_string($value)) {
            $valSql = "'" . addslashes($value) . "'";
        } elseif (is_bool($value)) {
            $valSql = $value ? '1' : '0';
        } elseif ($value === null) {
            $valSql = 'NULL';
        } else {
            throw new InvalidArgumentException('withCountWhere() value must be scalar or null.');
        }

        if ($alias !== null) {
            $aggAlias = $alias;
        } else {
            if (is_scalar($value)) {
                if (is_bool($value)) {
                    $suffix = $value ? 'true' : 'false';
                } else {
                    $suffix = (string) $value;
                }
            } else {
                $suffix = 'value';
            }
            $aggAlias = "{$snake}_count_" . $suffix;
        }

        if ($rel instanceof \Radix\Database\ORM\Relationships\HasMany) {
            /** @var \Radix\Database\ORM\Relationships\HasMany $rel */
            $ref = new ReflectionClass($rel);
            $relatedModelClassProp = $ref->getProperty('modelClass');
            $relatedModelClassProp->setAccessible(true);
            /** @var class-string<Model> $relatedClass */
            $relatedClass = $relatedModelClassProp->getValue($rel);

            $relatedInstance = (class_exists($relatedClass) && is_subclass_of($relatedClass, Model::class))
                ? new $relatedClass()
                : null;

            /** @var Model|null $relatedInstance */
            $relatedTable = $relatedInstance ? $relatedInstance->getTable() : $relation;

            $fkProp = $ref->getProperty('foreignKey');
            $fkProp->setAccessible(true);
            /** @var string $foreignKey */
            $foreignKey = $fkProp->getValue($rel);

            $this->columns[]
                = "(SELECT COUNT(*) FROM `$relatedTable` WHERE `$relatedTable`.`$foreignKey` = `$parentTable`.`$parentPk` AND `$relatedTable`.`$column` = $valSql) AS `$aggAlias`";
            $this->withAggregateExpressions[] = $aggAlias;
            return $this;
        }

        if ($rel instanceof \Radix\Database\ORM\Relationships\HasOneThrough) {
            /** @var \Radix\Database\ORM\Relationships\HasOneThrough $rel */
            $ref = new ReflectionClass($rel);

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

            if (
                !is_string($relatedClassOrTable)
                || !is_string($throughClassOrTable)
                || !is_string($firstKey)
                || !is_string($secondKey)
                || !is_string($secondLocal)
            ) {
                throw new LogicException('HasOneThrough relation properties must be strings for withCountWhere().');
            }

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

            $ref = new ReflectionClass($rel);

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

            if (
                !is_string($relatedClassOrTable)
                || !is_string($throughClassOrTable)
                || !is_string($firstKey)
                || !is_string($secondKey)
                || !is_string($secondLocal)
            ) {
                throw new LogicException('HasManyThrough relation properties must be strings for withCountWhere().');
            }

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
            $ref = new ReflectionClass($rel);

            $fkProp = $ref->getProperty('foreignKey');
            $fkProp->setAccessible(true);
            /** @var string $foreignKey */
            $foreignKey = $fkProp->getValue($rel);

            $mcProp = $ref->getProperty('modelClass');
            $mcProp->setAccessible(true);
            /** @var class-string<Model> $modelClass */
            $modelClass = $mcProp->getValue($rel);

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

            $relatedPivotKeyProp = (new ReflectionClass($rel))->getProperty('relatedPivotKey');
            $relatedPivotKeyProp->setAccessible(true);
            /** @var string $relatedPivotKey */
            $relatedPivotKey = $relatedPivotKeyProp->getValue($rel);

            $this->columns[]
                = "(SELECT COUNT(*) FROM `$pivotTable` AS pivot INNER JOIN `$relatedTable` AS related ON related.`id` = pivot.`$relatedPivotKey` WHERE pivot.`$foreignPivotKey` = `$parentTable`.`$parentPk` AND related.`$column` = $valSql) AS `$aggAlias`";
            $this->withAggregateExpressions[] = $aggAlias;
            return $this;
        }

        if ($rel instanceof \Radix\Database\ORM\Relationships\BelongsTo) {
            /** @var BelongsTo $rel */
            $ref = new ReflectionClass($rel);

            $ownerKeyProp = $ref->getProperty('ownerKey');
            $ownerKeyProp->setAccessible(true);
            $ownerKey = $ownerKeyProp->getValue($rel);

            $fkProp = $ref->getProperty('foreignKey');
            $fkProp->setAccessible(true);
            $parentForeignKey = $fkProp->getValue($rel);

            $tableProp = $ref->getProperty('relatedTable');
            $tableProp->setAccessible(true);
            $relatedTable = $tableProp->getValue($rel);

            if (!is_string($ownerKey) || !is_string($parentForeignKey) || !is_string($relatedTable)) {
                throw new LogicException('BelongsTo relation keys/tables must be strings for withCountWhere().');
            }

            $this->columns[]
                = "(SELECT COUNT(*) FROM `$relatedTable` WHERE `$relatedTable`.`$ownerKey` = `$parentTable`.`$parentForeignKey` AND `$relatedTable`.`$column` = $valSql) AS `$aggAlias`";
            $this->withAggregateExpressions[] = $aggAlias;
            return $this;
        }

        throw new InvalidArgumentException("withCountWhere() does not support relation type for '$relation'.");
    }
}
