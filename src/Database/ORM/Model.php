<?php

declare(strict_types=1);

namespace Radix\Database\ORM;

use JsonSerializable;
use Radix\Database\DatabaseManager;
use Radix\Database\ORM\Relationships\BelongsTo;
use Radix\Database\ORM\Relationships\BelongsToMany;
use Radix\Database\ORM\Relationships\HasMany;
use Radix\Database\ORM\Relationships\HasOne;
use Radix\Database\QueryBuilder\QueryBuilder;

/**
 * Dynamiska metoder som hämtas från QueryBuilder.
 *
 * @method static \Radix\Database\QueryBuilder\QueryBuilder select(string|string[] $columns = ['*']) Ställer in vilka kolumner som ska hämtas.
 * @method static \Radix\Database\QueryBuilder\QueryBuilder setModelClass(string $modelClass) Anger modellklassen för QueryBuilder.
 * @method static \Radix\Database\QueryBuilder\QueryBuilder first() Hämtar den första posten.
 * @method static \Radix\Database\QueryBuilder\QueryBuilder get() Hämtar resultaten som en array.
 * @method static \Radix\Database\QueryBuilder\QueryBuilder with(string|string[] $relations) Förladdar relationer.
 * @method static \Radix\Database\QueryBuilder\QueryBuilder withSoftDeletes()
 * @method static \Radix\Database\QueryBuilder\QueryBuilder getOnlySoftDeleted()()
 * @method static \Radix\Database\QueryBuilder\QueryBuilder distinct(bool $value = true) Markerar att bara unika värden ska hämtas.
 * @method static \Radix\Database\QueryBuilder\QueryBuilder from(string $table) Ställer in vilken tabell som ska användas.
 * @method static \Radix\Database\QueryBuilder\QueryBuilder where(string $column, string $operator, mixed $value, string $boolean = 'AND') Lägg till WHERE-villkor.
 * @method static \Radix\Database\QueryBuilder\QueryBuilder whereIn(string $column, array $values, string $boolean = 'AND') Lägg till WHERE IN-villkor.
 * @method static \Radix\Database\QueryBuilder\QueryBuilder orWhere(string $column, string $operator, mixed $value) Lägg till OR WHERE-villkor.
 * @method static \Radix\Database\QueryBuilder\QueryBuilder whereNull(string $column, string $boolean = 'AND') Lägg till WHERE IS NULL.
 * @method static \Radix\Database\QueryBuilder\QueryBuilder whereNotNull(string $column, string $boolean = 'AND') Lägg till WHERE IS NOT NULL.
 * @method static \Radix\Database\QueryBuilder\QueryBuilder orWhereNotNull(string $column) Lägg till OR WHERE IS NOT NULL.
 * @method static \Radix\Database\QueryBuilder\QueryBuilder whereLike(string $column, string $value, string $boolean = 'AND') Lägg till WHERE LIKE-villkor.
 * @method static \Radix\Database\QueryBuilder\QueryBuilder orderBy(string $column, string $direction = 'ASC') Lägg till ORDER BY.
 * @method static \Radix\Database\QueryBuilder\QueryBuilder groupBy(string ...$columns) Lägg till GROUP BY-villkor.
 * @method static \Radix\Database\QueryBuilder\QueryBuilder having(string $column, string $operator, mixed $value) Lägg till HAVING-villkor.
 * @method static \Radix\Database\QueryBuilder\QueryBuilder leftJoin(string $table, string $first, string $operator, string $second) Lägg till LEFT JOIN.
 * @method static \Radix\Database\QueryBuilder\QueryBuilder join(string $table, string $first, string $operator, string $second, string $type = 'INNER') Lägg till JOIN.
 * @method static \Radix\Database\QueryBuilder\QueryBuilder fullJoin(string $table, string $first, string $operator, string $second) Lägg till FULL OUTER JOIN.
 * @method static \Radix\Database\QueryBuilder\QueryBuilder joinSub(self|\Radix\Database\QueryBuilder\QueryBuilder $subQuery, string $alias, string $first, string $operator, string $second, string $type = 'INNER') Lägg till JOIN med en subquery.
 * @method static \Radix\Database\QueryBuilder\QueryBuilder union(self|\Radix\Database\QueryBuilder\QueryBuilder $query, bool $all = false) Lägg till UNION.
 * @method static \Radix\Database\QueryBuilder\QueryBuilder limit(int $limit) Ange antalet poster att hämta.
 * @method static \Radix\Database\QueryBuilder\QueryBuilder offset(int $offset) Hoppa över ett visst antal poster.
 * @method static \Radix\Database\QueryBuilder\QueryBuilder paginate(int $perPage = 10, int $currentPage = 1) Returnera paginerade resultat.
 * @method static \Radix\Database\QueryBuilder\QueryBuilder search(string $term, array $searchColumns, int $perPage = 10, int $currentPage = 1) Returnera search resultat.
 * @method static \Radix\Database\QueryBuilder\QueryBuilder count(string $column = '*', string $alias = 'count') Lägg till COUNT i SELECT.
 * @method static \Radix\Database\QueryBuilder\QueryBuilder avg(string $column, string $alias = 'average') Lägg till AVG i SELECT.
 * @method static \Radix\Database\QueryBuilder\QueryBuilder sum(string $column, string $alias = 'sum') Lägg till SUM i SELECT.
 * @method static \Radix\Database\QueryBuilder\QueryBuilder min(string $column, string $alias = 'min') Lägg till MIN i SELECT.
 * @method static \Radix\Database\QueryBuilder\QueryBuilder max(string $column, string $alias = 'max') Lägg till MAX i SELECT.
 * @method static \Radix\Database\QueryBuilder\QueryBuilder concat(array $columns, string $alias) Lägg till CONCAT i SELECT.
 * @method static \Radix\Database\QueryBuilder\QueryBuilder addExpression(string $expression) Lägg till ett raw uttryck i SELECT.
 * @method static \Radix\Database\QueryBuilder\QueryBuilder round(string $column, int $decimals = 0, string $alias = null) Lägg till ROUND i SELECT.
 * @method static \Radix\Database\QueryBuilder\QueryBuilder ceil(string $column, string $alias = null) Lägg till CEIL i SELECT.
 * @method static \Radix\Database\QueryBuilder\QueryBuilder floor(string $column, string $alias = null) Lägg till FLOOR i SELECT.
 * @method static \Radix\Database\QueryBuilder\QueryBuilder abs(string $column, string $alias = null) Lägg till ABS i SELECT.
 * @method static \Radix\Database\QueryBuilder\QueryBuilder upper(string $column, string $alias = null) Lägg till UPPER i SELECT.
 * @method static \Radix\Database\QueryBuilder\QueryBuilder lower(string $column, string $alias = null) Lägg till LOWER i SELECT.
 * @method static \Radix\Database\QueryBuilder\QueryBuilder year(string $column, string $alias = null) Lägg till YEAR i SELECT.
 * @method static \Radix\Database\QueryBuilder\QueryBuilder month(string $column, string $alias = null) Lägg till MONTH i SELECT.
 * @method static \Radix\Database\QueryBuilder\QueryBuilder date(string $column, string $alias = null) Lägg till DATE i SELECT.
 * @method static \Radix\Database\QueryBuilder\QueryBuilder selectRaw(string $expression) Lägg till ett raw SELECT-uttryck.
 * @method static \Radix\Database\QueryBuilder\QueryBuilder debugSql() Returnerar den aktuella SQL-frågan med bindningsvärden.
 * @method static \Radix\Database\QueryBuilder\QueryBuilder toSql() Returnerar den kompletta SQL-frågan.
 * @method static \Radix\Database\QueryBuilder\QueryBuilder getBindings() Returnerar bindningsvärdena för SQL-frågan.
 * @method static \Radix\Database\QueryBuilder\QueryBuilder insert(array $data) Kör en INSERT-fråga.
 * @method static \Radix\Database\QueryBuilder\QueryBuilder update(array $data) Kör en UPDATE-fråga.
 * @method static \Radix\Database\QueryBuilder\QueryBuilder delete() Kör en DELETE-fråga.
 * @method static \Radix\Database\QueryBuilder\QueryBuilder transaction(callable $callback) Utför en transaktion.
 * @method static \Radix\Database\QueryBuilder\QueryBuilder setConnection(\Radix\Database\Connection $connection) Ställer in en databasanslutning.
 * @method static \Radix\Database\QueryBuilder\QueryBuilder execute() Kör den genererade SQL-frågan.
 * @method static \Radix\Database\QueryBuilder\QueryBuilder testWrapColumn(string $column) Testar att wrappa en kolumn.
 * @method static \Radix\Database\QueryBuilder\QueryBuilder testWrapAlias(string $alias) Testar att wrappa ett alias.
 * @method static self forceFill(array $attributes) Tvinga fyllning av attribut oavsett skydd.
 */

abstract class Model implements JsonSerializable
{
    protected string $primaryKey = 'id'; // Standard primärnyckel
    protected array $attributes = [];   // Modellens attribut
    protected bool $exists = false;    // Om posten existerar i databasen
    protected string $table;          // Tabellen kopplad till modellen
    protected bool $softDeletes = false; // Om modellen använder soft deletes
    protected bool $timestamps = false;
    protected array $relations = []; // Lagrar modellens relati
    protected array $internalKeys = ['exists', 'relations']; // Skyddade nycklar
    protected array $fillable = []; // Lista över tillåtna fält för massfyllning
    protected array $guarded = [];
    protected array $autoloadRelations = [];

    /**
     * Konstruktor
     */
    public function __construct(array $attributes = [])
    {
        $this->fill($attributes);
    }

    public function markAsExisting(): void
    {
        $this->exists = true;
    }

    public function markAsNew(): void
    {
        $this->exists = false;
    }

    public function isExisting(): bool
    {
        return $this->exists;
    }

    public function getTable(): string
    {
        return $this->table;
    }

    public function fetchGuardedAttribute(string $field): mixed
    {
        if (!in_array($field, $this->guarded, true)) {
            throw new \InvalidArgumentException("Fältet '$field' är inte markerat som guarded.");
        }

        // Hämta skyddat fält via direkt SQL
        $connection = $this->getConnection();
        $value = $connection->fetchOne(
            sprintf('SELECT `%s` FROM `%s` WHERE `%s` = ?', $field, $this->getTable(), $this->primaryKey),
            [$this->attributes[$this->primaryKey]]
        );

        return $value[$field] ?? null;
    }

    /**
     * Sätt relation för modellen.
     */
    public function setRelation(string $key, mixed $value): self
    {
        $this->relations[$key] = $value;
        return $this;
    }

    /**
     * Hämta en relation från modellen.
     */
    public function getRelation(string $key): mixed
    {
        return $this->relations[$key] ?? null;
    }

    public function relationExists(string $relation): bool
    {
        return method_exists($this, $relation);
    }

    public static function __callStatic(string $method, array $arguments)
    {
        $query = self::query(); // Använd rätt kontext via `query()`

        if (method_exists($query, $method)) {
            return $query->$method(...$arguments);
        }

        throw new \BadMethodCallException("Method $method does not exist in " . static::class);
    }

    public static function availableQueryBuilderMethods(): array
    {
        $queryBuilderClass = QueryBuilder::class;
        return get_class_methods($queryBuilderClass);
    }

    /**
     * Hämta anslutningen från DatabaseManager via app().
     */
    protected function getConnection(): \Radix\Database\Connection
    {
        return app(DatabaseManager::class)->connection();
    }

    /**
     * Fyll objektet med data.
     */
    public function blockUndefinableAttributes(): void
    {
        if (empty($this->fillable) && empty($this->guarded)) {
            $this->guarded = []; // Tillåt allt om både `fillable` och `guarded` är tomma.
        }

        // Hantera timestamps: Om `fillable` inte innehåller dem, behandla dem som ej tillåtna.
        if (!in_array('created_at', $this->fillable, true)) {
            unset($this->attributes['created_at']);
        }

        if (!in_array('updated_at', $this->fillable, true)) {
            unset($this->attributes['updated_at']);
        }
    }


    public function setGuarded(array $fields): void
    {
        $this->guarded = $fields; // Uppdatera guarded-attributet
    }

    /**
     * Kontrollera om ett attribut är tillåtet att fyllas (fillable).
     */
    public function isFillable(string $key): bool
    {
        // Om `fillable` och `guarded` är tomma, tillåt allt
        if (empty($this->fillable) && empty($this->guarded)) {
            return true;
        }

        // Om `guarded` är tom, kontrollera endast mot `fillable`
        if (empty($this->guarded)) {
            return in_array($key, $this->fillable, true);
        }

        // Blockera alla attribut om `guarded` innehåller '*'
        if (in_array('*', $this->guarded, true)) {
            return in_array($key, $this->fillable, true);
        }

        // Blockera specifika attribut som anges i `guarded`
        if (in_array($key, $this->guarded, true)) {
            return false;
        }

        // Tillåt attribut som uttryckligen är angivet som "fillable"
        return in_array($key, $this->fillable, true);
    }

    /**
     * Ange fillable-fält dynamiskt.
     */
    public function setFillable(array $fields): void
    {
        $this->fillable = $fields;
    }

    public function forceFill(array $attributes): self
    {
        foreach ($attributes as $key => $value) {
            $this->attributes[$key] = $value;
        }

        return $this;
    }

    public function fill(array $attributes): void
    {
        $this->blockUndefinableAttributes();

        foreach ($attributes as $key => $value) {
            if ($this->isFillable($key)) {
                $this->setAttribute($key, $value);
            }
        }
    }

    /**
     * Hämta ett attribut med eventuell accessor.
     */
    // Modifierad getAttribute-metod
    public function getAttribute(string $key): mixed
    {
        // Kontrollera om nyckeln finns i $attributes innan anrop av accessor
        if (array_key_exists($key, $this->attributes)) {
            $accessor = 'get' . str_replace(' ', '', ucwords(str_replace('_', ' ', $key))) . 'Attribute';

            // Om en accessor-metod finns, anropa den
            if (method_exists($this, $accessor)) {
                return $this->$accessor($this->attributes[$key]);
            }

            return $this->attributes[$key];
        }

        // Returnera null om nyckeln inte finns
        return null;
    }

    /**
     * Sätt ett attribut med validering eller bearbetning.
     */
    public function setAttribute(string $key, mixed $value): void
    {
        // Kontrollera om attributet är tillåtet att sättas
        if (!$this->isFillable($key)) {
            return; // Ignorera värdet
        }

        // Hantera mutators (sätt värde via setter om det finns)
        $mutator = 'set' . str_replace(' ', '', ucwords(str_replace('_', ' ', $key))) . 'Attribute';
        if (method_exists($this, $mutator)) {
            $this->$mutator($value);
            return;
        }

        // Annars sätt värdet direkt
        $this->attributes[$key] = $value;
    }

    public function getAttributes(): array
    {
        // Ta bort interna nycklar från resultatet
        return array_filter(
            $this->attributes,
            fn($key) => !in_array($key, $this->internalKeys, true),
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * Magic method för att läsa attribut som egenskaper.
     */
    public function __get(string $key): mixed
    {
        // Kontrollera först om egenskapen finns
        if (property_exists($this, $key)) {
            return $this->$key;
        }

        // Kontrollera om attributet finns i $attributes
        if (array_key_exists($key, $this->attributes)) {
            return $this->getAttribute($key);
        }

        // Kontrollera om det är en definierad relationsmetod
        if (method_exists($this, $key)) {
            return $this->$key();
        }

        throw new \Exception("Undefined property or relation '$key' in model.");
    }

    /**
     * Magic method för att sätta nya värden på attribut.
     */
    public function __set(string $key, mixed $value): void
    {
        $method = 'set' . ucfirst(camel_to_snake($key)) . 'Attribute';
        if (method_exists($this, $method)) {
            $this->$method($value);
            return;
        }

        $this->attributes[$key] = $value;
    }

    /**
     * Kontrollera om ett attribut finns definierat.
     */
    public function __isset(string $key): bool
    {
        return isset($this->attributes[$key]);
    }

    /**
     * Ta bort ett attribut.
     */
    public function __unset(string $key): void
    {
        unset($this->attributes[$key]);
    }

    public function getExists(): bool
    {
        return $this->exists;
    }

    public static function query(): QueryBuilder
    {
        $instance = new static();
        $query = (new QueryBuilder())
           ->setConnection($instance->getConnection())  // Anslutning
           ->setModelClass(static::class)              // Sätt rätt modellklass
           ->from($instance->getTable());

        // Lägg till standard för Soft Deletes om det behövs
        if ($instance->softDeletes && !$query->getWithSoftDeletes()) { // Kontrollera flaggan
           $query->whereNull('deleted_at');
        }

        return $query;
    }

    /**
     * Spara objektet i databasen (insert eller update).
     */
    public function save(): bool
    {
        if ($this->timestamps) {
            $this->attributes['updated_at'] = date('Y-m-d H:i:s');
            if (!$this->exists) {
                $this->attributes['created_at'] = date('Y-m-d H:i:s');
            }
        }

        // Kontrollera om modellen ska uppdateras eller infogas
        $this->exists = isset($this->attributes[$this->primaryKey]);

        return $this->exists ? $this->update() : $this->insert();
    }

    public function setTimestamps(bool $enable): void
    {
        $this->timestamps = $enable;
    }

    /**
     * Uppdatera aktuell rad i databasen.
     */
    private function update(): bool
    {
        // Samma här: Ta endast med relevanta attribut
        $attributes = $this->getAttributes();

        $fields = implode(', ', array_map(fn($key) => "`$key` = ?", array_keys($attributes)));
        $query = "UPDATE `$this->table` SET $fields WHERE `$this->primaryKey` = ?";
        $bindings = array_merge(array_values($attributes), [$this->attributes[$this->primaryKey]]);

        return $this->getConnection()->execute($query, $bindings)->rowCount() > 0;
    }

    /**
     * Infoga en ny rad i databasen.
     */
    private function insert(): bool
    {
        // Hämta endast giltiga attribut som får sparas
        $attributes = $this->getAttributes();

        $columns = implode('`, `', array_keys($attributes));
        $placeholders = implode(', ', array_fill(0, count($attributes), '?'));

        $query = "INSERT INTO `$this->table` (`$columns`) VALUES ($placeholders)";
        $statement = $this->getConnection()->execute($query, array_values($attributes));

        if ($statement->rowCount() > 0) {
            $this->exists = true;
            $this->attributes[$this->primaryKey] = $this->getConnection()->lastInsertId();
            return true;
        }

        return false;
    }

    /**
     * Ta bort en rad från databasen.
     */
    public function delete(): bool
    {
        if (!$this->exists) {
            return false; // Modellen måste existera för att kunna raderas
        }

        if ($this->softDeletes) {
            // Hantera soft delete: sätt 'deleted_at' i modellen
            $this->attributes['deleted_at'] = date('Y-m-d H:i:s');
            return $this->update(); // Uppdatera raden
        }

        // Om softDeletes inte är aktivt, utför hård radering
        return $this->forceDelete();
    }

    public function restore(): bool
    {
        if ($this->softDeletes) {
            // Spara den ursprungliga `guarded`
            $originalGuarded = $this->guarded;

            // Temporärt tillåt att manipulera `deleted_at`
            $this->setGuarded(array_diff($this->guarded ?? [], ['deleted_at']));

            // Kontrollera om `deleted_at` är satt i attributen
            if (!isset($this->attributes['deleted_at'])) {
                // Hämta värdet från databasen om det inte är tillgängligt
                $result = $this->getConnection()
                    ->execute("SELECT deleted_at FROM `$this->table` WHERE `$this->primaryKey` = ?", [$this->attributes[$this->primaryKey]])
                    ->fetch();

                // Om inget `deleted_at` hittas i databasen, bör det stanna false
                $this->attributes['deleted_at'] = $result['deleted_at'] ?? null;
            }

            // Om modellen är soft-deleted (`deleted_at` har ett värde), återställ den
            if (!is_null($this->attributes['deleted_at'])) {
                $this->attributes['deleted_at'] = null;

                // Uppdatera posten i databasen
                $result = $this->update();

                // Återställ den ursprungliga `guarded`
                $this->setGuarded($originalGuarded);

                return $result;
            }

            // Återställ den ursprungliga `guarded` om inget krävdes
            $this->setGuarded($originalGuarded);
        }

        return false; // Om modellen inte var soft-deleted
    }

    /**
     * Tvinga borttagning av en rad från databasen oavsett Soft Deletes.
     */
    public function forceDelete(): bool
    {
        if ($this->exists) {
            // Bygg och kör DELETE-satsen
            $query = "DELETE FROM `$this->table` WHERE `$this->primaryKey` = ?";
            $deleted = $this->getConnection()->execute($query, [$this->attributes[$this->primaryKey]])->rowCount() > 0;

            if ($deleted) {
                $this->exists = false; // Markera modellen som borttagen
            }

            return $deleted;
        }

        return false;
    }

    /**
     * Hämta en rad från databasen baserad på primärnyckeln.
     */
    public static function find(int|string $id, bool $withTrashed = false): ?static
    {
        $instance = new static(); // Skapa en ny instans av modellen

        // Skapa en query med rätt modellklass
        $query = (new QueryBuilder())
            ->setConnection($instance->getConnection())
            ->setModelClass(static::class) // Sätt rätt modellklass
            ->from($instance->getTable());

        // Filtrera bort soft-deleted poster om `$withTrashed` = false
        if (!$withTrashed && $instance->softDeletes) {
            $query->whereNull('deleted_at');
        }

        // Lägg till WHERE-filter för primärnyckeln
        $query->where($instance->primaryKey, '=', $id);

        // Hämta det första resultatet som en modell
        $model = $query->first();

        // Om modellen har autoloadRelations, ladda dem
        if ($model && property_exists($model, 'autoloadRelations') && !empty($model->autoloadRelations)) {
            foreach ($model->autoloadRelations as $relation) {
                if ($model->relationExists($relation)) {
                    $model->setRelation($relation, $model->$relation()->get());
                }
            }
        }

        return $model;
    }

    /**
     * Hämta alla rader från tabellen.
     */
    public static function all(): array
    {
        return self::query()->get();
    }

    /**
     * Definiera en "hasMany"-relation.
     */
    public function hasMany(string $relatedModel, string $foreignKey, ?string $localKey = null): HasMany
    {
        $localKey = $localKey ?? $this->primaryKey;

        // Säkerställ att modellen existerar och skapa en instans för att hämta tabellnamnet
        if (!class_exists($relatedModel)) {
            throw new \Exception("Relation model class '$relatedModel' not found.");
        }

        // Verifiera att localKey finns i attributen
        if (!array_key_exists($localKey, $this->attributes)) {
            throw new \Exception("Attribute '$localKey' not found in model attributes.");
        }

        return new HasMany(
            $this->getConnection(),
            $relatedModel,
            $foreignKey,
            (string)$this->attributes[$localKey] // Nu säkerställt
        );
    }

    /**
     * Definiera en "hasOne"-relation.
     */
    public function hasOne(string $relatedModel, string $foreignKey, ?string $localKey = null): HasOne
    {
        $localKey = $localKey ?? $this->primaryKey;

        // Säkerställ att modellen existerar och hämta tabellnamn via getTable()
        if (!class_exists($relatedModel)) {
            throw new \Exception("Relation model class '$relatedModel' not found.");
        }

        $relatedInstance = new $relatedModel();

        return new HasOne(
            $this->getConnection(),
            $relatedInstance->getTable(), // Använd getTable()
            $foreignKey,
            $this->attributes[$localKey]
        );
    }

    /**
     * Definiera en "belongsTo"-relation.
     */
    public function belongsTo(string $relatedModel, string $foreignKey, ?string $ownerKey = null): BelongsTo
    {
        $ownerKey = $ownerKey ?? $this->primaryKey;

        if (!class_exists($relatedModel)) {
            throw new \Exception("Relation model class '$relatedModel' not found.");
        }

        $relatedInstance = new $relatedModel();

        // Skicka den aktuella instansen (`$this`) som parent-modellen
        return new BelongsTo(
            $this->getConnection(),
            $relatedInstance->getTable(),
            $foreignKey,
            $ownerKey,
            $this // Passera parent-modellen
        );
    }

    /**
     * Definiera en "belongsToMany"-relation.
     */
    public function belongsToMany(
        string $relatedModel,
        string $pivotTable,
        string $foreignPivotKey,
        string $relatedPivotKey,
        ?string $parentKey = null
    ): BelongsToMany {
        $parentKey = $parentKey ?? $this->primaryKey;

        // Säkerställ att modellen existerar och hämta tabellnamn via getTable()
        if (!class_exists($relatedModel)) {
            throw new \Exception("Relation model class '$relatedModel' not found.");
        }

        $relatedInstance = new $relatedModel();

        return new BelongsToMany(
            $this->getConnection(),
            $relatedInstance->getTable(), // Använd getTable()
            $pivotTable,
            $foreignPivotKey,
            $relatedPivotKey,
            $this->attributes[$parentKey]
        );
    }

    public function toArray(): array
    {
        $array = [];

        // Lägg till attribut
        foreach ($this->attributes as $key => $value) {
            $array[$key] = $this->getAttribute($key);
        }

        // Lägg till relationer
        foreach ($this->relations as $relationKey => $relationValue) {
            if (is_array($relationValue)) {
                $array[$relationKey] = array_map(fn($item) => $item->toArray(), $relationValue);
            } elseif ($relationValue instanceof self) {
                $array[$relationKey] = $relationValue->toArray();
            } else {
                $array[$relationKey] = $relationValue;
            }
        }

        // Autoladda relationer vid serialisering
        if (!empty($this->autoloadRelations)) {
            foreach ($this->autoloadRelations as $relation) {
                if (!isset($array[$relation]) && $this->relationExists($relation)) {
                    $relatedData = $this->$relation()->get();
                    $array[$relation] = is_array($relatedData)
                        ? array_map(fn($item) => $item->toArray(), $relatedData)
                        : $relatedData->toArray();
                }
            }
        }

        return $array;
    }

    public static function getPrimaryKey(): string
    {
        return 'id'; // Standard primärnyckel
    }

    public function jsonSerialize(): array
    {
       return $this->toArray();
    }
}