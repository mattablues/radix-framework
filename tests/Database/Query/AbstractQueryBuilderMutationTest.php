<?php

declare(strict_types=1);

namespace Radix\Tests\Database\Query;

use Closure;
use ErrorException;
use PHPUnit\Framework\TestCase;
use Radix\Database\Connection;
use Radix\Database\ORM\Model;
use Radix\Database\QueryBuilder\AbstractQueryBuilder;
use Radix\Database\QueryBuilder\QueryBuilder;
use ReflectionMethod;
use ReflectionProperty;

final class AbstractQueryBuilderMutationTest extends TestCase
{
    private function setModelClassOnAbstractBuilder(AbstractQueryBuilder $builder, string $modelClass): void
    {
        $prop = new ReflectionProperty(AbstractQueryBuilder::class, 'modelClass');
        $prop->setAccessible(true);
        $prop->setValue($builder, $modelClass);
    }

    public function testAbstractQueryBuilderGetIsPublic(): void
    {
        $ref = new ReflectionMethod(AbstractQueryBuilder::class, 'get');
        $this->assertTrue($ref->isPublic(), 'AbstractQueryBuilder::get() ska vara public.');
    }

    public function testEagerLoadRelationsMustBeArrayWhenPropertyExists(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAll')->willReturn([
            ['id' => 1],
        ]);

        $builder = new class extends AbstractQueryBuilder {
            /** @var mixed medvetet fel typ för att trigga && vs || */
            public $eagerLoadRelations = 'not-an-array';

            public function toSql(): string
            {
                return 'SELECT * FROM dummy';
            }

            /**
             * Hjälper PHPStan: AbstractQueryBuilder::get() returnerar array|Collection, men här förväntar vi oss array.
             *
             * @return array<int, Model>
             */
            public function get(): array
            {
                /** @var array<int, Model> $rows */
                $rows = parent::get();
                return $rows;
            }
        };

        $builder->setConnection($conn);

        $model = new class extends Model {
            protected string $table = 'dummy';
            /** @var array<int, string> */
            protected array $fillable = ['id'];
        };
        $this->setModelClassOnAbstractBuilder($builder, get_class($model));

        set_error_handler(static function (int $severity, string $message): never {
            throw new ErrorException($message, 0, $severity);
        });

        try {
            /** @var array<int, Model> $rows */
            $rows = $builder->get();
            $this->assertCount(1, $rows);
        } finally {
            restore_error_handler();
        }
    }

    public function testGetMarksHydratedModelsAsExisting(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAll')->willReturn([
            ['id' => 123],
        ]);

        $model = new class extends Model {
            protected string $table = 'users';
            /** @var array<int, string> */
            protected array $fillable = ['id'];
        };

        $qb = (new QueryBuilder())
            ->setConnection($conn)
            ->from('users')
            ->setModelClass(get_class($model));

        $results = $qb->get();

        $this->assertCount(1, $results);
        $this->assertInstanceOf(Model::class, $results[0]);
        $this->assertTrue(
            $results[0]->isExisting(),
            'Hydrerade modeller från get() ska markeras som existerande.'
        );
    }

    public function testEagerLoadCallsSetParentOnRelationObjectWhenMethodExists(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAll')->willReturn([
            ['id' => 1],
        ]);

        $builder = new class extends AbstractQueryBuilder {
            /** @var array<int, string> */
            protected array $eagerLoadRelations = ['rel'];

            public function toSql(): string
            {
                return 'SELECT * FROM dummy';
            }

            /**
             * @return array<int, Model>
             */
            public function get(): array
            {
                /** @var array<int, Model> $rows */
                $rows = parent::get();
                return $rows;
            }
        };

        $builder->setConnection($conn);

        $model = new class extends Model {
            protected string $table = 'dummy';
            /** @var array<int, string> */
            protected array $fillable = ['id'];

            private ?object $relObj = null;

            public function rel(): object
            {
                if ($this->relObj === null) {
                    $this->relObj = new class {
                        public int $setParentCalls = 0;
                        public ?Model $parent = null;

                        public function setParent(Model $m): void
                        {
                            $this->setParentCalls++;
                            $this->parent = $m;
                        }

                        public function query(): QueryBuilder
                        {
                            return new QueryBuilder();
                        }

                        /**
                         * @return array<int, mixed>
                         */
                        public function get(): array
                        {
                            return [];
                        }
                    };
                }

                return $this->relObj;
            }
        };

        $this->setModelClassOnAbstractBuilder($builder, get_class($model));

        /** @var array<int, Model> $rows */
        $rows = $builder->get();

        $this->assertCount(1, $rows);

        $m = $rows[0];
        $this->assertInstanceOf(get_class($model), $m);

        $relObj = $m->rel();
        // $relObj är redan "object" (via signatur), så assertIsObject() blir redundant för PHPStan.

        /** @var object{setParentCalls:int,parent:?Model} $relObj */
        $relObj = $relObj;

        $this->assertTrue(property_exists($relObj, 'setParentCalls'));
        $this->assertSame(1, $relObj->setParentCalls, 'Eager-load ska anropa setParent() exakt en gång.');
        $this->assertSame($m, $relObj->parent, 'setParent() ska få den hydrerade modellen som parent.');
    }

    public function testEagerLoadConstraintClosureIsExecutedWhenQueryBuilderIsAvailable(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAll')->willReturn([
            ['id' => 1],
        ]);

        $builder = new class extends AbstractQueryBuilder {
            /** @var array<int, string> */
            protected array $eagerLoadRelations = ['rel'];

            public function toSql(): string
            {
                return 'SELECT * FROM dummy';
            }

            public function withConstraintForTest(string $relation, Closure $closure): void
            {
                $this->eagerLoadConstraints[$relation] = $closure;
            }

            /**
             * @return array<int, Model>
             */
            public function get(): array
            {
                /** @var array<int, Model> $rows */
                $rows = parent::get();
                return $rows;
            }
        };

        $builder->setConnection($conn);

        $model = new class extends Model {
            protected string $table = 'dummy';
            /** @var array<int, string> */
            protected array $fillable = ['id'];

            public function rel(): object
            {
                return new class {
                    public function setParent(Model $m): void
                    {
                        // no-op
                    }

                    public function query(): QueryBuilder
                    {
                        return new QueryBuilder();
                    }

                    /**
                     * @return array<int, mixed>
                     */
                    public function get(): array
                    {
                        return [];
                    }
                };
            }
        };

        $this->setModelClassOnAbstractBuilder($builder, get_class($model));

        $called = 0;
        $builder->withConstraintForTest('rel', function (QueryBuilder $q) use (&$called): void {
            $called++;
        });

        $builder->get();

        $this->assertSame(1, $called, 'Constraint-closure ska köras exakt en gång per eager-load.');
    }
}
