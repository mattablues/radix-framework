<?php

declare(strict_types=1);

namespace Radix\Tests\Database\Query;

use InvalidArgumentException;
use LogicException;
use PDO;
use PHPUnit\Framework\TestCase;
use Radix\Database\Connection;
use Radix\Database\ORM\Model;
use Radix\Database\QueryBuilder\QueryBuilder;
use ReflectionClass;
use stdClass;

/**
 * Tester kring eager loading (with()) på QueryBuilder.
 */
final class QueryBuilderEagerLoadTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        // Skapa en PDO-instans för SQLite i minnet
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Använd den uppdaterade Connection-klassen
        $this->connection = new Connection($pdo);
    }

    public function testWithThrowsWhenModelClassIsNotASubclassOfModel(): void
    {
        // Minimal QueryBuilder-subklass som låter oss manipulera modelClass
        $builder = new class extends QueryBuilder {
            public function forceModelClass(string $cls): void
            {
                $ref = new ReflectionClass(QueryBuilder::class);
                $prop = $ref->getProperty('modelClass');
                $prop->setAccessible(true);
                $prop->setValue($this, $cls);
            }
        };

        // Behöver en anslutning för att QueryBuilder inte ska krascha på connection-relaterat
        $pdo = new PDO('sqlite::memory:');
        $connection = new Connection($pdo);
        $builder->setConnection($connection);

        // Sätt en klass som INTE är en Model-subklass
        $builder->forceModelClass(stdClass::class);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Model class must be set and extend ' . Model::class);

        // Detta ska kasta, både när modelClass är null och när den är fel typ.
        // LogicalOr-mutanten (|| -> &&) gör att fallet med fel typ INTE kastar.
        $builder->with(['nonexistentRelation']);
    }

    public function testWithRejectsBaseModelMethodAsRelation(): void
    {
        $model = new class extends \Radix\Database\ORM\Model {
            protected string $table = 'users';
            /** @var array<int,string> */
            protected array $fillable = ['id'];
        };

        $qb = (new \Radix\Database\QueryBuilder\QueryBuilder())
            ->setConnection($this->connection)
            ->setModelClass(get_class($model))
            ->from('users');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Relation 'save' is not defined");

        $qb->with('save');
    }

    public function testWithAcceptsPublicZeroArgumentRelationMethod(): void
    {
        $model = new class extends \Radix\Database\ORM\Model {
            protected string $table = 'users';
            /** @var array<int,string> */
            protected array $fillable = ['id'];

            public function profile(): object
            {
                return new class {
                    public function setParent(\Radix\Database\ORM\Model $parent): self
                    {
                        return $this;
                    }

                    public function get(): null
                    {
                        return null;
                    }
                };
            }
        };

        $qb = (new \Radix\Database\QueryBuilder\QueryBuilder())
            ->setConnection($this->connection)
            ->setModelClass(get_class($model))
            ->from('users')
            ->with('profile');

        $this->assertSame(
            'SELECT * FROM `users`',
            $qb->toSql()
        );
    }
}
