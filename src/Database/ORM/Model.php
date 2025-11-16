<?php

declare(strict_types=1);

namespace Radix\Database\ORM;

use JsonSerializable;
use Radix\Collection\Collection;
use Radix\Database\DatabaseManager;
use Radix\Database\ORM\Relationships\BelongsTo;
use Radix\Database\ORM\Relationships\BelongsToMany;
use Radix\Database\ORM\Relationships\HasMany;
use Radix\Database\ORM\Relationships\HasManyThrough;
use Radix\Database\ORM\Relationships\HasOne;
use Radix\Database\QueryBuilder\QueryBuilder;

/**
 * Dynamiska metoder som hämtas från QueryBuilder.
 *
 * Bas
 * @method static \Radix\Database\QueryBuilder\QueryBuilder setConnection(\Radix\Database\Connection $connection) Sätt DB-anslutning.
 * @method static \Radix\Database\QueryBuilder\QueryBuilder setModelClass(string $modelClass) Ange modellklass.
 * @method static \Radix\Database\QueryBuilder\QueryBuilder from(string $table) Ange tabell (stödjer "AS").
 * @method static \Radix\Database\QueryBuilder\QueryBuilder fromRaw(string $raw) Ange FROM som rått uttryck/subquery.
 * @method static \Radix\Database\QueryBuilder\QueryBuilder select(string|array<int,string> $columns = ['*']) Kolumner.
 * @method static \Radix\Database\QueryBuilder\QueryBuilder selectRaw(string $expression) Rå SELECT-uttryck.
 * @method static \Radix\Database\QueryBuilder\QueryBuilder selectSub(\Radix\Database\QueryBuilder\QueryBuilder $sub, string $alias) Subquery i SELECT.
 * @method static \Radix\Database\QueryBuilder\QueryBuilder distinct(bool $value = true) DISTINCT.
 * @method static string                                    toSql() Generera SQL.
 * @method static array<int,mixed>                          getBindings() Hämta bindningar.
 * @method static string                                    debugSql() SQL med interpolerade bindningar.
 * @method static string                                    getRawSql() SQL med insatta värden (debug).
 * @method static \Radix\Database\QueryBuilder\QueryBuilder dump() Dumpa interpolerad SQL och fortsätt kedjan.
 * @method static mixed                                     value(string $column) Hämta ett enda värde.
 * @method static array<int|string,mixed>                   pluck(string $column, ?string $key = null) Hämta kolumnlista/assoc.
 * @method static \Radix\Collection\Collection              get() Hämta resultat (hydreras till modeller).
 * @method static mixed                                     first() Första raden (modell eller null).
 * @method static mixed                                     firstOrFail() Första raden eller exception.
 *
 * Snabba hämtningar
 * @method static array<int, array<string, mixed>>            fetchAllRaw() Hämta alla rader som assoc-arrayer (utan modell-hydrering).
 * @method static array<string,mixed>|null                  fetchRaw() Hämta första raden som assoc-array(utan modell-hydrering) eller null.
 *
 * Paginering/Sök
 * @method static array<string,mixed>                       paginate(int $perPage = 10, int $currentPage = 1)
 * @method static array<string,mixed>                       simplePaginate(int $perPage = 10, int $currentPage = 1)
 * @method static array<string,mixed>                       search(string $term, array<int,string> $searchColumns, int $perPage = 10, int $currentPage = 1)
 * @method static bool                                      exists()
 * @method static bool                                      doesntExist()
 *
 * Limit/Offset
 * @method static \Radix\Database\QueryBuilder\QueryBuilder limit(int $limit)
 * @method static \Radix\Database\QueryBuilder\QueryBuilder offset(int $offset)
 *
 * Where/Filter
 * @method static \Radix\Database\QueryBuilder\QueryBuilder where(string|\Radix\Database\QueryBuilder\QueryBuilder|\Closure $column, ?string $operator = null, mixed $value = null, string $boolean = 'AND')
 * @method static \Radix\Database\QueryBuilder\QueryBuilder orWhere(string $column, string $operator, mixed $value)
 * @method static \Radix\Database\QueryBuilder\QueryBuilder whereIn(string $column, array<int,mixed> $values, string $boolean = 'AND')
 * @method static \Radix\Database\QueryBuilder\QueryBuilder whereNotIn(string $column, array<int,mixed> $values, string $boolean = 'AND')
 * @method static \Radix\Database\QueryBuilder\QueryBuilder whereBetween(string $column, array<int,mixed> $range, string $boolean = 'AND')
 * @method static \Radix\Database\QueryBuilder\QueryBuilder whereNotBetween(string $column, array<int,mixed> $range, string $boolean = 'AND')
 * @method static \Radix\Database\QueryBuilder\QueryBuilder whereColumn(string $left, string $operator, string $right, string $boolean = 'AND')
 * @method static \Radix\Database\QueryBuilder\QueryBuilder whereExists(\Radix\Database\QueryBuilder\QueryBuilder $sub, string $boolean = 'AND')
 * @method static \Radix\Database\QueryBuilder\QueryBuilder whereNotExists(\Radix\Database\QueryBuilder\QueryBuilder $sub, string $boolean = 'AND')
 * @method static \Radix\Database\QueryBuilder\QueryBuilder whereRaw(string $sql, array<int,mixed> $bindings = [], string $boolean = 'AND')
 * @method static \Radix\Database\QueryBuilder\QueryBuilder whereNull(string $column, string $boolean = 'AND')
 * @method static \Radix\Database\QueryBuilder\QueryBuilder whereNotNull(string $column, string $boolean = 'AND')
 * @method static \Radix\Database\QueryBuilder\QueryBuilder orWhereNotNull(string $column)
 * @method static \Radix\Database\QueryBuilder\QueryBuilder whereLike(string $column, string $value, string $boolean = 'AND')
 *
 * JSON
 * @method static \Radix\Database\QueryBuilder\QueryBuilder jsonExtract(string $column, string $path, ?string $alias = null)
 * @method static \Radix\Database\QueryBuilder\QueryBuilder whereJsonContains(string $column, mixed $needle, string $boolean = 'AND')
 * @method static \Radix\Database\QueryBuilder\QueryBuilder whereJsonPath(string $column, string $path, string $operator, mixed $value, string $boolean = 'AND')
 *
 * Joins
 * @method static \Radix\Database\QueryBuilder\QueryBuilder join(string $table, string $first, string $operator, string $second, string $type = 'INNER')
 * @method static \Radix\Database\QueryBuilder\QueryBuilder leftJoin(string $table, string $first, string $operator, string $second)
 * @method static \Radix\Database\QueryBuilder\QueryBuilder rightJoin(string $table, string $first, string $operator, string $second)
 * @method static \Radix\Database\QueryBuilder\QueryBuilder fullJoin(string $table, string $first, string $operator, string $second)
 * @method static \Radix\Database\QueryBuilder\QueryBuilder joinSub(self|\Radix\Database\QueryBuilder\QueryBuilder $subQuery, string $alias, string $first, string $operator, string $second, string $type = 'INNER')
 * @method static \Radix\Database\QueryBuilder\QueryBuilder joinRaw(string $raw, array<int,mixed> $bindings = [])
 *
 * Group/Having/Order
 * @method static \Radix\Database\QueryBuilder\QueryBuilder groupBy(string ...$columns)
 * @method static \Radix\Database\QueryBuilder\QueryBuilder having(string $column, string $operator, mixed $value)
 * @method static \Radix\Database\QueryBuilder\QueryBuilder havingRaw(string $expression, array<int,mixed> $bindings = [])
 * @method static \Radix\Database\QueryBuilder\QueryBuilder orderBy(string $column, string $direction = 'ASC')
 * @method static \Radix\Database\QueryBuilder\QueryBuilder orderByRaw(string $expression)
 * @method static \Radix\Database\QueryBuilder\QueryBuilder orderByDesc(string $column)
 * @method static \Radix\Database\QueryBuilder\QueryBuilder latest(string $column = 'created_at')
 * @method static \Radix\Database\QueryBuilder\QueryBuilder oldest(string $column = 'created_at')
 *
 * Grouping sets och rollup
 * @method static \Radix\Database\QueryBuilder\QueryBuilder rollup(array<int,string> $columns)
 * @method static \Radix\Database\QueryBuilder\QueryBuilder groupingSets(array<int,mixed> $sets)
 *
 * Union
 * @method static \Radix\Database\QueryBuilder\QueryBuilder union(self|\Radix\Database\QueryBuilder\QueryBuilder $query, bool $all = false)
 * @method static \Radix\Database\QueryBuilder\QueryBuilder unionAll(self|\Radix\Database\QueryBuilder\QueryBuilder $query)
 *
 * Fönsterfunktioner (Windows)
 * @method static \Radix\Database\QueryBuilder\QueryBuilder rowNumber(string $alias, array<int,string> $partitionBy = [], array<int,string> $orderBy = [])
 * @method static \Radix\Database\QueryBuilder\QueryBuilder rank(string $alias, array<int,string> $partitionBy = [], array<int,string> $orderBy = [])
 * @method static \Radix\Database\QueryBuilder\QueryBuilder denseRank(string $alias, array<int,string> $partitionBy = [], array<int,string> $orderBy = [])
 * @method static \Radix\Database\QueryBuilder\QueryBuilder sumOver(string $column, string $alias, array<int,string> $partitionBy = [], array<int,string> $orderBy = [])
 * @method static \Radix\Database\QueryBuilder\QueryBuilder avgOver(string $column, string $alias, array<int,string> $partitionBy = [], array<int,string> $orderBy = [])
 * @method static \Radix\Database\QueryBuilder\QueryBuilder windowRaw(string $expression, ?string $alias = null)
 *
 * CASE-uttryck
 * @method static \Radix\Database\QueryBuilder\QueryBuilder caseWhen(array<int,mixed> $whenThenRows, string $elseExpr, ?string $alias = null)
 * @method static \Radix\Database\QueryBuilder\QueryBuilder orderByCase(string $column, array<string,string> $map, string $default, string $direction = 'ASC')
 *
 * CTE (WITH / RECURSIVE)
 * @method static \Radix\Database\QueryBuilder\QueryBuilder withCte(string $name, \Radix\Database\QueryBuilder\QueryBuilder $sub)
 * @method static \Radix\Database\QueryBuilder\QueryBuilder withCteRaw(string $raw, array<int, mixed> $bindings = [])
 * @method static \Radix\Database\QueryBuilder\QueryBuilder withRecursive(string $name, \Radix\Database\QueryBuilder\QueryBuilder $anchor, \Radix\Database\QueryBuilder\QueryBuilder $recursive, array<int, string> $columns)
 *
 * Låsning
 * @method static \Radix\Database\QueryBuilder\QueryBuilder forUpdate(bool $enable = true)
 * @method static \Radix\Database\QueryBuilder\QueryBuilder lockInShareMode(bool $enable = true)
 * @method static \Radix\Database\QueryBuilder\QueryBuilder lock(string $mode)
 *
 * Aggregatfunktioner (SELECT)
 * @method static \Radix\Database\QueryBuilder\QueryBuilder count(string $column = '*', string $alias = 'count')
 * @method static \Radix\Database\QueryBuilder\QueryBuilder avg(string $column, string $alias = 'average')
 * @method static \Radix\Database\QueryBuilder\QueryBuilder sum(string $column, string $alias = 'sum')
 * @method static \Radix\Database\QueryBuilder\QueryBuilder min(string $column, string $alias = 'min')
 * @method static \Radix\Database\QueryBuilder\QueryBuilder max(string $column, string $alias = 'max')
 * @method static \Radix\Database\QueryBuilder\QueryBuilder concat(array<int,string> $columns, string $alias)
 * @method static \Radix\Database\QueryBuilder\QueryBuilder addExpression(string $expression)
 * @method static \Radix\Database\QueryBuilder\QueryBuilder round(string $column, int $decimals = 0, string $alias = null)
 * @method static \Radix\Database\QueryBuilder\QueryBuilder ceil(string $column, string $alias = null)
 * @method static \Radix\Database\QueryBuilder\QueryBuilder floor(string $column, string $alias = null)
 * @method static \Radix\Database\QueryBuilder\QueryBuilder abs(string $column, string $alias = null)
 * @method static \Radix\Database\QueryBuilder\QueryBuilder upper(string $column, string $alias = null)
 * @method static \Radix\Database\QueryBuilder\QueryBuilder lower(string $column, string $alias = null)
 * @method static \Radix\Database\QueryBuilder\QueryBuilder year(string $column, string $alias = null)
 * @method static \Radix\Database\QueryBuilder\QueryBuilder month(string $column, string $alias = null)
 * @method static \Radix\Database\QueryBuilder\QueryBuilder date(string $column, string $alias = null)
 *
 * Skalära resultat
 * @method static mixed        scalar() Returnera första kolumnen i första raden.
 * @method static int|null     int() Returnera scalar som int (eller null).
 * @method static float|null   float() Returnera scalar som float (eller null).
 * @method static string|null  string() Returnera scalar som string (eller null).
 *
 * Insert-Select och mutationer
 * @method static \Radix\Database\QueryBuilder\QueryBuilder insertSelect(string $table, array<int,string> $columns, \Radix\Database\QueryBuilder\QueryBuilder $select)
 * @method static \Radix\Database\QueryBuilder\QueryBuilder insert(array<string,mixed> $data)
 * @method static \Radix\Database\QueryBuilder\QueryBuilder update(array<string,mixed> $data)
 * @method static \Radix\Database\QueryBuilder\QueryBuilder delete()
 * @method static \Radix\Database\QueryBuilder\QueryBuilder insertOrIgnore(array<string,mixed> $data)
 * @method static \Radix\Database\QueryBuilder\QueryBuilder upsert(array<int,array<string,mixed> > $data, array<int,string> $uniqueBy, array<string,mixed>|null $update = null)
 *
 * Transaktioner
 * @method static void transaction(callable $callback)
 * @method static void startTransaction()
 * @method static void commitTransaction()
 * @method static void rollbackTransaction()
 *
 * Soft deletes
 * @method static \Radix\Database\QueryBuilder\QueryBuilder withSoftDeletes()
 * @method static \Radix\Database\QueryBuilder\QueryBuilder getOnlySoftDeleted()
 * @method static \Radix\Database\QueryBuilder\QueryBuilder onlyTrashed()
 * @method static \Radix\Database\QueryBuilder\QueryBuilder withoutTrashed()
 *
 * Eager load/aggregat över relationer
 * @method static \Radix\Database\QueryBuilder\QueryBuilder with(string|array<int,string> $relations)
 * @method static \Radix\Database\QueryBuilder\QueryBuilder withCount(string|array<int,string> $relations)
 * @method static \Radix\Database\QueryBuilder\QueryBuilder withCountWhere(string $relation, string $column, mixed $value, ?string $alias = null)
 * @method static \Radix\Database\QueryBuilder\QueryBuilder withConstraint(string $relation, \Closure $constraint)
 * @method static \Radix\Database\QueryBuilder\QueryBuilder withSum(string $relation, string $column, ?string $alias = null)
 * @method static \Radix\Database\QueryBuilder\QueryBuilder withAvg(string $relation, string $column, ?string $alias = null)
 * @method static \Radix\Database\QueryBuilder\QueryBuilder withMin(string $relation, string $column, ?string $alias = null)
 * @method static \Radix\Database\QueryBuilder\QueryBuilder withMax(string $relation, string $column, ?string $alias = null)
 * @method static \Radix\Database\QueryBuilder\QueryBuilder withAggregate(string $relation, string $column, string $fn, ?string $alias = null)
 *
 * Utilities/sugar
 * @method static \Radix\Database\QueryBuilder\QueryBuilder when(bool $condition, \Closure $then, ?\Closure $else = null) Villkorad chaining.
 * @method static \Radix\Database\QueryBuilder\QueryBuilder tap(\Closure $callback) Hooka in i kedjan.
 * @method static \Generator                             lazy(int $size = 1000) Lazy iteration.
 * @method static void                                   chunk(int $size, \Closure $callback) Hämta i bitar.
 */
abstract class Model implements JsonSerializable
{
    protected string $primaryKey = 'id'; // Standard primärnyckel
    /** @var array<string, mixed> */
    protected array $attributes = [];   // Modellens attribut
    protected bool $exists = false;    // Om posten existerar i databasen
    protected string $table;          // Tabellen kopplad till modellen
    protected bool $softDeletes = false; // Om modellen använder soft deletes
    protected bool $timestamps = false;
    /** @var array<string, mixed> */
    protected array $relations = []; // Lagrar modellens relati
    /** @var array<int, string> */
    protected array $internalKeys = ['exists', 'relations']; // Skyddade nycklar
    /** @var array<int, string> */
    protected array $fillable = []; // Lista över tillåtna fält för massfyllning
    /** @var array<int, string> */
    protected array $guarded = [];
    /** @var array<int, string> */
    protected array $autoloadRelations = [];

    /**
     * @param array<string, mixed> $attributes
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

    /**
     * @param string              $method
     * @param array<int, mixed>   $arguments
     * @return mixed
     */
    public static function __callStatic(string $method, array $arguments)
    {
        $query = self::query(); // Använd rätt kontext via `query()`

        if (method_exists($query, $method)) {
            return $query->$method(...$arguments);
        }

        throw new \BadMethodCallException("Method $method does not exist in " . static::class);
    }

    protected function getConnection(): \Radix\Database\Connection
    {
        /** @var \Radix\Database\DatabaseManager $db */
        $db = app(\Radix\Database\DatabaseManager::class);

        return $db->connection();
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

    /**
     * @param array<int, string> $fields
     */
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
     * @param array<int, string> $fields
     */
    public function setFillable(array $fields): void
    {
        $this->fillable = $fields;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function forceFill(array $attributes): self
    {
        foreach ($attributes as $key => $value) {
            $this->attributes[$key] = $value;
        }

        return $this;
    }

    /**
     * @param array<string, mixed> $row
     */
    public function hydrateFromDatabase(array $row): self
    {
        $guardAll = !empty($this->guarded) && in_array('*', $this->guarded, true);

        foreach ($row as $key => $value) {
            if ($guardAll || in_array($key, $this->guarded ?? [], true)) {
                continue; // hoppa över guarded vid hydrering
            }
            $this->attributes[$key] = $value;
        }

        return $this;
    }

    /**
     * @param array<string, mixed> $attributes
     */
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

    /**
     * @return array<string, mixed>
     */
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
        $modelClass = static::class;
        /** @var static $instance */
        $instance = new $modelClass();

        $query = (new QueryBuilder())
           ->setConnection($instance->getConnection())
           ->setModelClass($modelClass)
           ->from($instance->getTable());

        if ($instance->softDeletes && !$query->getWithSoftDeletes()) {
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

            return $this->exists ? $this->persistUpdate() : $this->persistInsert();
        }

    public function setTimestamps(bool $enable): void
    {
        $this->timestamps = $enable;
    }

    /**
     * Uppdatera aktuell rad i databasen.
     */
    private function persistUpdate(): bool
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
    private function persistInsert(): bool
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
            return false;
        }

        if ($this->softDeletes) {
            // Soft delete: sätt deleted_at direkt via UPDATE istället för save()/persistUpdate()
            $this->attributes['deleted_at'] = date('Y-m-d H:i:s');

            $query = "UPDATE `$this->table` SET `deleted_at` = ? WHERE `$this->primaryKey` = ?";
            $ok = $this->getConnection()->execute($query, [$this->attributes['deleted_at'], $this->attributes[$this->primaryKey]])->rowCount() > 0;

            return $ok;
        }

        // Hård radering
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
                $stmt = $this->getConnection()
                    ->execute(
                        "SELECT deleted_at FROM `$this->table` WHERE `$this->primaryKey` = ?",
                            [$this->attributes[$this->primaryKey]]
                    );

                /** @var array<string, mixed>|false $row */
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                $this->attributes['deleted_at'] = is_array($row) ? ($row['deleted_at'] ?? null) : null;
            }

            // Om modellen är soft-deleted (`deleted_at` har ett värde), återställ den
            if (!is_null($this->attributes['deleted_at'])) {
                $this->attributes['deleted_at'] = null;

                // Uppdatera posten i databasen och returnera bool
                $query = "UPDATE `$this->table` SET `deleted_at` = NULL WHERE `$this->primaryKey` = ?";
                $affected = $this->getConnection()
                    ->execute($query, [$this->attributes[$this->primaryKey]])
                    ->rowCount() > 0;

                // Återställ den ursprungliga `guarded`
                $this->setGuarded($originalGuarded);

                return $affected;
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
     *
     * @param  int|string  $id
     * @param  bool  $withTrashed
     * @return static|null
     */
    public static function find(int|string $id, bool $withTrashed = false): ?static
    {
        $modelClass = static::class;
        $instance = new $modelClass();

        $query = (new QueryBuilder())
            ->setConnection($instance->getConnection())
            ->setModelClass($modelClass)
            ->from($instance->getTable());

        if (!$withTrashed && $instance->softDeletes) {
            $query->whereNull('deleted_at');
        }

        $query->where($instance->primaryKey, '=', $id);

        /** @var static|null $model */
        $model = $query->first();

        if ($model && property_exists($model, 'autoloadRelations') && !empty($model->autoloadRelations)) {
            foreach ($model->autoloadRelations as $relation) {
                if ($model->relationExists($relation)) {
                    $relObj = $model->$relation();

                    if (is_object($relObj) && method_exists($relObj, 'get')) {
                        $related = $relObj->get();
                    } else {
                        $related = null;
                    }

                    $model->setRelation($relation, $related);
                }
            }
        }

        return $model;
    }

    /**
     * Hämta alla rader från tabellen.
     */
    public static function all(): Collection
    {
        return self::query()->get();
    }

    /**
     * Definiera en "hasMany"-relation.
     */
    public function hasMany(string $relatedModel, string $foreignKey, ?string $localKey = null): HasMany
    {
        $localKey = $localKey ?? $this->primaryKey;

        if (!class_exists($relatedModel)) {
            throw new \Exception("Relation model class '$relatedModel' not found.");
        }

        // Skapa relationen med key-namnet, och koppla parent efteråt
        $relation = new HasMany(
            $this->getConnection(),
            $relatedModel,
            $foreignKey,
            $localKey
        );

        $relation->setParent($this);

        return $relation;
    }

    /**
     * Definiera en "hasManyThrough"-relation.
     *
     * Struktur:
     *  parent -> through -> related (många)
     *
     * Parametrar:
     *  - $related     Relaterad modellklass eller tabellnamn (ex: Vote::class eller 'votes')
     *  - $through     Mellanmodellklass eller tabellnamn (ex: Subject::class eller 'subjects')
     *  - $firstKey    Kolumn på through som pekar till parent (ex: subjects.category_id)
     *  - $secondKey   Kolumn på related som pekar till through (ex: votes.subject_id)
     *  - $localKey    Kolumn på parent som matchar $firstKey (default 'id')
     *  - $secondLocal Kolumn på through som matchar $secondKey (default 'id')
     *
     * Exempel:
     *  public function votes(): HasManyThrough {
     *      return $this->hasManyThrough(Vote::class, Subject::class, 'category_id', 'subject_id', 'id', 'id');
     *  }
     *
     *  // Hämta alla relaterade
     *  $category->votes()->get();
     *
     *  // Aggregat via QueryBuilder:
     *  Category::query()->withCount('votes')->withSum('votes', 'points', 'votes_sum')->get();
     */
    public function hasManyThrough(
        string $related,
        string $through,
        string $firstKey,
        string $secondKey,
        ?string $localKey = null,
        ?string $secondLocal = null
    ): HasManyThrough {
        $localKey = $localKey ?? $this->primaryKey;
        $secondLocal = $secondLocal ?? 'id';

        $relation = new HasManyThrough(
            $this->getConnection(),
            $related,
            $through,
            $firstKey,
            $secondKey,
            $localKey,
            $secondLocal
        );

        $relation->setParent($this);

        return $relation;
    }

    /**
     * Definiera en "hasOneThrough"-relation.
     *
     * Struktur:
     *  parent -> through -> related
     *
     * Parametrar:
     *  - $related     Relaterad modellklass eller tabellnamn (ex: Vote::class eller 'votes')
     *  - $through     Mellanmodellklass eller tabellnamn (ex: Subject::class eller 'subjects')
     *  - $firstKey    Kolumn på through som pekar till parent (ex: subjects.category_id)
     *  - $secondKey   Kolumn på related som pekar till through (ex: votes.subject_id)
     *  - $localKey    Kolumn på parent som matchar $firstKey (default 'id')
     *  - $secondLocal Kolumn på through som matchar $secondKey (default 'id')
     *
     * Exempel:
     *  public function topVote(): HasOneThrough {
     *      return $this->hasOneThrough(Vote::class, Subject::class, 'category_id', 'subject_id', 'id', 'id');
     *  }
     *
     *  // Hämta posten
     *  $category->topVote()->first();
     */
    public function hasOneThrough(
        string $related,
        string $through,
        string $firstKey,
        string $secondKey,
        ?string $localKey = null,
        ?string $secondLocal = null
    ): \Radix\Database\ORM\Relationships\HasOneThrough {
        $localKey = $localKey ?? $this->primaryKey;
        $secondLocal = $secondLocal ?? 'id';

        $relation = new \Radix\Database\ORM\Relationships\HasOneThrough(
            $this->getConnection(),
            $related,
            $through,
            $firstKey,
            $secondKey,
            $localKey,
            $secondLocal
        );

        $relation->setParent($this);

        return $relation;
    }

    /**
     * Definiera en "hasOne"-relation.
     */
    public function hasOne(string $relatedModel, string $foreignKey, ?string $localKey = null): HasOne
    {
        $localKey = $localKey ?? $this->primaryKey;

        if (!class_exists($relatedModel)) {
            throw new \Exception("Relation model class '$relatedModel' not found.");
        }

        $relation = new HasOne(
            $this->getConnection(),
            $relatedModel,
            $foreignKey,
            $localKey // skicka key-namn
        );

        $relation->setParent($this);

        return $relation;
    }
    public function belongsToMany(
        string $relatedModel,
        string $pivotTable,
        string $foreignPivotKey,
        string $relatedPivotKey,
        ?string $parentKey = null
    ): BelongsToMany {
        $parentKey = $parentKey ?? $this->primaryKey;

        if (!class_exists($relatedModel)) {
            throw new \Exception("Relation model class '$relatedModel' not found.");
        }

        $relation = new BelongsToMany(
            $this->getConnection(),
            $relatedModel,     // skicka modellklass
            $pivotTable,
            $foreignPivotKey,
            $relatedPivotKey,
            $parentKey         // skicka key-namn
        );

        $relation->setParent($this);

        return $relation;
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

        if (!$relatedInstance instanceof self) {
            throw new \LogicException(
                "belongsTo-relaterad klass '$relatedModel' måste ärva " . self::class . "."
            );
        }

        /** @var self $relatedInstance */

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
     * @return array<int, string>
     */
    public static function availableQueryBuilderMethods(): array
    {
        $queryBuilderClass = QueryBuilder::class;
        return get_class_methods($queryBuilderClass);
    }

    /**
     * @return array<int, array{
     *     name: string,
     *     parameters: array<int, array{name: string, hasDefault: bool}>,
     *     returnsSelf: bool
     * }>
     */
    public static function describeQueryBuilderMethods(): array
    {
        $queryBuilderClass = \Radix\Database\QueryBuilder\QueryBuilder::class;
        $ref = new \ReflectionClass($queryBuilderClass);

        /** @var array<int, array{
         *     name: string,
         *     parameters: array<int, array{name: string, hasDefault: bool}>,
         *     returnsSelf: bool
         * }> $out
         */
        $out = [];

        foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (
                $method->isConstructor()
                || $method->getDeclaringClass()->getName() !== $queryBuilderClass
            ) {
                continue;
            }

            /** @var array<int, array{name: string, hasDefault: bool}> $params */
            $params = [];
            foreach ($method->getParameters() as $p) {
                $params[] = [
                    'name' => '$' . $p->getName(),
                    'hasDefault' => $p->isDefaultValueAvailable(),
                ];
            }

            $returnType = $method->getReturnType();

            $out[] = [
                'name' => $method->getName(),
                'parameters' => $params,
                'returnsSelf' =>
                    $returnType instanceof \ReflectionNamedType
                    && in_array(
                        $returnType->getName(),
                        ['self', 'static', $queryBuilderClass],
                        true
                    ),
            ];
        }

        return $out;
    }

    /**
     * Exempel:
     *  $user->load('posts');
     *  $user->load(['posts', 'profile']);
     *  $user->load(['posts' => function (QueryBuilder $q) { $q->where('status', '=', 'published'); }]);
     *
     * @param array<int, string>|array<string, \Closure>|string $relations
     */
    public function load(array|string $relations): self
    {
        $relations = is_array($relations) ? $relations : [$relations];

        foreach ($relations as $key => $constraint) {
            // Stöd både 'rel' och 'rel' => closure
            if (is_int($key)) {
                // numeriska nycklar: värdet ÄR relationsnamnet och ska vara string
                if (!is_string($constraint)) {
                    throw new \InvalidArgumentException('Relation name must be a string for numeric keys.');
                }
                $name = $constraint;
                $closure = null;
            } else {
                // assoc: nyckeln är relationsnamn, värdet är \Closure
                $name = $key;
                $closure = $constraint;
            }

            if (!$this->relationExists($name)) {
                throw new \InvalidArgumentException("Relation '$name' är inte definierad i modellen " . static::class . ".");
            }

            $relObj = $this->$name();

            // Sätt parent om möjligt
            if (is_object($relObj) && method_exists($relObj, 'setParent')) {
                $relObj->setParent($this);
            }

            $relatedData = null;

            if ($closure instanceof \Closure) {
                $ref = new \ReflectionFunction($closure);
                // Säker typ-hämtning utan ReflectionType::getName()
                $paramType = null;
                if ($ref->getNumberOfParameters() === 1) {
                    $type = $ref->getParameters()[0]->getType();
                    if ($type instanceof \ReflectionNamedType) {
                        $paramType = $type->getName();
                    } elseif ($type instanceof \ReflectionUnionType) {
                        $names = array_map(
                            static fn($t) => $t instanceof \ReflectionNamedType ? $t->getName() : null,
                            $type->getTypes()
                        );
                        $names = array_values(array_filter($names));
                        $paramType = in_array(\Radix\Database\QueryBuilder\QueryBuilder::class, $names, true)
                            ? \Radix\Database\QueryBuilder\QueryBuilder::class
                            : ($names[0] ?? null);
                    } elseif (class_exists('\ReflectionIntersectionType') && $type instanceof \ReflectionIntersectionType) {
                        $names = array_map(
                            static fn($t) => $t instanceof \ReflectionNamedType ? $t->getName() : null,
                            $type->getTypes()
                        );
                        $names = array_values(array_filter($names));
                        $paramType = $names[0] ?? null;
                    }
                }

                // Försök extrahera QueryBuilder (om relationen har en)
                $query = null;
                if (is_object($relObj) && method_exists($relObj, 'getQuery')) {
                    $query = $relObj->getQuery();
                } elseif (is_object($relObj) && method_exists($relObj, 'query')) {
                    $query = $relObj->query();
                }

                if ($paramType === QueryBuilder::class) {
                    if ($query instanceof QueryBuilder) {
                        // Ge closuren relationens QueryBuilder
                        $closure($query);
                        // Låt relationen hämta enligt sin get()
                        if (is_object($relObj) && method_exists($relObj, 'get')) {
                            $relatedData = $relObj->get();
                        } else {
                            $relatedData = $query->get();
                        }
                    } else {
                        // Skapa en fristående QB som verkar mot relaterade tabellen
                        $relatedTable = null;
                        $relatedModelClass = null;

                        // HasMany/HasOne: har 'modelClass'
                        if (is_object($relObj) && property_exists($relObj, 'modelClass')) {
                            $rc = new \ReflectionClass($relObj);
                            if ($rc->hasProperty('modelClass')) {
                                $p = $rc->getProperty('modelClass');
                                $p->setAccessible(true);
                                $relatedModelClass = $p->getValue($relObj);
                            }
                        }
                        // BelongsTo: har 'relatedTable'
                        if ($relatedModelClass === null && is_object($relObj) && property_exists($relObj, 'relatedTable')) {
                            $rc = new \ReflectionClass($relObj);
                            if ($rc->hasProperty('relatedTable')) {
                                $p = $rc->getProperty('relatedTable');
                                $p->setAccessible(true);
                                $relatedTable = $p->getValue($relObj);
                            }
                        }

                        if (is_string($relatedModelClass) && class_exists($relatedModelClass)) {
                            $tmpModel = new $relatedModelClass();
                            if ($tmpModel instanceof self) {
                                /** @var self $tmpModel */
                                $relatedTable = $tmpModel->getTable();
                            }
                        }

                        $tableForQuery = $relatedTable ?? $name;
                        if (!is_string($tableForQuery)) {
                            throw new \LogicException('Related table name must be a string.');
                        }

                        $modelClassForQuery = is_string($relatedModelClass) ? $relatedModelClass : static::class;

                        $qb = (new QueryBuilder())
                            ->setConnection($this->getConnection())
                            ->setModelClass($modelClassForQuery)
                            ->from($tableForQuery);

                        // Applicera foreign key-filter om möjligt (för HasMany/HasOne)
                        try {
                            if (is_object($relObj)) {
                                $rc = new \ReflectionClass($relObj);
                                if ($rc->hasProperty('foreignKey') && $rc->hasProperty('localKeyName')) {
                                    $pfk = $rc->getProperty('foreignKey');
                                    $pfk->setAccessible(true);
                                    $plk = $rc->getProperty('localKeyName');
                                    $plk->setAccessible(true);

                                    $foreignKey = $pfk->getValue($relObj);
                                    $localKeyName = $plk->getValue($relObj);

                                    if (!is_string($foreignKey) || !is_string($localKeyName)) {
                                        throw new \LogicException('Relation foreignKey/localKeyName must be strings.');
                                    }

                                    $localValue = $this->getAttribute($localKeyName);
                                    if ($localValue !== null) {
                                        $qb->where($foreignKey, '=', $localValue);
                                    }
                                }
                            }
                        } catch (\Throwable) {
                            // ignoreras
                        }

                        $closure($qb);
                        $relatedData = $qb->get();
                    }
                } elseif (
                    $paramType === null
                    || (is_string($paramType) && str_starts_with($paramType, 'Radix\\Database\\ORM\\Relationships\\'))
                ) {
                    // Skicka relationsobjektet (withDefault m.m.)
                    $closure($relObj);
                    if (is_object($relObj) && method_exists($relObj, 'get')) {
                        $relatedData = $relObj->get();
                    } elseif ($query instanceof QueryBuilder) {
                        $relatedData = $query->get();
                    } else {
                        $relatedData = null;
                    }
                } else {
                    // Okänd typ: försök QB, annars relation
                    if ($query instanceof QueryBuilder) {
                        $closure($query);
                        if (is_object($relObj) && method_exists($relObj, 'get')) {
                            $relatedData = $relObj->get();
                        } else {
                            $relatedData = $query->get();
                        }
                    } else {
                        $closure($relObj);
                        if (is_object($relObj) && method_exists($relObj, 'get')) {
                            $relatedData = $relObj->get();
                        } else {
                            $relatedData = null;
                        }
                    }
                }
            } else {
                // Ingen constraint: hämta via relationens get()
                if (is_object($relObj) && method_exists($relObj, 'get')) {
                    $relatedData = $relObj->get();
                } else {
                    $relatedData = null;
                }
            }

            $this->setRelation($name, is_array($relatedData) ? $relatedData : ($relatedData ?? null));
        }

        return $this;
    }

    /**
     * Ladda relationer endast om de saknas (inte redan laddade).
     *
     * Exempel:
     *  $user->loadMissing(['posts', 'profile']);
     *  $user->loadMissing(['posts' => function (QueryBuilder $q) { $q->where('status', '=', 'published'); }]);
     *
     * @param array<int, string>|array<string, \Closure>|string $relations
     */
    public function loadMissing(array|string $relations): self
    {
        $relations = is_array($relations) ? $relations : [$relations];

        foreach ($relations as $key => $constraint) {
            if (is_int($key)) {
                if (!is_string($constraint)) {
                    // enligt signaturen ska värdet här vara string; hoppa annars
                    continue;
                }
                $name = $constraint;
            } else {
                $name = $key;
            }

            if (array_key_exists($name, $this->relations)) {
                continue; // redan laddad
            }
        }

        // Kör load() med full uppsättning, men filtrera bort redan laddade
        $toLoad = [];
        foreach ($relations as $key => $constraint) {
            if (is_int($key)) {
                if (!is_string($constraint)) {
                    continue;
                }
                $name = $constraint;
            } else {
                $name = $key;
            }

            if (!array_key_exists($name, $this->relations)) {
                $toLoad[$key] = $constraint;
            }
        }

        if (!empty($toLoad)) {
            $this->load($toLoad);
        }

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $array = [];

        foreach ($this->attributes as $key => $value) {
            $array[$key] = $this->getAttribute($key);
        }

        foreach ($this->relations as $relationKey => $relationValue) {
            if ($relationValue instanceof Collection) {
                $array[$relationKey] = $relationValue->map(
                    fn($item) => $item instanceof self ? $item->toArray() : $item
                )->values()->toArray();
            } elseif (is_array($relationValue)) {
                $array[$relationKey] = array_map(
                    fn($item) => $item instanceof self ? $item->toArray() : $item,
                    $relationValue
                );
            } elseif ($relationValue instanceof self) {
                $array[$relationKey] = $relationValue->toArray();
            } else {
                $array[$relationKey] = $relationValue;
            }
        }

        if (!empty($this->autoloadRelations)) {
            foreach ($this->autoloadRelations as $relation) {
                if (!isset($array[$relation]) && $this->relationExists($relation)) {
                    $relObj = $this->$relation();

                    if (is_object($relObj) && method_exists($relObj, 'get')) {
                        $relatedData = $relObj->get();
                    } else {
                        $relatedData = null;
                    }

                    if ($relatedData instanceof Collection) {
                        $array[$relation] = $relatedData->map(
                            fn($item) => $item instanceof self ? $item->toArray() : $item
                        )->values()->toArray();
                    } elseif (is_array($relatedData)) {
                        $array[$relation] = array_map(
                            fn($item) => $item instanceof self ? $item->toArray() : $item,
                            $relatedData
                        );
                    } elseif ($relatedData instanceof self) {
                        $array[$relation] = $relatedData->toArray();
                    } else {
                        $array[$relation] = $relatedData;
                    }
                }
            }
        }

        return $array;
    }

    public static function getPrimaryKey(): string
    {
        return 'id'; // Standard primärnyckel
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
       return $this->toArray();
    }
}