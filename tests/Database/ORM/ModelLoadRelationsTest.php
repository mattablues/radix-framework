<?php

declare(strict_types=1);

namespace Radix\Tests\Database\ORM;

use PDO;
use PHPUnit\Framework\TestCase;
use Radix\Database\Connection;
use Radix\Database\ORM\Model;
use Radix\Database\ORM\Relationships\HasMany;
use Radix\Database\QueryBuilder\QueryBuilder;
use ReflectionProperty;

final class ModelLoadRelationsTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->connection = new Connection($pdo);

        $pdo->exec("
            CREATE TABLE parents (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT
            );
        ");
        $pdo->exec("
            CREATE TABLE children (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                parent_id INTEGER NOT NULL,
                label TEXT
            );
        ");
    }

    private function makeParentModel(): Model
    {
        return new class extends Model {
            protected string $table = 'parents';
            /** @var array<int,string> */
            protected array $fillable = ['id', 'name'];

            public \Radix\Database\Connection $connectionForTest;

            protected function getConnection(): \Radix\Database\Connection
            {
                /** @var \Radix\Database\Connection $conn */
                $conn = $this->connectionForTest;
                return $conn;
            }

            public function children(): HasMany
            {
                $related = new class extends Model {
                    protected string $table = 'children';
                    /** @var array<int,string> */
                    protected array $fillable = ['id', 'parent_id', 'label'];
                };

                /** @var class-string<Model> $relatedClass */
                $relatedClass = $related::class;

                $relation = new HasMany(
                    $this->getConnection(),
                    $relatedClass,
                    'parent_id',
                    'id',
                    null
                );

                $relation->setParent($this);

                return $relation;
            }

            public function customRelation(): object
            {
                // Objekt utan getQuery/query/get – tvingar load() till "ny QB"-grenen.
                return new class {
                    /** @var list<string> */
                    public array $called = [];

                    public function setParent(object $parent): void
                    {
                        $this->called[] = 'setParent';
                    }
                };
            }
        };
    }

    public function testLoadWithClosureTypedAsRelationshipReceivesRelationObject(): void
    {
        $parent = $this->makeParentModel();

        $refConn = new ReflectionProperty($parent, 'connectionForTest');
        $refConn->setAccessible(true);
        $refConn->setValue($parent, $this->connection);

        $parent->forceFill(['id' => 1, 'name' => 'P']);

        $received = null;

        $parent->load([
            'children' => function (HasMany $rel) use (&$received): void {
                $received = $rel;
            },
        ]);

        $this->assertInstanceOf(
            HasMany::class,
            $received,
            'load() med typ-hint HasMany ska skicka relationsobjektet, inte en QueryBuilder.'
        );

        $relData = $parent->getRelation('children');
        $this->assertIsArray(
            $relData,
            'Efter load("children") ska relationen "children" vara satt (även om tom).'
        );
    }

    public function testLoadWithClosureTypedAsQueryBuilderReceivesQueryBuilder(): void
    {
        $parent = $this->makeParentModel();

        $refConn = new ReflectionProperty($parent, 'connectionForTest');
        $refConn->setAccessible(true);
        $refConn->setValue($parent, $this->connection);

        $parent->forceFill(['id' => 1, 'name' => 'P']);

        $received = null;

        $parent->load([
            'children' => function (QueryBuilder $qb) use (&$received): void {
                $received = $qb;
            },
        ]);

        $this->assertInstanceOf(
            QueryBuilder::class,
            $received,
            'load() med typ-hint QueryBuilder ska ge QueryBuilder-objektet till closuren.'
        );
    }

    public function testLoadWithRelationWithoutGetDoesNotCallGetOnIt(): void
    {
        $model = new class extends Model {
            protected string $table = 'dummy';
            /** @var array<int,string> */
            protected array $fillable = [];

            public function bogusRelation(): object
            {
                return new class {
                    /** @var list<string> */
                    public array $called = [];

                    public function setParent(object $parent): void
                    {
                        $this->called[] = 'setParent';
                    }
                };
            }
        };

        $model->load('bogusRelation');

        $this->assertNull(
            $model->getRelation('bogusRelation'),
            'load() ska inte försöka anropa get() på ett relationsobjekt som saknar den metoden.'
        );
    }

    public function testLoadWithQueryBuilderParameterAppliesConstraintOnNewlyCreatedQueryBuilder(): void
    {
        // Mocka Connection så vi kan inspektera SQL i fetchAll()
        $connection = $this->createMock(Connection::class);

        $connection
            ->expects($this->once())
            ->method('fetchAll')
            ->with(
                $this->callback(function (string $sql): bool {
                    return str_contains($sql, '`x` = ?');
                }),
                $this->anything()
            )
            ->willReturn([]);

        $parent = $this->makeParentModel();

        $refConn = new ReflectionProperty($parent, 'connectionForTest');
        $refConn->setAccessible(true);
        $refConn->setValue($parent, $connection);

        $parent->forceFill(['id' => 1, 'name' => 'P']);

        $parent->load([
            'customRelation' => function (QueryBuilder $qb): void {
                $qb->where('x', '=', 1);
            },
        ]);

        // Vi har redan verifierat beteendet via mockens expects()+callback.
        $this->addToAssertionCount(1);
    }

    public function testLoadWithUntypedClosureReceivesRelationshipObject(): void
    {
        $parent = $this->makeParentModel();

        $refConn = new ReflectionProperty($parent, 'connectionForTest');
        $refConn->setAccessible(true);
        $refConn->setValue($parent, $this->connection);

        $parent->forceFill(['id' => 1, 'name' => 'P']);

        $received = null;

        // Ingen typ-hint på parametern => $paramType === null i load()
        $parent->load([
            'children' => function ($rel) use (&$received): void {
                $received = $rel;
            },
        ]);

        $this->assertInstanceOf(
            HasMany::class,
            $received,
            'När closure-parametern är otypad ska load() skicka relationsobjektet (HasMany).'
        );

        $relData = $parent->getRelation('children');
        $this->assertIsArray(
            $relData,
            'Efter load("children") med otypad closure ska relationen vara laddad.'
        );
    }

    public function testLoadDoesNotAddForeignKeyWhereWhenLocalValueIsNull(): void
    {
        $connection = $this->createMock(Connection::class);

        $connection
            ->expects($this->once())
            ->method('fetchAll')
            ->with(
                $this->callback(function (string $sql): bool {
                    return !str_contains($sql, '`parent_id` = ?');
                }),
                $this->anything()
            )
            ->willReturn([]);

        $parent = $this->makeParentModel();

        $refConn = new ReflectionProperty($parent, 'connectionForTest');
        $refConn->setAccessible(true);
        $refConn->setValue($parent, $connection);

        $parent->forceFill(['id' => null, 'name' => 'P']);

        $parent->load([
            'children' => function (QueryBuilder $qb): void {
                // no-op
            },
        ]);

        // Verifierat via callbacken ovan
        $this->addToAssertionCount(1);
    }

    public function testLoadAddsForeignKeyWhereWhenLocalValueIsNotNull(): void
    {
        $connection = $this->createMock(Connection::class);

        $connection
            ->expects($this->once())
            ->method('fetchAll')
            ->with(
                $this->callback(function (string $sql): bool {
                    return str_contains($sql, '`parent_id` = ?');
                }),
                $this->anything()
            )
            ->willReturn([]);

        $parent = $this->makeParentModel();

        $refConn = new ReflectionProperty($parent, 'connectionForTest');
        $refConn->setAccessible(true);
        $refConn->setValue($parent, $connection);

        $parent->forceFill(['id' => 123, 'name' => 'P']);

        $parent->load([
            'children' => function (QueryBuilder $qb): void {
                // no-op
            },
        ]);

        // Verifierat via callbacken ovan
        $this->addToAssertionCount(1);
    }
}
