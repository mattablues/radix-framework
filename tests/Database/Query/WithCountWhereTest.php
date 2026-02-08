<?php

declare(strict_types=1);

namespace Radix\Tests\Database\Query;

use InvalidArgumentException;
use LogicException;
use PDO;
use PHPUnit\Framework\TestCase;
use Radix\Database\Connection;
use Radix\Database\ORM\Model;
use Radix\Database\ORM\Relationships\BelongsTo;
use Radix\Database\ORM\Relationships\BelongsToMany;
use Radix\Database\ORM\Relationships\HasMany;
use Radix\Database\ORM\Relationships\HasOneThrough;
use Radix\Database\QueryBuilder\QueryBuilder;
use ReflectionClass;
use stdClass;

final class WithCountWhereTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->connection = new Connection($pdo);

        ParentModelStub::$conn = $this->connection;

        ParentModelStub::$rel = null;
        ParentModelStub::$tags = null;
        ParentModelStub::$owner = null;
    }

    public function testWithCountHasOneThroughResolveTableDoesNotInstantiateExistingNonModelClass(): void
    {
        $conn = ParentModelStub::$conn;
        $this->assertInstanceOf(Connection::class, $conn);

        ParentModelStub::$relCount = new HasOneThrough(
            $conn,
            stdClass::class, // class_exists=true men inte Model
            stdClass::class,
            'parent_id',
            'id',
            'id',
            'through_id'
        );

        $sql = (new QueryBuilder())
            ->setConnection($this->connection)
            ->setModelClass(ParentModelStub::class)
            ->from('parents')
            ->withCount('relCount')
            ->toSql();

        $this->assertStringContainsString('FROM `stdClass` AS r', $sql);
        $this->assertStringContainsString('INNER JOIN `stdClass` AS t', $sql);
    }

    public function testWithCountWhereHasOneThroughBuildsSqlWhenKeysAreStrings(): void
    {
        $conn = ParentModelStub::$conn;
        $this->assertInstanceOf(Connection::class, $conn);

        ParentModelStub::$rel = new HasOneThrough(
            $conn,
            'related_table',
            'through_table',
            'parent_id',   // firstKey
            'id',          // secondKey (private -> kräver setAccessible(true) för att läsas i WithCount)
            'id',          // localKey (default)
            'through_id'   // secondLocal (private -> kräver setAccessible(true))
        );

        $qb = (new QueryBuilder())
            ->setConnection($this->connection)
            ->setModelClass(ParentModelStub::class)
            ->from('parents')
            ->select(['*'])
            ->withCountWhere('rel', 'status', 'active', 'rel_count');

        $sql = $qb->toSql();

        $this->assertStringContainsString('FROM `parents`', $sql);
        $this->assertStringContainsString('FROM `related_table` AS r', $sql);
        $this->assertStringContainsString('INNER JOIN `through_table` AS t', $sql);
        $this->assertStringContainsString("AND r.`status` = 'active'", $sql);
        $this->assertStringContainsString('AS `rel_count`', $sql);
    }

    public function testWithCountWhereHasOneThroughResolveDoesNotInstantiateExistingNonModelClass(): void
    {
        $conn = ParentModelStub::$conn;
        $this->assertInstanceOf(Connection::class, $conn);

        ParentModelStub::$rel = new HasOneThrough(
            $conn,
            stdClass::class,  // class_exists=true men inte Model
            stdClass::class,
            'parent_id',
            'id',
            'id',
            'through_id'
        );

        $qb = (new QueryBuilder())
            ->setConnection($this->connection)
            ->setModelClass(ParentModelStub::class)
            ->from('parents')
            ->select(['*'])
            ->withCountWhere('rel', 'status', 'active', 'rel_count');

        $sql = $qb->toSql();

        $this->assertStringContainsString('FROM `stdClass` AS r', $sql);
        $this->assertStringContainsString('INNER JOIN `stdClass` AS t', $sql);
    }

    public function testWithCountWhereBelongsToManyNeedsAccessibleRelatedPivotKeyPrivateProperty(): void
    {
        $rel = (new ReflectionClass(BelongsToManyStub::class))->newInstanceWithoutConstructor();
        ParentModelStub::$tags = $rel;

        $qb = (new QueryBuilder())
            ->setConnection($this->connection)
            ->setModelClass(ParentModelStub::class)
            ->from('parents')
            ->select(['*'])
            ->withCountWhere('tags', 'name', 'blue', 'tags_blue');

        $sql = $qb->toSql();

        $this->assertStringContainsString('FROM `pivot_table` AS pivot', $sql);
        $this->assertStringContainsString('INNER JOIN `related_table` AS related', $sql);
        $this->assertStringContainsString('pivot.`related_id`', $sql);
        $this->assertStringContainsString("related.`name` = 'blue'", $sql);
    }

    public function testWithCountWhereBelongsToReadsPrivatePropertiesAndThrowsWhenAnyIsNotString(): void
    {
        $rel = (new ReflectionClass(BelongsToBadTypesStub::class))->newInstanceWithoutConstructor();
        ParentModelStub::$owner = $rel;

        $qb = (new QueryBuilder())
            ->setConnection($this->connection)
            ->setModelClass(ParentModelStub::class)
            ->from('parents');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('BelongsTo relation keys/tables must be strings for withCountWhere().');

        $qb->withCountWhere('owner', 'status', 'active');
    }

    public function testWithCountWhereHasManyDoesNotInstantiateExistingNonModelRelatedClass(): void
    {
        // Skapa HasMany utan ctor så vi kan sätta modelClass till stdClass (som inte är Model).
        $rel = (new ReflectionClass(HasMany::class))->newInstanceWithoutConstructor();

        $refRel = new ReflectionClass($rel);

        // modelClass: sätt till en existerande klass som INTE är Model
        $modelClassProp = $refRel->getProperty('modelClass');
        $modelClassProp->setAccessible(true);
        $modelClassProp->setValue($rel, stdClass::class);

        // foreignKey: behövs för att bygga SQL
        $fkProp = $refRel->getProperty('foreignKey');
        $fkProp->setAccessible(true);
        $fkProp->setValue($rel, 'parent_id');

        ParentModelStub::$badHasMany = $rel;

        $qb = (new QueryBuilder())
            ->setConnection($this->connection)
            ->setModelClass(ParentModelStub::class)
            ->from('parents')
            ->select(['*'])
            ->withCountWhere('badHasMany', 'status', 'active', 'bad_count');

        // Original (&&): instansierar INTE stdClass -> fallback-tabell = relationsnamnet ('badHasMany') -> funkar
        // Mutant (||): instansierar stdClass -> försöker $relatedInstance->getTable() -> fatal -> testet dör
        $sql = $qb->toSql();

        $this->assertStringContainsString('SELECT COUNT(*) FROM `badHasMany`', $sql);
        $this->assertStringContainsString('`badHasMany`.`parent_id` = `parents`.`id`', $sql);
        $this->assertStringContainsString("`badHasMany`.`status` = 'active'", $sql);
        $this->assertStringContainsString('AS `bad_count`', $sql);
    }

    public function testWithCountHasManyDoesNotInstantiateExistingNonModelRelatedClass(): void
    {
        // Skapa HasMany utan ctor så vi kan sätta modelClass till stdClass (som inte är Model).
        $rel = (new ReflectionClass(HasMany::class))->newInstanceWithoutConstructor();

        $refRel = new ReflectionClass($rel);

        // modelClass: sätt till en existerande klass som INTE är Model
        $modelClassProp = $refRel->getProperty('modelClass');
        $modelClassProp->setAccessible(true);
        $modelClassProp->setValue($rel, stdClass::class);

        // foreignKey: behövs för att bygga SQL
        $fkProp = $refRel->getProperty('foreignKey');
        $fkProp->setAccessible(true);
        $fkProp->setValue($rel, 'parent_id');

        ParentModelStub::$badHasMany = $rel;

        $sql = (new QueryBuilder())
            ->setConnection($this->connection)
            ->setModelClass(ParentModelStub::class)
            ->from('parents')
            ->withCount('badHasMany')
            ->toSql();

        // Original (&&): instansierar INTE stdClass -> fallback-tabell = relationsnamnet ('badHasMany') -> funkar
        // Mutant (||): instansierar stdClass -> försöker $relatedInstance->getTable() -> fatal -> testet dör
        $this->assertStringContainsString('SELECT COUNT(*) FROM `badHasMany`', $sql);
        $this->assertStringContainsString('`badHasMany`.`parent_id` = `parents`.`id`', $sql);
        $this->assertStringContainsString('AS `bad_has_many_count`', $sql);
    }

    public function testWithCountWhereAutoAliasUsesScalarSuffixForFloat(): void
    {
        $rel = (new ReflectionClass(HasMany::class))->newInstanceWithoutConstructor();
        $refRel = new ReflectionClass($rel);

        $modelClassProp = $refRel->getProperty('modelClass');
        $modelClassProp->setAccessible(true);
        $modelClassProp->setValue($rel, RelatedModelForBelongsToManyStub::class);

        $fkProp = $refRel->getProperty('foreignKey');
        $fkProp->setAccessible(true);
        $fkProp->setValue($rel, 'parent_id');

        ParentModelStub::$badHasMany = $rel;

        $sql = (new QueryBuilder())
            ->setConnection($this->connection)
            ->setModelClass(ParentModelStub::class)
            ->from('parents')
            ->select(['*'])
            ->withCountWhere('badHasMany', 'rating', 1.5, null) // <- alias AUTO
            ->toSql();

        // Alias blir snake_case av relationen + suffix
        $this->assertStringContainsString('AS `bad_has_many_count_1.5`', $sql);
        $this->assertStringContainsString("`related_table`.`rating` = 1.5", $sql);
    }

    public function testWithCountWhereAcceptsFloatValueAndBuildsSql(): void
    {
        // Återanvänd HasMany-stubben med en riktig Model-klass så relatedTable kan resolved korrekt.
        $rel = (new ReflectionClass(HasMany::class))->newInstanceWithoutConstructor();
        $refRel = new ReflectionClass($rel);

        $modelClassProp = $refRel->getProperty('modelClass');
        $modelClassProp->setAccessible(true);
        $modelClassProp->setValue($rel, RelatedModelForBelongsToManyStub::class);

        $fkProp = $refRel->getProperty('foreignKey');
        $fkProp->setAccessible(true);
        $fkProp->setValue($rel, 'parent_id');

        ParentModelStub::$badHasMany = $rel;

        $sql = (new QueryBuilder())
            ->setConnection($this->connection)
            ->setModelClass(ParentModelStub::class)
            ->from('parents')
            ->select(['*'])
            ->withCountWhere('badHasMany', 'rating', 1.5, 'rating_count')
            ->toSql();

        $this->assertStringContainsString("`related_table`.`rating` = 1.5", $sql);
        $this->assertStringContainsString('AS `rating_count`', $sql);
    }

    public function testWithCountWhereBelongsToThrowsWhenForeignKeyIsEmptyEvenIfOthersAreOk(): void
    {
        $rel = (new ReflectionClass(BelongsToEmptyForeignKeyStub::class))->newInstanceWithoutConstructor();
        ParentModelStub::$owner = $rel;

        $qb = (new QueryBuilder())
            ->setConnection($this->connection)
            ->setModelClass(ParentModelStub::class)
            ->from('parents');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('BelongsTo relation keys/tables must be strings for withCountWhere().');

        $qb->withCountWhere('owner', 'status', 'active');
    }

    public function testWithCountHasManyDoesNotUseGetTableFromNonModelEvenIfClassExists(): void
    {
        // Klass finns + har getTable(), men är INTE en Model.
        // Originalkod (&&) ska därför INTE instansiera den.
        // Mutanten (||) skulle instansiera den och ta dess getTable(), vilket ändrar SQL.
        $rel = (new ReflectionClass(HasMany::class))->newInstanceWithoutConstructor();

        $refRel = new ReflectionClass($rel);

        $modelClassProp = $refRel->getProperty('modelClass');
        $modelClassProp->setAccessible(true);
        $modelClassProp->setValue($rel, NonModelWithGetTableStub::class);

        $fkProp = $refRel->getProperty('foreignKey');
        $fkProp->setAccessible(true);
        $fkProp->setValue($rel, 'parent_id');

        ParentModelStub::$badHasMany = $rel;

        $sql = (new QueryBuilder())
            ->setConnection($this->connection)
            ->setModelClass(ParentModelStub::class)
            ->from('parents')
            ->withCount('badHasMany')
            ->toSql();

        // Vi kräver fallback-tabellen `badHasMany` (relationsnamnet).
        // Om mutanten körs kommer den använda "evil_table" och detta test faller.
        $this->assertStringContainsString('SELECT COUNT(*) FROM `badHasMany`', $sql);
        $this->assertStringContainsString('`badHasMany`.`parent_id` = `parents`.`id`', $sql);
        $this->assertStringContainsString('AS `bad_has_many_count`', $sql);
    }

    public function testWithCountWhereBelongsToCanReadAllPrivatePropertiesAndBuildSql(): void
    {
        $rel = (new ReflectionClass(BelongsToOkStub::class))->newInstanceWithoutConstructor();
        ParentModelStub::$owner = $rel;

        $qb = (new QueryBuilder())
            ->setConnection($this->connection)
            ->setModelClass(ParentModelStub::class)
            ->from('parents')
            ->select(['*'])
            ->withCountWhere('owner', 'status', 'active', 'owner_active');

        $sql = $qb->toSql();

        $this->assertStringContainsString('FROM `owners`', $sql);
        $this->assertStringContainsString('`owners`.`id` = `parents`.`owner_id`', $sql);
        $this->assertStringContainsString("`owners`.`status` = 'active'", $sql);
    }
}

/**
 * Parent model-stub som WithCountWhere kan instansiera utan ctor-argument
 * och som klarar method_exists($parent, $relation).
 */
final class ParentModelStub extends Model
{
    protected string $table = 'parents';

    public static ?Connection $conn = null;

    public static ?object $rel = null;
    public static ?object $tags = null;
    public static ?object $owner = null;

    public static ?object $relCount = null;
    public static ?object $badHasMany = null;

    public function relCount(): object
    {
        if (self::$relCount === null) {
            throw new InvalidArgumentException('Test relation relCount() not configured.');
        }
        return self::$relCount;
    }

    public function badHasMany(): object
    {
        if (self::$badHasMany === null) {
            throw new InvalidArgumentException('Test relation badHasMany() not configured.');
        }
        return self::$badHasMany;
    }

    protected function getConnection(): Connection
    {
        if (self::$conn === null) {
            throw new InvalidArgumentException('Test connection not configured.');
        }
        return self::$conn;
    }

    public function rel(): object
    {
        if (self::$rel === null) {
            throw new InvalidArgumentException('Test relation rel() not configured.');
        }
        return self::$rel;
    }

    public function tags(): object
    {
        if (self::$tags === null) {
            throw new InvalidArgumentException('Test relation tags() not configured.');
        }
        return self::$tags;
    }

    public function owner(): object
    {
        if (self::$owner === null) {
            throw new InvalidArgumentException('Test relation owner() not configured.');
        }
        return self::$owner;
    }
}

/**
 * BelongsToMany-subklass för att:
 *  - kontrollera metoderna som WithCountWhere använder
 *  - ha en private property 'relatedPivotKey' som måste läsas via Reflection + setAccessible(true)
 */
final class BelongsToManyStub extends BelongsToMany
{
    // OBS: ingen private $relatedPivotKey här längre – det var bara för Reflection-varianten.

    public function getPivotTable(): string
    {
        return 'pivot_table';
    }

    public function getForeignPivotKey(): string
    {
        return 'parent_id';
    }

    public function getRelatedPivotKey(): string
    {
        return 'related_id';
    }

    public function getRelatedModelClass(): string
    {
        return RelatedModelForBelongsToManyStub::class;
    }
}

final class RelatedModelForBelongsToManyStub extends Model
{
    protected string $table = 'related_table';
}

/**
 * BelongsTo-stubbar: WithCountWhere läser ownerKey, foreignKey, relatedTable via ReflectionProperty.
 * Vi gör dem private för att kräva setAccessible(true).
 */
final class BelongsToBadTypesStub extends BelongsTo
{
    // Med getters kan vi inte längre “smuggla in” fel typ utan att PHP stoppar oss,
    // så vi testar ogiltiga (tomma) strängar istället → ska ge LogicException.
    public function getOwnerKey(): string
    {
        return '';
    }

    public function getForeignKey(): string
    {
        return 'owner_id';
    }

    public function getRelatedTable(): string
    {
        return 'owners';
    }
}

final class BelongsToOkStub extends BelongsTo
{
    public function getOwnerKey(): string
    {
        return 'id';
    }

    public function getForeignKey(): string
    {
        return 'owner_id';
    }

    public function getRelatedTable(): string
    {
        return 'owners';
    }
}

final class BelongsToEmptyForeignKeyStub extends BelongsTo
{
    public function getOwnerKey(): string
    {
        return 'id';
    }

    public function getForeignKey(): string
    {
        return '';
    }

    public function getRelatedTable(): string
    {
        return 'owners';
    }
}

/**
 * Existerande klass, har getTable(), men är inte en Model-subklass.
 * Används för att döda LogicalAnd-mutanten i WithCount::addRelationCountSelect().
 */
final class NonModelWithGetTableStub
{
    public function getTable(): string
    {
        return 'evil_table';
    }
}
