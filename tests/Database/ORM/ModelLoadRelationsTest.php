<?php

declare(strict_types=1);

namespace Radix\Tests\Database\ORM;

use PDO;
use PHPUnit\Framework\TestCase;
use Radix\Database\Connection;
use Radix\Database\ORM\Model;
use Radix\Database\ORM\Relationships\HasMany;
use Radix\Database\QueryBuilder\AbstractQueryBuilder;
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
                    return str_contains($sql, '1 = 0')
                        && !str_contains($sql, '`parent_id` = ?');
                }),
                $this->equalTo([])
            )
            ->willReturn([]);

        $parent = $this->makeParentModel();

        $refConn = new ReflectionProperty($parent, 'connectionForTest');
        $refConn->setAccessible(true);
        $refConn->setValue($parent, $connection);

        $parent->forceFill(['id' => null, 'name' => 'P']);

        $received = null;

        $parent->load([
            'children' => function (QueryBuilder $qb) use (&$received): void {
                $received = $qb;
            },
        ]);

        $this->assertInstanceOf(QueryBuilder::class, $received);
        $this->assertStringContainsString('1 = 0', $received->toSql());
        $this->assertStringNotContainsString('`parent_id` = ?', $received->toSql());

        $loaded = $parent->getRelation('children');

        $this->assertInstanceOf(\Radix\Collection\Collection::class, $loaded);
        $this->assertTrue($loaded->isEmpty());
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

    public function testRelationLoadedReturnsFalseWhenRelationIsMissing(): void
    {
        $model = new class extends \Radix\Database\ORM\Model {
            protected string $table = 'users';
        };

        $this->assertFalse($model->relationLoaded('profile'));
    }

    public function testRelationLoadedReturnsTrueWhenRelationIsLoadedAsNull(): void
    {
        $model = new class extends \Radix\Database\ORM\Model {
            protected string $table = 'users';
        };

        $model->setRelation('profile', null);

        $this->assertTrue($model->relationLoaded('profile'));
        $this->assertNull($model->getRelation('profile'));
    }

    public function testUnsetRelationRemovesLoadedRelation(): void
    {
        $model = new class extends \Radix\Database\ORM\Model {
            protected string $table = 'users';
        };

        $model->setRelation('profile', null);

        $this->assertTrue($model->relationLoaded('profile'));

        $model->unsetRelation('profile');

        $this->assertFalse($model->relationLoaded('profile'));
    }

    public function testWithoutRelationsRemovesAllLoadedRelations(): void
    {
        $model = new class extends \Radix\Database\ORM\Model {
            protected string $table = 'users';
        };

        $model->setRelation('profile', null);
        $model->setRelation('posts', []);

        $this->assertTrue($model->relationLoaded('profile'));
        $this->assertTrue($model->relationLoaded('posts'));

        $model->withoutRelations();

        $this->assertFalse($model->relationLoaded('profile'));
        $this->assertFalse($model->relationLoaded('posts'));
    }

    public function testMagicGetReturnsLoadedRelationValueBeforeCallingRelationMethod(): void
    {
        $model = new class extends \Radix\Database\ORM\Model {
            protected string $table = 'users';

            public function profile(): string
            {
                return 'relation-object';
            }
        };

        $model->setRelation('profile', null);

        $this->assertNull($model->__get('profile'));
    }

    public function testRelationExistsReturnsFalseForMissingMethod(): void
    {
        $model = new class extends \Radix\Database\ORM\Model {
            protected string $table = 'users';
        };

        $this->assertFalse($model->relationExists('missingRelation'));
    }

    public function testRelationExistsReturnsTrueForPublicZeroArgumentUserRelationMethod(): void
    {
        $model = new class extends \Radix\Database\ORM\Model {
            protected string $table = 'users';

            public function profile(): mixed
            {
                return null;
            }
        };

        $this->assertTrue($model->relationExists('profile'));
    }

    public function testRelationExistsReturnsFalseForModelBaseMethods(): void
    {
        $model = new class extends \Radix\Database\ORM\Model {
            protected string $table = 'users';
        };

        $this->assertFalse($model->relationExists('save'));
        $this->assertFalse($model->relationExists('delete'));
        $this->assertFalse($model->relationExists('toArray'));
        $this->assertFalse($model->relationExists('load'));
    }

    public function testRelationExistsReturnsFalseForMethodWithRequiredParameters(): void
    {
        $model = new class extends \Radix\Database\ORM\Model {
            protected string $table = 'users';

            public function profile(string $type): mixed
            {
                return $type;
            }
        };

        $this->assertFalse($model->relationExists('profile'));
    }

    public function testRelationExistsReturnsFalseForNonPublicMethod(): void
    {
        $model = new class extends \Radix\Database\ORM\Model {
            protected string $table = 'users';

            protected function profile(): mixed
            {
                return null;
            }
        };

        $this->assertFalse($model->relationExists('profile'));
    }

    public function testLoadUsesRelationKeyGettersWhenAvailable(): void
    {
        $connection = $this->createMock(Connection::class);

        $connection
            ->expects($this->once())
            ->method('fetchAll')
            ->with(
                $this->callback(static function (string $sql): bool {
                    return str_contains($sql, '`custom_parent_id` = ?');
                }),
                $this->equalTo([123])
            )
            ->willReturn([]);

        $parent = new class extends Model {
            protected string $table = 'parents';
            /** @var array<int,string> */
            protected array $fillable = ['id'];

            public Connection $connectionForTest;

            protected function getConnection(): Connection
            {
                return $this->connectionForTest;
            }

            public function customChildren(): object
            {
                return new class {
                    public function setParent(Model $parent): self
                    {
                        return $this;
                    }

                    public function getForeignKey(): string
                    {
                        return 'custom_parent_id';
                    }

                    public function getLocalKeyName(): string
                    {
                        return 'id';
                    }
                };
            }
        };

        $parent->connectionForTest = $connection;
        $parent->forceFill(['id' => 123]);

        $parent->load([
            'customChildren' => static function (QueryBuilder $qb): void {
                // no-op, vi vill bara trigga fallback-QB-grenen
            },
        ]);

        $this->addToAssertionCount(1);
    }

    public function testLoadFallsBackToReflectionForRelationKeysWhenGettersAreMissing(): void
    {
        $connection = $this->createMock(Connection::class);

        $connection
            ->expects($this->once())
            ->method('fetchAll')
            ->with(
                $this->callback(static function (string $sql): bool {
                    return str_contains($sql, '`reflected_parent_id` = ?');
                }),
                $this->equalTo([456])
            )
            ->willReturn([]);

        $parent = new class extends Model {
            protected string $table = 'parents';
            /** @var array<int,string> */
            protected array $fillable = ['id'];

            public Connection $connectionForTest;

            protected function getConnection(): Connection
            {
                return $this->connectionForTest;
            }

            public function reflectedChildren(): object
            {
                return new class {
                    private string $foreignKey = 'reflected_parent_id';
                    private string $localKeyName = 'id';

                    public function setParent(Model $parent): self
                    {
                        return $this;
                    }

                    public function foreignKeyForAssertion(): string
                    {
                        return $this->foreignKey;
                    }

                    public function localKeyNameForAssertion(): string
                    {
                        return $this->localKeyName;
                    }
                };
            }
        };

        $parent->connectionForTest = $connection;
        $parent->forceFill(['id' => 456]);

        $relation = $parent->reflectedChildren();

        $this->assertTrue(method_exists($relation, 'foreignKeyForAssertion'));
        $this->assertTrue(method_exists($relation, 'localKeyNameForAssertion'));

        $this->assertSame('reflected_parent_id', $relation->foreignKeyForAssertion());
        $this->assertSame('id', $relation->localKeyNameForAssertion());

        $parent->load([
            'reflectedChildren' => static function (QueryBuilder $qb): void {
                // no-op: vi vill bara trigga fallback-QB-grenen och relation key reflection.
            },
        ]);

        $this->addToAssertionCount(1);
    }

    public function testLoadUsesRelatedModelClassGetterWhenAvailable(): void
    {
        $connection = $this->createMock(Connection::class);

        $connection
            ->expects($this->once())
            ->method('fetchAll')
            ->with(
                $this->callback(static function (string $sql): bool {
                    return str_contains($sql, 'FROM `children`');
                }),
                $this->anything()
            )
            ->willReturn([]);

        $related = new class extends Model {
            protected string $table = 'children';
            /** @var array<int,string> */
            protected array $fillable = ['id'];
        };

        $relatedClass = get_class($related);

        $parent = new class ($connection, $relatedClass) extends Model {
            protected string $table = 'parents';
            /** @var array<int,string> */
            protected array $fillable = ['id'];

            public function __construct(
                private Connection $connectionForTest,
                private string $relatedClassForTest
            ) {
                parent::__construct([]);
            }

            protected function getConnection(): Connection
            {
                return $this->connectionForTest;
            }

            public function relatedByGetter(): object
            {
                return new class ($this->relatedClassForTest) {
                    public function __construct(private string $relatedClass) {}

                    public function getRelatedModelClass(): string
                    {
                        return $this->relatedClass;
                    }

                    public function setParent(Model $parent): self
                    {
                        return $this;
                    }
                };
            }
        };

        $parent->forceFill(['id' => 1]);

        $parent->load([
            'relatedByGetter' => static function (QueryBuilder $qb): void {
                // no-op
            },
        ]);

        $this->addToAssertionCount(1);
    }

    public function testLoadUsesRelatedTableGetterWhenAvailable(): void
    {
        $connection = $this->createMock(Connection::class);

        $connection
            ->expects($this->once())
            ->method('fetchAll')
            ->with(
                $this->callback(static function (string $sql): bool {
                    return str_contains($sql, 'FROM `profiles`');
                }),
                $this->anything()
            )
            ->willReturn([]);

        $parent = new class ($connection) extends Model {
            protected string $table = 'users';
            /** @var array<int,string> */
            protected array $fillable = ['id'];

            public function __construct(private Connection $connectionForTest)
            {
                parent::__construct([]);
            }

            protected function getConnection(): Connection
            {
                return $this->connectionForTest;
            }

            public function profileByTableGetter(): object
            {
                return new class {
                    public function getRelatedTable(): string
                    {
                        return 'profiles';
                    }

                    public function setParent(Model $parent): self
                    {
                        return $this;
                    }
                };
            }
        };

        $parent->forceFill(['id' => 1]);

        $parent->load([
            'profileByTableGetter' => static function (QueryBuilder $qb): void {
                // no-op
            },
        ]);

        $this->addToAssertionCount(1);
    }

    public function testLoadWithNonRelationshipBaseQueryBuilderTypeReceivesQueryBuilderWhenAvailable(): void
    {
        $connection = $this->createMock(Connection::class);

        $connection
            ->expects($this->once())
            ->method('fetchAll')
            ->willReturn([]);

        $parent = new class ($connection) extends Model {
            protected string $table = 'parents';
            /** @var array<int,string> */
            protected array $fillable = ['id'];

            public function __construct(private Connection $connectionForTest)
            {
                parent::__construct([]);
            }

            protected function getConnection(): Connection
            {
                return $this->connectionForTest;
            }

            public function queryBackedRelation(): object
            {
                return new class ($this->connectionForTest) {
                    public function __construct(private Connection $connection) {}

                    public function setParent(Model $parent): self
                    {
                        return $this;
                    }

                    public function query(): QueryBuilder
                    {
                        return (new QueryBuilder())
                            ->setConnection($this->connection)
                            ->setModelClass($this->makeRelatedModelClass())
                            ->from('children');
                    }

                    /**
                     * @return class-string<Model>
                     */
                    private function makeRelatedModelClass(): string
                    {
                        $model = new class extends Model {
                            protected string $table = 'children';
                            /** @var array<int,string> */
                            protected array $fillable = ['id'];
                        };

                        return get_class($model);
                    }
                };
            }
        };

        $parent->forceFill(['id' => 1]);

        $received = null;

        $parent->load([
            'queryBackedRelation' => static function (AbstractQueryBuilder $qb) use (&$received): void {
                $received = $qb;
            },
        ]);

        $this->assertInstanceOf(QueryBuilder::class, $received);
    }

    public function testLoadWithQueryBuilderClosureForOneRelationStoresSingleModel(): void
    {
        $connection = $this->createMock(Connection::class);

        $connection
            ->expects($this->once())
            ->method('fetchAll')
            ->willReturn([
                ['id' => 7, 'user_id' => 123, 'avatar' => 'a.png'],
            ]);

        $parent = new class ($connection) extends Model {
            protected string $table = 'users';
            /** @var array<int,string> */
            protected array $fillable = ['id'];

            public function __construct(private Connection $connectionForTest)
            {
                parent::__construct([]);
            }

            protected function getConnection(): Connection
            {
                return $this->connectionForTest;
            }

            public function profile(): \Radix\Database\ORM\Relationships\HasOne
            {
                $related = new class extends Model {
                    protected string $table = 'profiles';
                    /** @var array<int,string> */
                    protected array $fillable = ['id', 'user_id', 'avatar'];
                };

                return (new \Radix\Database\ORM\Relationships\HasOne(
                    $this->getConnection(),
                    get_class($related),
                    'user_id',
                    'id'
                ))->setParent($this);
            }
        };

        $parent->forceFill(['id' => 123]);

        $parent->load([
            'profile' => static function (QueryBuilder $qb): void {
                $qb->where('avatar', '!=', '');
            },
        ]);

        $profile = $parent->getRelation('profile');

        $this->assertInstanceOf(Model::class, $profile);
        $this->assertSame(7, $profile->getAttribute('id'));
        $this->assertSame('a.png', $profile->getAttribute('avatar'));
    }

    public function testLoadWithQueryBuilderClosureForManyRelationStoresCollection(): void
    {
        $connection = $this->createMock(Connection::class);

        $connection
            ->expects($this->once())
            ->method('fetchAll')
            ->willReturn([
                ['id' => 1, 'parent_id' => 123, 'label' => 'A'],
            ]);

        $parent = $this->makeParentModel();

        $refConn = new ReflectionProperty($parent, 'connectionForTest');
        $refConn->setAccessible(true);
        $refConn->setValue($parent, $connection);

        $parent->forceFill(['id' => 123]);

        $parent->load([
            'children' => static function (QueryBuilder $qb): void {
                $qb->where('label', '=', 'A');
            },
        ]);

        $children = $parent->getRelation('children');

        $this->assertInstanceOf(\Radix\Collection\Collection::class, $children);
        $this->assertCount(1, $children);
    }

    public function testLoadCallsSetParentOnRelationObjectWhenSupported(): void
    {
        $model = new class extends Model {
            protected string $table = 'parents';

            public ?object $relationObject = null;

            public function trackedRelation(): object
            {
                if ($this->relationObject === null) {
                    $this->relationObject = new class {
                        public ?Model $parent = null;
                        public int $setParentCalls = 0;

                        public function setParent(Model $parent): self
                        {
                            $this->parent = $parent;
                            $this->setParentCalls++;

                            return $this;
                        }

                        /**
                         * @return array<int, Model>
                         */
                        public function get(): array
                        {
                            return [];
                        }
                    };
                }

                return $this->relationObject;
            }
        };

        $model->load('trackedRelation');

        $relation = $model->relationObject;

        $this->assertIsObject($relation);
        $this->assertTrue(property_exists($relation, 'parent'));
        $this->assertTrue(property_exists($relation, 'setParentCalls'));

        /** @var object{parent:?Model,setParentCalls:int} $relation */
        $this->assertSame($model, $relation->parent);
        $this->assertSame(1, $relation->setParentCalls);
    }

    public function testLoadUsesGetQueryWhenRelationProvidesIt(): void
    {
        $connection = $this->createMock(Connection::class);

        $connection
            ->expects($this->once())
            ->method('fetchAll')
            ->with(
                $this->callback(static function (string $sql): bool {
                    return str_contains($sql, 'FROM `children`')
                        && str_contains($sql, '`from_get_query` = ?');
                }),
                $this->equalTo([1])
            )
            ->willReturn([]);

        $model = new class ($connection) extends Model {
            protected string $table = 'parents';

            public function __construct(private Connection $connectionForTest)
            {
                parent::__construct([]);
            }

            protected function getConnection(): Connection
            {
                return $this->connectionForTest;
            }

            public function getQueryRelation(): object
            {
                return new class ($this->connectionForTest) {
                    public function __construct(private Connection $connection) {}

                    public function setParent(Model $parent): self
                    {
                        return $this;
                    }

                    public function getQuery(): QueryBuilder
                    {
                        $related = new class extends Model {
                            protected string $table = 'children';
                            /** @var array<int,string> */
                            protected array $fillable = ['id'];
                        };

                        return (new QueryBuilder())
                            ->setConnection($this->connection)
                            ->setModelClass(get_class($related))
                            ->from('children');
                    }
                };
            }
        };

        $received = null;

        $model->load([
            'getQueryRelation' => static function (QueryBuilder $query) use (&$received): void {
                $received = $query;
                $query->where('from_get_query', '=', 1);
            },
        ]);

        $this->assertInstanceOf(QueryBuilder::class, $received);
        $this->assertInstanceOf(\Radix\Collection\Collection::class, $model->getRelation('getQueryRelation'));
    }
}
