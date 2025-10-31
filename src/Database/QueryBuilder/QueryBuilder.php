<?php

/** @noinspection ALL */

declare(strict_types=1);

namespace Radix\Database\QueryBuilder;

use Radix\Database\QueryBuilder\AbstractQueryBuilder;

class QueryBuilder extends AbstractQueryBuilder
{
    protected string $type = 'SELECT'; // Typ av fråga: SELECT, INSERT, UPDATE, DELETE
    protected array $columns = ['*']; // Kolumner för SELECT
    protected ?string $table = null;  // Tabellnamn
    protected array $joins = [];      // Lista över JOIN-betingelser
    protected array $where = [];      // WHERE-betingelser
    protected array $groupBy = [];    // GROUP BY-kolumner
    protected array $orderBy = [];    // ORDER BY-kolumner
    protected ?string $having = null; // HAVING-betingelse
    protected ?int $limit = null;     // LIMIT-parameter
    protected ?int $offset = null;    // OFFSET-parameter
    protected array $bindings = [];   // Bindningsvärden
    protected array $unions = [];
    protected bool $distinct = false; // Flagga för D
    protected ?string $modelClass = null;
    protected array $eagerLoadRelations = []; // Förladdade relationer
    protected bool $withSoftDeletes = false;
    protected array $withCountRelations = [];
    protected array $withAggregateExpressions = []; // <-- lägg till för aggregat

    /**
     * Ställ in vilka kolumner som ska väljas vid SELECT.
     */
    public function select(array|string $columns = ['*']): self
    {
        $this->type = 'SELECT';

        // Ta bort defaultkolumnen '*'
        if ($this->columns === ['*']) {
            $this->columns = [];
        }

        $this->columns = array_map(function ($column) {
            // Hantera kolumn med alias (t.ex. u.id AS user_id)
            if (preg_match('/^(.+)\s+AS\s+(.+)$/i', $column, $matches)) {
                $columnPart = $this->wrapColumn(trim($matches[1])); // Wrappa kolumnen (u.id)
                $aliasPart = $this->wrapAlias(trim($matches[2]));   // Wrappa aliaset (user_id)

                return "{$columnPart} AS {$aliasPart}";
            }

            // Hantera funktioner med alias (t.ex. COUNT(*) AS total_employees)
            if (preg_match('/^([A-Z_]+)\((.*)\)\s+AS\s+(.+)$/i', $column, $matches)) {
                $function = $matches[1]; // Funktion, t.ex. COUNT
                $parameters = $matches[2]; // Parametrar, t.ex. *
                $alias = $matches[3]; // Alias, t.ex. total_employees

                $wrappedParameters = implode(', ',
                    array_map([$this, 'wrapColumn'], array_map('trim', explode(',', $parameters))));
                $wrappedAlias = $this->wrapAlias($alias);

                return strtoupper($function)."($wrappedParameters) AS $wrappedAlias";
            }

            // Hantera andra typer på vanligt sätt
            return $this->wrapColumn($column);
        }, (array) $columns);

        return $this;
    }

    /**
     * Räkna antal relaterade poster och exponera som {relation}_count.
     *
     * Stöder: HasMany, HasOne, BelongsTo, BelongsToMany, HasManyThrough, HasOneThrough.
     *
     * Exempel (HasOneThrough):
     *  Category::query()->withCount('topVote')->get();
     */
    public function withCount(string|array $relations): self
    {
        if ($this->modelClass === null) {
            throw new \LogicException("Model class is not set. Use setModelClass() before calling withCount().");
        }

        $relations = (array) $relations;
        foreach ($relations as $relation) {
            $this->withCountRelations[] = $relation;
            $this->addRelationCountSelect($relation);
        }

        return $this;
    }

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

    /**
     * Lägg till aggregat på en relation (SUM/AVG/MIN/MAX).
     *
     * Stöder relationstyper: HasMany, HasOne, BelongsTo, BelongsToMany, HasManyThrough, HasOneThrough.
     *
     * Exempel (HasOneThrough):
     *  // I modellen:
     *  // public function topVote(): HasOneThrough { ... }
     *  //
     *  // I query:
     *  Category::query()
     *      ->withAggregate('topVote', 'points', 'MAX', 'topVote_max')
     *      ->get();
     */
    public function withAggregate(string $relation, string $column, string $fn, ?string $alias = null): self
    {
        if ($this->modelClass === null) {
            throw new \LogicException("Model class is not set. Use setModelClass() before calling withAggregate().");
        }

        $fn = strtoupper($fn);
        if (!in_array($fn, ['SUM', 'AVG', 'MIN', 'MAX'], true)) {
            throw new \InvalidArgumentException("Unsupported aggregate function: {$fn}");
        }

        $computedAlias = $alias ?: "{$relation}_" . strtolower($fn);

        $this->addRelationAggregateSelect($relation, $column, $fn, $alias);

        // Spara aliaset för hydrering till relations
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
            throw new \InvalidArgumentException("Relation '$relation' is not defined in model {$this->modelClass}.");
        }

        $rel = $parent->$relation();
        $aggAlias = $alias ?: "{$relation}_" . strtolower($fn);

        // HasMany
        if ($rel instanceof \Radix\Database\ORM\Relationships\HasMany) {
            // Försök få relaterad tabell
            $relatedModelClass = (new \ReflectionClass($rel))->getProperty('modelClass');
            $relatedModelClass->setAccessible(true);
            $relatedClass = $relatedModelClass->getValue($rel);
            $relatedInstance = class_exists($relatedClass) ? new $relatedClass() : null;
            $relatedTable = $relatedInstance ? $relatedInstance->getTable() : $relation;

            $fkProp = (new \ReflectionClass($rel))->getProperty('foreignKey');
            $fkProp->setAccessible(true);
            $foreignKey = $fkProp->getValue($rel);

            $this->columns[] = "(SELECT {$fn}(`{$relatedTable}`.`{$column}`) FROM `{$relatedTable}` WHERE `{$relatedTable}`.`{$foreignKey}` = `{$parentTable}`.`{$parentPk}`) AS `{$aggAlias}`";
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

            $firstKeyProp = $ref->getProperty('firstKey');     // t.ex. subjects.category_id
            $firstKeyProp->setAccessible(true);
            $firstKey = $firstKeyProp->getValue($rel);

            $secondKeyProp = $ref->getProperty('secondKey');   // t.ex. votes.subject_id
            $secondKeyProp->setAccessible(true);
            $secondKey = $secondKeyProp->getValue($rel);

            $secondLocalProp = $ref->getProperty('secondLocal'); // t.ex. subjects.id
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
                "(SELECT {$fn}(`r`.`{$column}`)" .
                " FROM `{$relatedTable}` AS r" .
                " INNER JOIN `{$throughTable}` AS t ON t.`{$secondLocal}` = r.`{$secondKey}`" .
                " WHERE t.`{$firstKey}` = `{$parentTable}`.`{$parentPk}`" .
                " LIMIT 1) AS `{$aggAlias}`";
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

            $firstKeyProp = $ref->getProperty('firstKey');     // ex: subjects.category_id
            $firstKeyProp->setAccessible(true);
            $firstKey = $firstKeyProp->getValue($rel);

            $secondKeyProp = $ref->getProperty('secondKey');   // ex: votes.subject_id
            $secondKeyProp->setAccessible(true);
            $secondKey = $secondKeyProp->getValue($rel);

            $secondLocalProp = $ref->getProperty('secondLocal'); // ex: subjects.id
            $secondLocalProp->setAccessible(true);
            $secondLocal = $secondLocalProp->getValue($rel);

            // Resolva tabellnamn från klass eller tabellsträng
            $resolveTable = function (string $classOrTable): string {
                if (class_exists($classOrTable)) {
                    $m = new $classOrTable();
                    return $m->getTable();
                }
                return $classOrTable;
            };

            $relatedTable = $resolveTable($relatedClassOrTable); // t.ex. votes
            $throughTable = $resolveTable($throughClassOrTable); // t.ex. subjects

            // Aggregat via JOIN genom mellanmodellen
            $this->columns[] =
                "(SELECT {$fn}(`r`.`{$column}`)" .
                " FROM `{$relatedTable}` AS r" .
                " INNER JOIN `{$throughTable}` AS t ON t.`{$secondLocal}` = r.`{$secondKey}`" .
                " WHERE t.`{$firstKey}` = `{$parentTable}`.`{$parentPk}`" .
                ") AS `{$aggAlias}`";
            return;
        }

        // HasOne
        if ($rel instanceof \Radix\Database\ORM\Relationships\HasOne) {
            $fkProp = (new \ReflectionClass($rel))->getProperty('foreignKey');
            $fkProp->setAccessible(true);
            $foreignKey = $fkProp->getValue($rel);

            // Hämta relaterad tabell via modellklass i HasOne
            $mcProp = (new \ReflectionClass($rel))->getProperty('modelClass');
            $mcProp->setAccessible(true);
            $modelClass = $mcProp->getValue($rel);
            $relatedInstance = new $modelClass();
            $relatedTable = $relatedInstance->getTable();

            $this->columns[] = "(SELECT {$fn}(`{$relatedTable}`.`{$column}`) FROM `{$relatedTable}` WHERE `{$relatedTable}`.`{$foreignKey}` = `{$parentTable}`.`{$parentPk}`) AS `{$aggAlias}`";
            return;
        }

        // BelongsTo
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

            // related.ownerKey = parent.foreignKey
            $this->columns[] = "(SELECT {$fn}(`{$relatedTable}`.`{$column}`) FROM `{$relatedTable}` WHERE `{$relatedTable}`.`{$ownerKey}` = `{$parentTable}`.`{$parentForeignKey}`) AS `{$aggAlias}`";
            return;
        }

        // BelongsToMany
        if ($rel instanceof \Radix\Database\ORM\Relationships\BelongsToMany) {
            // Hämta metadata från relationen
            $pivotTable = $rel->getPivotTable();
            $foreignPivotKey = $rel->getForeignPivotKey();

            // Relaterad tabell från relaterad modellklass
            $relatedClass = $rel->getRelatedModelClass();
            /** @var \Radix\Database\ORM\Model $relatedInstance */
            $relatedInstance = new $relatedClass();
            $relatedTable = $relatedInstance->getTable();

            $relatedPivotKeyProp = (new \ReflectionClass($rel))->getProperty('relatedPivotKey');
            $relatedPivotKeyProp->setAccessible(true);
            $relatedPivotKey = $relatedPivotKeyProp->getValue($rel);

            // JOIN pivot -> related, filter pivot.foreignPivotKey = parent.pk
            $this->columns[] = "(SELECT {$fn}(`related`.`{$column}`) 
                                 FROM `{$relatedTable}` AS related
                                 INNER JOIN `{$pivotTable}` AS pivot
                                   ON related.`id` = pivot.`{$relatedPivotKey}`
                                 WHERE pivot.`{$foreignPivotKey}` = `{$parentTable}`.`{$parentPk}`) AS `{$aggAlias}`";
            return;
        }

        throw new \InvalidArgumentException("withAggregate() does not support relation type for '$relation'.");
    }

    protected function addRelationCountSelect(string $relation): void
    {
        /** @var \Radix\Database\ORM\Model $parent */
        $parent = new $this->modelClass();
        $parentTable = trim($this->table, '`'); // unwrap table-name if wrapped
        $parentPk = $parent::getPrimaryKey();

        if (!method_exists($parent, $relation)) {
            throw new \InvalidArgumentException("Relation '$relation' is not defined in model {$this->modelClass}.");
        }

        $rel = $parent->$relation();
        $parentSingular = \Radix\Support\StringHelper::singularize($parentTable);
        $fkGuess = $parentSingular . '_id';
        $relatedTableGuess = $relation;

        if ($rel instanceof \Radix\Database\ORM\Relationships\HasMany) {
            try {
                $relatedModelClass = 'App\\Models\\' . ucfirst(\Radix\Support\StringHelper::singularize($relation));
                if (class_exists($relatedModelClass)) {
                    $relatedInstance = new $relatedModelClass();
                    $relatedTable = $relatedInstance->getTable();
                } else {
                    $relatedTable = $relatedTableGuess;
                }
            } catch (\Throwable) {
                $relatedTable = $relatedTableGuess;
            }

            $fkProp = (new \ReflectionClass($rel))->getProperty('foreignKey');
            $fkProp->setAccessible(true);
            $foreignKey = $fkProp->getValue($rel);

            $this->columns[] = "(SELECT COUNT(*) FROM `{$relatedTable}` WHERE `{$relatedTable}`.`{$foreignKey}` = `{$parentTable}`.`{$parentPk}`) AS `{$relation}_count`";
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
                "(SELECT COUNT(*)" .
                " FROM `{$relatedTable}` AS r" .
                " INNER JOIN `{$throughTable}` AS t ON t.`{$secondLocal}` = r.`{$secondKey}`" .
                " WHERE t.`{$firstKey}` = `{$parentTable}`.`{$parentPk}`" .
                " LIMIT 1) AS `{$relation}_count`";
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

            $firstKeyProp = $ref->getProperty('firstKey');     // t.ex. subjects.category_id
            $firstKeyProp->setAccessible(true);
            $firstKey = $firstKeyProp->getValue($rel);

            $secondKeyProp = $ref->getProperty('secondKey');   // t.ex. votes.subject_id
            $secondKeyProp->setAccessible(true);
            $secondKey = $secondKeyProp->getValue($rel);

            $secondLocalProp = $ref->getProperty('secondLocal'); // t.ex. subjects.id
            $secondLocalProp->setAccessible(true);
            $secondLocal = $secondLocalProp->getValue($rel);

            // Resolva tabellnamn från klass eller tabellsträng
            $resolveTable = function (string $classOrTable): string {
                if (class_exists($classOrTable)) {
                    $m = new $classOrTable();
                    return $m->getTable();
                }
                return $classOrTable;
            };

            $relatedTable = $resolveTable($relatedClassOrTable); // ex: votes
            $throughTable = $resolveTable($throughClassOrTable); // ex: subjects

            $this->columns[] =
                "(SELECT COUNT(*)" .
                " FROM `{$relatedTable}` AS r" .
                " INNER JOIN `{$throughTable}` AS t ON t.`{$secondLocal}` = r.`{$secondKey}`" .
                " WHERE t.`{$firstKey}` = `{$parentTable}`.`{$parentPk}`" .
                ") AS `{$relation}_count`";
            return;
        }

        if ($rel instanceof \Radix\Database\ORM\Relationships\BelongsToMany) {
            $pivotTable = $rel->getPivotTable();
            $foreignPivotKey = $rel->getForeignPivotKey();
            $this->columns[] = "(SELECT COUNT(*) FROM `{$pivotTable}` WHERE `{$pivotTable}`.`{$foreignPivotKey}` = `{$parentTable}`.`{$parentPk}`) AS `{$relation}_count`";
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

            $this->columns[] = "(SELECT COUNT(*) FROM `{$relatedTable}` WHERE `{$relatedTable}`.`{$foreignKey}` = `{$parentTable}`.`{$parentPk}`) AS `{$relation}_count`";
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

            $this->columns[] = "(SELECT COUNT(*) FROM `{$relatedTable}` WHERE `{$relatedTable}`.`{$ownerKey}` = `{$parentTable}`.`{$parentForeignKey}`) AS `{$relation}_count`";
            return;
        }

        throw new \InvalidArgumentException("withCount() does not support relation type for '$relation'.");
    }

    /**
     * Räkna relaterade poster med ett extra WHERE-filter och alias.
     *
     * Stöder: HasMany, HasOne, BelongsTo, BelongsToMany, HasManyThrough, HasOneThrough.
     *
     * Exempel (HasOneThrough):
     *  Category::query()
     *      ->withCountWhere('topVote', 'status', 'approved', 'topVote_approved')
     *      ->get();
     */
    public function withCountWhere(string $relation, string $column, mixed $value, ?string $alias = null): self
    {
        if ($this->modelClass === null) {
            throw new \LogicException("Model class is not set. Use setModelClass() before calling withCountWhere().");
        }

        /** @var \Radix\Database\ORM\Model $parent */
        $parent = new $this->modelClass();
        $parentTable = trim($this->table, '`');
        $parentPk = $parent::getPrimaryKey();

        if (!method_exists($parent, $relation)) {
            throw new \InvalidArgumentException("Relation '$relation' is not defined in model {$this->modelClass}.");
        }

        $rel = $parent->$relation();
        $aggAlias = $alias ?: "{$relation}_count_{$value}";

        // Normalisera jämförelsevärde (literal i SQL)
        $valSql = is_int($value) || is_float($value)
            ? (string)$value
            : ("'".addslashes((string)$value)."'");

        // HasMany
        if ($rel instanceof \Radix\Database\ORM\Relationships\HasMany) {
            $relatedModelClassProp = (new \ReflectionClass($rel))->getProperty('modelClass');
            $relatedModelClassProp->setAccessible(true);
            $relatedClass = $relatedModelClassProp->getValue($rel);
            $relatedInstance = class_exists($relatedClass) ? new $relatedClass() : null;
            $relatedTable = $relatedInstance ? $relatedInstance->getTable() : $relation;

            $fkProp = (new \ReflectionClass($rel))->getProperty('foreignKey');
            $fkProp->setAccessible(true);
            $foreignKey = $fkProp->getValue($rel);

            $this->columns[] =
                "(SELECT COUNT(*) FROM `{$relatedTable}` " .
                "WHERE `{$relatedTable}`.`{$foreignKey}` = `{$parentTable}`.`{$parentPk}` " .
                "AND `{$relatedTable}`.`{$column}` = {$valSql}) AS `{$aggAlias}`";

            $this->withAggregateExpressions[] = $aggAlias;
            return $this;
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

            $resolve = function (string $classOrTable): string {
                if (class_exists($classOrTable)) {
                    $m = new $classOrTable();
                    return $m->getTable();
                }
                return $classOrTable;
            };

            $relatedTable = $resolve($relatedClassOrTable);
            $throughTable = $resolve($throughClassOrTable);

            $this->columns[] =
                "(SELECT COUNT(*)" .
                " FROM `{$relatedTable}` AS r" .
                " INNER JOIN `{$throughTable}` AS t ON t.`{$secondLocal}` = r.`{$secondKey}`" .
                " WHERE t.`{$firstKey}` = `{$parentTable}`.`{$parentPk}`" .
                " AND r.`{$column}` = {$valSql}" .
                " LIMIT 1) AS `{$aggAlias}`";

            $this->withAggregateExpressions[] = $aggAlias;
            return $this;
        }

        // HasManyThrough
        if ($rel instanceof \Radix\Database\ORM\Relationships\HasManyThrough) {
            $ref = new \ReflectionClass($rel);

            $relatedProp = $ref->getProperty('related');
            $relatedProp->setAccessible(true);
            $relatedClassOrTable = $relatedProp->getValue($rel);

            $throughProp = $ref->getProperty('through');
            $throughProp->setAccessible(true);
            $throughClassOrTable = $throughProp->getValue($rel);

            $firstKeyProp = $ref->getProperty('firstKey');     // t.ex. subjects.category_id
            $firstKeyProp->setAccessible(true);
            $firstKey = $firstKeyProp->getValue($rel);

            $secondKeyProp = $ref->getProperty('secondKey');   // t.ex. votes.subject_id
            $secondKeyProp->setAccessible(true);
            $secondKey = $secondKeyProp->getValue($rel);

            $secondLocalProp = $ref->getProperty('secondLocal'); // t.ex. subjects.id
            $secondLocalProp->setAccessible(true);
            $secondLocal = $secondLocalProp->getValue($rel);

            // Resolva tabellnamn från klass eller tabellsträng
            $resolve = function (string $classOrTable): string {
                if (class_exists($classOrTable)) {
                    $m = new $classOrTable();
                    return $m->getTable();
                }
                // anta redan tabellnamn
                return $classOrTable;
            };

            $relatedTable = $resolve($relatedClassOrTable); // "votes"
            $throughTable = $resolve($throughClassOrTable); // "subjects"

            // Bygg subquery: COUNT relaterade rader med filter på kolumn + värde
            $this->columns[] =
                "(SELECT COUNT(*) " .
                " FROM `{$relatedTable}` AS r" .
                " INNER JOIN `{$throughTable}` AS t ON t.`{$secondLocal}` = r.`{$secondKey}`" .
                " WHERE t.`{$firstKey}` = `{$parentTable}`.`{$parentPk}`" .
                " AND r.`{$column}` = {$valSql}" .
                ") AS `{$aggAlias}`";

            $this->withAggregateExpressions[] = $aggAlias;
            return $this;
        }

        // HasOne
        if ($rel instanceof \Radix\Database\ORM\Relationships\HasOne) {
            $fkProp = (new \ReflectionClass($rel))->getProperty('foreignKey');
            $fkProp->setAccessible(true);
            $foreignKey = $fkProp->getValue($rel);

            $mcProp = (new \ReflectionClass($rel))->getProperty('modelClass');
            $mcProp->setAccessible(true);
            $modelClass = $mcProp->getValue($rel);
            $relatedInstance = new $modelClass();
            $relatedTable = $relatedInstance->getTable();

            $this->columns[] =
                "(SELECT COUNT(*) FROM `{$relatedTable}` " .
                "WHERE `{$relatedTable}`.`{$foreignKey}` = `{$parentTable}`.`{$parentPk}` " .
                "AND `{$relatedTable}`.`{$column}` = {$valSql}) AS `{$aggAlias}`";

            $this->withAggregateExpressions[] = $aggAlias;
            return $this;
        }

        // BelongsTo
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

            $this->columns[] =
                "(SELECT COUNT(*) FROM `{$relatedTable}` " .
                "WHERE `{$relatedTable}`.`{$ownerKey}` = `{$parentTable}`.`{$parentForeignKey}` " .
                "AND `{$relatedTable}`.`{$column}` = {$valSql}) AS `{$aggAlias}`";

            $this->withAggregateExpressions[] = $aggAlias;
            return $this;
        }

        // BelongsToMany
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
                "(SELECT COUNT(*) " .
                "FROM `{$pivotTable}` AS pivot " .
                "INNER JOIN `{$relatedTable}` AS related ON related.`id` = pivot.`{$relatedPivotKey}` " .
                "WHERE pivot.`{$foreignPivotKey}` = `{$parentTable}`.`{$parentPk}` " .
                "AND related.`{$column}` = {$valSql}) AS `{$aggAlias}`";

            $this->withAggregateExpressions[] = $aggAlias;
            return $this;
        }

        throw new \InvalidArgumentException("withCountWhere() does not support relation type for '$relation'.");
    }

    public function setModelClass(string $modelClass): self
    {
        if (!class_exists($modelClass)) {
            throw new \InvalidArgumentException("Model class '$modelClass' does not exist.");
        }

        $this->modelClass = $modelClass;
        return $this;
    }

    public function first()
    {
        if (is_null($this->modelClass)) {
            throw new \LogicException("Model class is not set. Use setModelClass() before calling first().");
        }

        $this->limit(1);
        $results = $this->get();

        if (empty($results)) {
            return null;
        }

        $result = $results[0];

        // Verifiera att resultatet är en modellinstans
        if (!$result instanceof $this->modelClass) {
            $modelInstance = new $this->modelClass();
            $modelInstance->fill($result); // Fyll modellen
            $modelInstance->markAsExisting(); // Markera modellen som existerande
            return $modelInstance;
        }

        $result->markAsExisting(); // Ifall det redan är en modell, markera den som existerande.
        return $result;
    }

    public function get(): array
    {
        if (is_null($this->modelClass)) {
            throw new \LogicException("Model class is not set. Use setModelClass() before calling get().");
        }

        // Låt parent::get() sköta hydrering + eager loading
        return parent::get();
    }

    public function with(array|string $relations): self
    {
        if (!$this->modelClass) {
            throw new \LogicException('Model class is not set. Use setModelClass() to assign a model.');
        }

        // Se till att $relations alltid är en array
        $relations = is_array($relations) ? $relations : func_get_args();

        foreach ($relations as $relation) {
            $method = $relation;

            // Kontrollera att metoden för relationen existerar
            if (!method_exists($this->modelClass, $method)) {
                throw new \InvalidArgumentException("Relation '{$relation}' is not defined in the model '{$this->modelClass}'.");
            }

            // Reservera för att senare ladda upp relationerna
            $this->eagerLoadRelations[] = $relation;
        }

        return $this;
    }

    public function withSoftDeletes(): self
    {
        $this->withSoftDeletes = true;

        // Ta bort tidigare tillämpade `deleted_at IS NULL` om det redan existerar
        $this->where = array_filter($this->where, function ($condition) {
            // Identifiera och ta bort `WHERE deleted_at IS NULL`
            return !(
                $condition['type'] === 'raw' &&
                $condition['column'] === $this->wrapColumn('deleted_at') &&
                $condition['operator'] === 'IS NULL'
            );
        });

        return $this;
    }

    public function getWithSoftDeletes(): bool
    {
        return $this->withSoftDeletes;
    }

    public function distinct(bool $value = true): self
    {
        $this->distinct = $value;
        return $this;
    }

    /**
     * Ange vilken tabell frågan ska baseras på.
     */
    public function from(string $table): self
    {
        $table = trim($table);
        if (empty($table)) {
            throw new \InvalidArgumentException("Table name cannot be empty.");
        }

        // Hantera tabell med alias (AS).
        if (preg_match('/\s+AS\s+/i', $table)) {
            [$tableName, $alias] = preg_split('/\s+AS\s+/i', $table, 2);
            $this->table = $this->wrapColumn($tableName).' AS '.$this->wrapAlias($alias);
        } else {
            $this->table = $this->wrapColumn($table);
        }

        return $this;
    }

    public function where(string|QueryBuilder|\Closure $column, string $operator = null, mixed $value = null, string $boolean = 'AND'): self
    {
        if ($column instanceof \Closure) {
            // Hanterar Closure som skickas för inkapslade villkor
            $query = new static(); // Skapa en ny instans av QueryBuilder
            $column($query);

            // Kontrollera om några WHERE-betingelser skapades i underfrågan
            if (!empty($query->where)) {
                $this->where[] = [
                    'type' => 'nested',
                    'query' => $query,
                    'boolean' => $boolean
                ];
                $this->bindings = array_merge($this->bindings, $query->getBindings());
            }
        } else {
            if (empty(trim($column))) {
                throw new \InvalidArgumentException("The column name in WHERE clause cannot be empty.");
            }

            $validOperators = ['=', '!=', '<', '<=', '>', '>=', 'LIKE', 'NOT LIKE', 'IN', 'NOT IN', 'BETWEEN', 'IS', 'IS NOT'];
            if (!in_array(strtoupper($operator), $validOperators, true)) {
                throw new \InvalidArgumentException("Invalid operator '{$operator}' in WHERE clause.");
            }

            // Hantera "IS NULL" och "IS NOT NULL"
            if (strtoupper($operator) === 'IS' || strtoupper($operator) === 'IS NOT') {
                $this->where[] = [
                    'type' => 'raw',
                    'column' => $this->wrapColumn($column),
                    'operator' => $operator,
                    'value' => null,
                    'boolean' => $boolean,
                ];
            } elseif ($value instanceof QueryBuilder) {
                // Subqueries stöds här
                $valueSql = '(' . $value->toSql() . ')';
                $this->bindings = array_merge($this->bindings, $value->getBindings());
                $this->where[] = [
                    'type' => 'subquery',
                    'column' => $this->wrapColumn($column),
                    'operator' => $operator,
                    'value' => $valueSql,
                    'boolean' => $boolean,
                ];
            } else {
                // Hantera direktvärden
                $this->bindings[] = $value;
                $this->where[] = [
                    'type' => 'raw',
                    'column' => $this->wrapColumn($column),
                    'operator' => $operator,
                    'value' => '?',
                    'boolean' => $boolean,
                ];
            }
        }

        return $this;
    }

    /**
     * Lägg till en WHERE IN-betingelse.
     */
    public function whereIn(string $column, array $values, string $boolean = 'AND'): self
    {
        if (empty($values)) {
            throw new \InvalidArgumentException("Argumentet 'values' måste innehålla minst ett värde för whereIn.");
        }

        // Skapa en lista av platsägare (placeholders) baserat på antalet värden
        $placeholders = implode(', ', array_fill(0, count($values), '?'));

        // Lägg till villkoret till WHERE-klassens array
        $this->where[] = [
            'type' => 'list', // Märk detta som en "list"-typ för att särskilja det
            'column' => $this->wrapColumn($column),
            'operator' => 'IN',
            'value' => "($placeholders)", // SQL, ex: (?,?,?)
            'boolean' => $boolean, // AND eller OR
        ];

        // Lägg till värden i bindningsparametrarna
        $this->bindings = array_merge($this->bindings, $values);

        return $this;
    }

    /**
     * Lägg till OR WHERE-betingelser.
     */
    public function orWhere(string $column, string $operator, mixed $value): self
    {
        return $this->where($column, $operator, $value, 'OR');
    }

    public function whereNull(string $column, string $boolean = 'AND'): self
    {
        // Specifik hantering för `deleted_at`
        if ($column === 'deleted_at' && $this->getWithSoftDeletes()) {
            // Ignorera `whereNull('deleted_at')` om `withSoftDeletes` är aktiverat
            return $this;
        }

        // Lägg till standardvillkor
        $this->where[] = [
            'type' => 'raw',
            'column' => $this->wrapColumn($column),
            'operator' => 'IS NULL',
            'value' => null,
            'boolean' => $boolean,
        ];

        return $this;
    }

    /**
     * Lägg till en WHERE IS NOT NULL-betingelse.
     */
    public function whereNotNull(string $column, string $boolean = 'AND'): self
    {
        // Först: Ta bort alla motsägande villkor (t.ex. IS NULL) från `where`
        $this->where = array_filter($this->where, function ($condition) use ($column, $boolean) {
            return !(
                $condition['type'] === 'raw' &&
                $condition['column'] === $this->wrapColumn($column) &&
                $condition['operator'] === 'IS NULL' &&
                $condition['boolean'] === $boolean
            );
        });

        // Kontrollera om villkoret redan finns
        foreach ($this->where as $condition) {
            if (
                $condition['type'] === 'raw' &&
                $condition['column'] === $this->wrapColumn($column) &&
                $condition['operator'] === 'IS NOT NULL' &&
                $condition['boolean'] === $boolean
            ) {
                return $this; // Inget behov av att lägga till dubbletter
            }
        }

        // Lägg till villkoret
        $this->where[] = [
            'type' => 'raw',
            'column' => $this->wrapColumn($column),
            'operator' => 'IS NOT NULL',
            'value' => null,
            'boolean' => $boolean,
        ];

        return $this;
    }

    public function leftJoin(string $table, string $first, string $operator, string $second): self
    {
        $this->joins[] = "LEFT JOIN ".$this->wrapColumn($table)." ON ".$this->wrapColumn($first)." $operator ".$this->wrapColumn($second);
        return $this;
    }

    public function exists(): bool
    {
        // Bygg en SELECT 1 som returnerar det första resultatet
        $this->selectRaw('1')->limit(1);

        // Kör frågan och returnera sann om det finns resultat
        $result = $this->connection->fetchOne($this->toSql(), $this->bindings);
        return $result !== false;
    }

     public function paginate(int $perPage = 10, int $currentPage = 1): array
    {
        // Säkerställ att $currentPage är ett positivt heltal
        $currentPage = ($currentPage > 0) ? $currentPage : 1;

        // Beräkna offset baserat på aktuell sida och antal poster per sida
        $offset = ($currentPage - 1) * $perPage;

        // Klona QueryBuilder för att göra en separat fråga för att räkna totalen
        $countQuery = clone $this;

        // Rensa specifika delar för att beräkna totalen utan LIMIT, OFFSET, ORDER BY
        $countQuery->columns = [];
        $countQuery->orderBy = [];
        $countQuery->limit = null;
        $countQuery->offset = null;

        // Ställ in COUNT-frågan
        $countQuery->selectRaw('COUNT(*) as total');

        // Kör COUNT-frågan för att hämta totalantalet rader
        $countResult = $this->connection->fetchOne($countQuery->toSql(), $countQuery->getBindings());
        $totalRecords = $countResult['total'] ?? 0;

        // Beräkna totalt antal sidor
        $lastPage = (int) ceil($totalRecords / $perPage);

        // Om den begärda sidan är större än sista sidan, sätt sidan till sista sidan
        if ($currentPage > $lastPage && $lastPage > 0) {
            $currentPage = $lastPage;
            $offset = ($currentPage - 1) * $perPage; // Uppdatera offset
        }

        // Kontrollera om det finns några resultat
        if ($totalRecords === 0) {
            return [
                'data' => [],
                'pagination' => [
                    'total' => 0,
                    'per_page' => $perPage,
                    'current_page' => $currentPage,
                    'last_page' => $lastPage,
                    'first_page' => 1,
                ],
            ];
        }

        // Begränsa resultaten för den aktuella sidan
        $this->limit($perPage)->offset($offset);

        // Hämta de faktiska resultaten
        $data = $this->get();

        // Returnera data och pagineringsmetadata
        return [
            'data' => $data,
            'pagination' => [
                'total' => $totalRecords,
                'per_page' => $perPage,
                'current_page' => $currentPage,
                'last_page' => $lastPage,
                'first_page' => 1,
            ],
        ];
    }

    /**
     * Utför en sökning baserat på angivna kolumner och sökterm.
     */
    public function search(string $term, array $searchColumns, int $perPage = 10, int $currentPage = 1): array
    {
        $currentPage = ($currentPage > 0) ? $currentPage : 1;

        // Lägg INTE på deleted_at här (Model::query() gör det). Vi grupperar istället sökvillkoren.
        if (!empty($searchColumns)) {
            $this->where(function (self $q) use ($term, $searchColumns) {
                $first = true;
                foreach ($searchColumns as $column) {
                    if ($first) {
                        $q->where($column, 'LIKE', "%$term%");
                        $first = false;
                    } else {
                        $q->orWhere($column, 'LIKE', "%$term%");
                    }
                }
            });
        }

        $countQuery = clone $this;
        $countQuery->columns = [];
        $countQuery->orderBy = [];
        $countQuery->limit = null;
        $countQuery->offset = null;
        $countQuery->selectRaw('COUNT(*) as total');

        $countResult = $this->connection->fetchOne($countQuery->toSql(), $countQuery->getBindings());
        $totalRecords = $countResult['total'] ?? 0;

        $lastPage = (int) ceil($totalRecords / $perPage);
        if ($currentPage > $lastPage && $lastPage > 0) {
            $currentPage = $lastPage;
        }

        $this->limit($perPage)->offset(($currentPage - 1) * $perPage);
        $data = $this->get();

        return [
            'data' => $data,
            'search' => [
                'term' => $term,
                'total' => $totalRecords,
                'per_page' => $perPage,
                'current_page' => $currentPage,
                'last_page' => $lastPage,
                'first_page' => 1,
            ],
        ];
    }

    public function getOnlySoftDeleted(): self
    {
        return $this->whereNotNull('deleted_at');
    }

    /**
     * Lägg till en OR WHERE IS NOT NULL-betingelse.
     */
    public function orWhereNotNull(string $column): self
    {
        return $this->whereNotNull($column, 'OR');
    }

    protected function buildWhere(): string
    {
        if (empty($this->where)) {
            return '';
        }

        $conditions = [];
        foreach ($this->where as $condition) {
            switch ($condition['type']) {
                case 'raw':
                case 'list':
                case 'subquery':
                    // Om villkoret är en "IS NULL" eller "IS NOT NULL", exkludera värde-bindning
                    if (in_array(strtoupper($condition['operator']), ['IS', 'IS NOT'], true) && $condition['value'] === null) {
                        $conditions[] = trim("{$condition['boolean']} {$condition['column']} {$condition['operator']} NULL");
                    } else {
                        $conditions[] = trim("{$condition['boolean']} {$condition['column']} {$condition['operator']} {$condition['value']}");
                    }
                    break;

                case 'nested':
                    // Hantera nested queries
                    $nestedWhere = $condition['query']->buildWhere();
                    $nestedWhere = preg_replace('/^WHERE\s+/i', '', $nestedWhere); // Ta bort "WHERE" i börjar
                    $conditions[] = "{$condition['boolean']} ({$nestedWhere})";
                    break;

                default:
                    throw new \LogicException("Unknown condition type: {$condition['type']}");
            }
        }

        // Ta bort första `AND`/`OR` innan vi sammanfogar och lägg till "WHERE"
        $sql = implode(' ', $conditions);
        return 'WHERE ' . preg_replace('/^(AND|OR)\s+/i', '', trim($sql));
    }

    /**
     * Lägg till WHERE LIKE-betingelser.
     */
    public function whereLike(string $column, string $value, string $boolean = 'AND'): self
    {
        return $this->where($column, 'LIKE', $value, $boolean);
    }

    /**
     * Lägg till en ORDER BY-klasul.
     */
    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        // Använd wrapColumn för att säkra korrekt formatering.
        $this->orderBy[] = $this->wrapColumn($column)." $direction";
        return $this;
    }

    /**
     * Lägg till en JOIN-betingelse.
     */
    public function join(string $table, string $first, string $operator, string $second, string $type = 'INNER'): self
    {
        $this->joins[] = "$type JOIN ".$this->wrapColumn($table)." ON ".$this->wrapColumn($first)." $operator ".$this->wrapColumn($second);
        return $this;
    }


    /**
     * Lägg till kolumner för GROUP BY.
     */
    public function groupBy(string ...$columns): self
    {
        foreach ($columns as $column) {
            $this->groupBy[] = $this->wrapColumn($column);
        }
        return $this;
    }

    /**
     * Definiera en HAVING-betingelse.
     */
    public function having(string $column, string $operator, mixed $value): self
    {
        $formattedColumn = $this->wrapAlias($column);
        $this->having = "$formattedColumn $operator ?";

        // Lägg till bindningsvärdet
        $this->bindings[] = $value;

        return $this;
    }

    /**
     * Lägg till en UNION med en annan fråga.
     */
    public function union(string|QueryBuilder $query, bool $all = false): self
    {
        if ($query instanceof QueryBuilder) {
            $this->bindings = array_merge($this->bindings, $query->getBindings());
            $query = $query->toSql();
        }

        $this->unions[] = ($all ? 'UNION ALL ' : 'UNION ').$query;

        return $this;
    }

    /**
     * Lägg till en LIMIT-parameter.
     */
    public function limit(int $limit): self
    {
        $this->limit = $limit;
        return $this;
    }

    /**
     * Lägg till en OFFSET-parameter.
     */
    public function offset(int $offset): self
    {
        $this->offset = $offset;
        return $this;
    }

    /**
     * Lägg till COUNT-funktion till SELECT.
     */
    public function count(string $column = '*', string $alias = 'count'): self
    {
        // Ta bort standardkolumnen '*'
        if ($this->columns === ['*']) {
            $this->columns = [];
        }

        $column = $this->wrapColumn($column);
        $this->columns[] = "COUNT($column) AS `".addslashes($alias)."`";
        return $this;
    }


    /**
     * Lägg till AVG-funktion till SELECT.
     */
    public function avg(string $column, string $alias = 'average'): self
    {
        // Ta bort standardkolumnen '*'
        if ($this->columns === ['*']) {
            $this->columns = [];
        }

        $column = $this->wrapColumn($column);
        $this->columns[] = "AVG($column) AS `$alias`";
        return $this;
    }

    /**
     * Lägg till SUM-funktion till SELECT.
     */
    public function sum(string $column, string $alias = 'sum'): self
    {
        // Ta bort standardkolumnen '*'
        if ($this->columns === ['*']) {
            $this->columns = [];
        }

        $column = $this->wrapColumn($column);
        $this->columns[] = "SUM($column) AS `$alias`";
        return $this;
    }

    /**
     * Lägg till MAX-funktion till SELECT.
     */
    public function max(string $column, string $alias = 'max'): self
    {
        // Ta bort standardkolumnen '*'
        if ($this->columns === ['*']) {
            $this->columns = [];
        }

        $column = $this->wrapColumn($column);
        $this->columns[] = "MAX($column) AS `$alias`";
        return $this;
    }

    /**
     * Lägg till MIN-funktion till SELECT.
     */
    public function min(string $column, string $alias = 'min'): self
    {
        // Ta bort standardkolumnen '*'
        if ($this->columns === ['*']) {
            $this->columns = [];
        }

        $column = $this->wrapColumn($column);
        $this->columns[] = "MIN($column) AS `$alias`";
        return $this;
    }

    public function addExpression(string $expression): self
    {
        $this->columns[] = $expression;
        return $this;
    }

    /**
     * Lägg till CONCAT-funktion till SELECT.
     */
    public function concat(array $columns, string $alias): self
    {
        if ($this->columns === ['*']) {
            $this->columns = [];
        }

        $wrappedColumns = array_map(function ($col) {
            if (preg_match("/^'.*'$/", $col)) {
                return $col; // Returnera sträng direkt om korrekt formaterad
            }

            return $this->wrapColumn($col); // Omslut endast nödvändiga kolumner
        }, $columns);

        $concatExpression = 'CONCAT('.implode(', ', $wrappedColumns).')';
        $this->columns[] = "$concatExpression AS `$alias`";

        return $this;
    }

    /**
     * Lägg till UPPER-funktion till SELECT.
     */
    public function upper(string $column, string $alias = null): self
    {
        // Ta bort standardkolumnen '*' om den finns
        if ($this->columns === ['*']) {
            $this->columns = [];
        }

        $column = $this->wrapColumn($column);
        $alias = $alias ?: "upper_{$column}";
        $this->columns[] = "UPPER($column) AS `$alias`";
        return $this;
    }

    /**
     * Lägg till LOWER-funktion till SELECT.
     */
    public function lower(string $column, string $alias = null): self
    {
        // Ta bort standardkolumnen '*' om den finns
        if ($this->columns === ['*']) {
            $this->columns = [];
        }

        $column = $this->wrapColumn($column);
        $alias = $alias ?: "lower_{$column}";
        $this->columns[] = "LOWER($column) AS `$alias`";
        return $this;
    }

    /**
     * Lägg till YEAR-funktion till SELECT.
     */
    public function year(string $column, string $alias = null): self
    {
        // Ta bort standardkolumnen '*' om den finns
        if ($this->columns === ['*']) {
            $this->columns = [];
        }

        $column = $this->wrapColumn($column);
        $alias = $alias ?: "year_{$column}";
        $this->columns[] = "YEAR($column) AS `$alias`";
        return $this;
    }

    /**
     * Lägg till MONTH-funktion till SELECT.
     */
    public function month(string $column, string $alias = null): self
    {
        // Ta bort standardkolumnen '*' om den finns
        if ($this->columns === ['*']) {
            $this->columns = [];
        }

        $column = $this->wrapColumn($column);
        $alias = $alias ?: "month_{$column}";
        $this->columns[] = "MONTH($column) AS `$alias`";
        return $this;
    }

    /**
     * Lägg till DATE-funktion till SELECT.
     */
    public function date(string $column, string $alias = null): self
    {
        // Ta bort standardkolumnen '*' om den finns
        if ($this->columns === ['*']) {
            $this->columns = [];
        }

        $column = $this->wrapColumn($column);
        $alias = $alias ?: "date_{$column}";
        $this->columns[] = "DATE($column) AS `$alias`";
        return $this;
    }

    public function selectRaw(string $rawExpression): self
    {
        // Ta bort standardkolumnen '*' om den finns
        if ($this->columns === ['*']) {
            $this->columns = [];
        }

        // Lägg till det råa uttrycket direkt utan formatering
        $this->columns[] = $rawExpression;
        return $this;
    }

    /**
     * Lägg till ROUND-funktion till SELECT.
     */
    public function round(string $column, int $decimals = 0, string $alias = null): self
    {
        $column = $this->wrapColumn($column);
        $alias = $alias ?: "round_{$column}";
        $this->columns[] = "ROUND($column, $decimals) AS `$alias`";
        return $this;
    }

    /**
     * Lägg till CEIL-funktion till SELECT.
     */
    public function ceil(string $column, string $alias = null): self
    {
        // Ta bort standardkolumnen '*' om den finns
        if ($this->columns === ['*']) {
            $this->columns = [];
        }

        $column = $this->wrapColumn($column);
        $alias = $alias ?: "ceil_{$column}";
        $this->columns[] = "CEIL($column) AS `$alias`";
        return $this;
    }

    /**
     * Lägg till FLOOR-funktion till SELECT.
     */
    public function floor(string $column, string $alias = null): self
    {
        // Ta bort standardkolumnen '*' om den finns
        if ($this->columns === ['*']) {
            $this->columns = [];
        }

        $column = $this->wrapColumn($column);
        $alias = $alias ?: "floor_{$column}";
        $this->columns[] = "FLOOR($column) AS `$alias`";
        return $this;
    }

    /**
     * Lägg till ABS-funktion till SELECT.
     */
    public function abs(string $column, string $alias = null): self
    {
        // Ta bort standardkolumnen '*' om den finns
        if ($this->columns === ['*']) {
            $this->columns = [];
        }

        $column = $this->wrapColumn($column);
        $alias = $alias ?: "abs_{$column}";
        $this->columns[] = "ABS($column) AS `$alias`";
        return $this;
    }

    public function fullJoin(string $table, string $first, string $operator, string $second): self
    {
        $this->joins[] = "FULL OUTER JOIN ".$this->wrapColumn($table)." ON ".$this->wrapColumn($first)." $operator ".$this->wrapColumn($second);
        return $this;
    }

    public function joinSub(
        QueryBuilder $subQuery,
        string $alias,
        string $first,
        string $operator,
        string $second,
        string $type = 'INNER'
    ): self {
        $subQuerySql = '('.$subQuery->toSql().') AS '.$this->wrapAlias($alias);
        $this->bindings = array_merge($this->bindings, $subQuery->getBindings());
        $this->joins[] = "$type JOIN $subQuerySql ON ".$this->wrapColumn($first)." $operator ".$this->wrapColumn($second);
        return $this;
    }

    public function debugSql(): string
    {
        // Bygg SQL-strängen
        $query = $this->toSql();

        // Ersätt placeholders (?) med bindningsvärden
        foreach ($this->bindings as $binding) {
            $replacement = is_string($binding) ? "'" . addslashes($binding) . "'" : $binding;
            $query = preg_replace('/\?/', $replacement, $query, 1); // Ersätt en placeholder i taget
        }

        // Returnera den kompletta SQL-strängen
        return $query;
    }

    /**
     * Bygg och returnera den slutgiltiga SQL-frågan.
     */
    public function toSql(): string
    {
        // INSERT
        if ($this->type === 'INSERT') {
            $columns = implode(', ', array_map(fn($col) => $this->wrapColumn($col), $this->columns));
            $placeholders = implode(', ', array_fill(0, count($this->columns), '?'));
            return "INSERT INTO {$this->table} ($columns) VALUES ($placeholders)";
        }

        // UPDATE
        if ($this->type === 'UPDATE') {
            // Generera SET-delen
            $setClause = implode(', ',
                array_map(fn($col) => "{$this->wrapColumn($col)} = ?", array_keys($this->columns))
            );

            // Bygg den fullständiga SQL-frågan
            $sql = "UPDATE {$this->table} SET {$setClause}";

            // Lägg till WHERE-villkor, om de finns
            $where = $this->buildWhere();
            if (!empty($where)) {
                $sql .= " {$where}";
            }

            return $sql;
        }

        // DELETE
        if ($this->type === 'DELETE') {
            $sql = "DELETE FROM {$this->table}";
            $where = $this->buildWhere();

            if (!empty($where)) {
                $sql .= " {$where}";
            }

            return $sql;
        }

        // SELECT (Default or Advanced)
        if ($this->type === 'SELECT') {
            $distinct = $this->distinct ? 'DISTINCT ' : '';

            // Behandla kolumner: lämna raw eller wraps
            $columns = implode(', ', array_map(function ($col) {
                // Om det är ett råuttryck (innehåller något som en SQL-funktion) lämna det orört
                if (preg_match('/[A-Z]+\(/i', $col) || str_starts_with($col, 'COUNT') || str_contains($col, 'NOW')) {
                    return $col;
                }
                return $this->wrapColumn($col);
            }, $this->columns));

            $sql = "SELECT {$distinct}{$columns} FROM {$this->table}";

            // Lägg till JOIN-satser
            if (!empty($this->joins)) {
                $sql .= ' '.implode(' ', $this->joins);
            }

            // Lägg till WHERE via buildWhere()
            $where = $this->buildWhere();
            if (!empty($where)) {
                $sql .= " {$where}";
            }

            // Hantera GROUP BY
            if (!empty($this->groupBy)) {
                $sql .= ' GROUP BY '.implode(', ', $this->groupBy);
            }

            // Lägg till HAVING om den används
            if (!empty($this->having)) {
                $sql .= " HAVING {$this->having}";
            }

            // Hantera ORDER BY
            if (!empty($this->orderBy)) {
                $sql .= ' ORDER BY '.implode(', ', $this->orderBy);
            }

            // Hantera LIMIT och OFFSET
            if ($this->limit !== null) {
                $sql .= " LIMIT {$this->limit}";
            }

            if ($this->offset !== null) {
                $sql .= " OFFSET {$this->offset}";
            }

            // Hantera UNION-satser
            if (!empty($this->unions)) {
                $sql .= ' '.implode(' ', $this->unions);
            }

            return $sql;
        }

        // Om typen inte stöds
        throw new \RuntimeException("Query type '{$this->type}' är inte implementerad.");
    }

    protected function getWhereBindings(): array
    {
        $whereBindings = [];
        foreach ($this->where as $condition) {
            // Ta endast med bindningsvärden för "raw" och liknande typer
            if (isset($condition['value']) && $condition['value'] === '?') {
                $whereBindings = array_merge($whereBindings, $this->bindings);
            }
        }
        return $whereBindings;
    }

    /**
     * Returnera bindningsparametrar för den byggda SQL-frågan.
     */
    public function getBindings(): array
    {
        return $this->bindings;
    }

    /**
     * Bygg `INSERT INTO` SQL-sats.
     */
    public function insert(array $data): self
    {
        // Kontrollera om data är tom
        if (empty($data)) {
            throw new \InvalidArgumentException("Data for INSERT cannot be empty.");
        }

        $this->type = 'INSERT';
        $this->columns = array_keys($data);
        $this->setBindings($data);

        return $this;
    }

    /**
     * Bygg `UPDATE` SQL-sats.
     */
    public function update(array $data): self
    {
        $this->type = 'UPDATE';
        $this->columns = $data;

        // Sammanfoga VALUES med WHERE-bindningar i rätt ordning
        $this->bindings = array_merge(
            array_values($data),       // Lägg till bindningsvärden för fälten som uppdateras
            $this->getWhereBindings()  // Lägg till WHERE-bindningarna
        );

        return $this;
    }

    /**
     * Bygg `DELETE` SQL-sats.
     */
    public function delete(): self
    {
        if (empty($this->where)) {
            throw new \RuntimeException("DELETE operation requires a WHERE clause.");
        }

        $this->type = 'DELETE';
        $this->setWhereBindings();

        return $this;
    }

    public function transaction(callable $callback): void
    {
        try {
            $this->startTransaction();
            $callback($this);
            $this->commitTransaction();
        } catch (\Throwable $e) {
            $this->rollbackTransaction();

            // Skicka vidare undantaget efter rollback
            throw $e;
        }
    }

    public function testWrapColumn(string $column): string
    {
        return $this->wrapColumn($column);
    }

    public function testWrapAlias(string $alias): string
    {
        return $this->wrapAlias($alias);
    }

    protected function setBindings(array $data): void
    {
        $this->bindings = array_values($data);
    }

    protected function setWhereBindings(): void
    {
        $whereBindings = $this->extractWhereBindings();

        // Lägg endast till nya bindningsvärden som inte redan existerar
        $this->bindings = array_merge(
            $this->bindings,
            array_filter($whereBindings, fn($binding) => !in_array($binding, $this->bindings, true))
        );
    }

    protected function startTransaction(): void
    {
        if ($this->connection === null) {
            throw new \LogicException('Ingen databasanslutning är inställd. Använd setConnection() innan du startar en transaktion.');
        }

        // Starta transaktionen via anslutningsobjektet
        $this->connection->beginTransaction();
    }

    protected function commitTransaction(): void
    {
        if ($this->connection === null) {
            throw new \LogicException('Ingen databasanslutning är inställd. Använd setConnection() innan du gör commit.');
        }

        // Genomför ändringar via anslutningsobjektet
        $this->connection->commitTransaction();
    }

    protected function rollbackTransaction(): void
    {
        if ($this->connection === null) {
            throw new \LogicException('Ingen databasanslutning är inställd. Använd setConnection() innan du gör rollback.');
        }

        // Rulla tillbaka transaktionen via anslutningsobjektet
        $this->connection->rollbackTransaction();
    }

    protected function wrapColumn(string $column): string
    {
        // Kontrollera om det är en giltig kolumn eller alias
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_\.]*$/', $column)) {
            return $column;
        }

        // Hantera "table.column" formatet och wrappa båda delar
        if (str_contains($column, '.')) {
            [$table, $col] = explode('.', $column, 2);
            return $this->wrapAlias($table) . '.' . "`{$col}`";
        }

        // Standard: wrappa kolumnnamnet direkt
        return "`{$column}`";
    }

    /**
     * Omgärda ett alias med backticks om nödvändigt.
     */
    protected function wrapAlias(string $alias): string
    {
        // Behåll alias korrekt omslutet eller omslut det med backticks om nödvändigt
        return preg_match('/^`.*`$/', $alias) ? $alias : "`$alias`";
    }

    protected function extractWhereBindings(): array
    {
        $bindings = [];

        foreach ($this->where as $condition) {
            if ($condition['type'] === 'basic' && $condition['value'] === '?') {
                // Bind det faktiska värdet som kopplats till detta villkor
                $bindings[] = $this->bindings[count($bindings)] ?? null;
            }
        }

        return $bindings;
    }
}