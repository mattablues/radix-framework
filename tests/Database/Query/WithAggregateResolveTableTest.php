<?php

declare(strict_types=1);

namespace Radix\Tests\Database\Query;

use LogicException;
use PDO;
use PHPUnit\Framework\TestCase;
use Radix\Database\Connection;
use Radix\Database\ORM\Model;
use Radix\Database\ORM\Relationships\HasManyThrough;
use Radix\Database\ORM\Relationships\HasOneThrough;
use Radix\Database\QueryBuilder\QueryBuilder;
use stdClass;

final class WithAggregateResolveTableTest extends TestCase
{
    public function testWithAggregateHasOneThroughResolveTableDoesNotInstantiateExistingNonModelClass(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $connection = new Connection($pdo);

        $parent = new class extends Model {
            protected string $table = 'parents';

            public static ?Connection $conn = null;

            protected function getConnection(): Connection
            {
                if (self::$conn === null) {
                    throw new LogicException('Test connection not configured.');
                }
                return self::$conn;
            }

            public function rel(): HasOneThrough
            {
                return (new HasOneThrough(
                    $this->getConnection(),
                    stdClass::class,
                    stdClass::class,
                    'parent_id',
                    'id',
                    'id',
                    'through_id'
                ))->setParent($this);
            }
        };

        $parent::$conn = $connection;

        $sql = (new QueryBuilder())
            ->setConnection($connection)
            ->setModelClass($parent::class)
            ->from('parents')
            ->withMax('rel', 'points', 'rel_max')
            ->toSql();

        $this->assertStringContainsString('FROM `stdClass` AS r', $sql);
        $this->assertStringContainsString('INNER JOIN `stdClass` AS t', $sql);
    }

    public function testWithAggregateHasManyThroughResolveTableDoesNotInstantiateExistingNonModelClass(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $connection = new Connection($pdo);

        $parent = new class extends Model {
            protected string $table = 'parents';

            public static ?Connection $conn = null;

            protected function getConnection(): Connection
            {
                if (self::$conn === null) {
                    throw new LogicException('Test connection not configured.');
                }
                return self::$conn;
            }

            public function relMany(): HasManyThrough
            {
                return (new HasManyThrough(
                    $this->getConnection(),
                    stdClass::class,
                    stdClass::class,
                    'parent_id',
                    'id',
                    'id',
                    'through_id'
                ))->setParent($this);
            }
        };

        $parent::$conn = $connection;

        $sql = (new QueryBuilder())
            ->setConnection($connection)
            ->setModelClass($parent::class)
            ->from('parents')
            ->withSum('relMany', 'points', 'rel_many_sum')
            ->toSql();

        $this->assertStringContainsString('FROM `stdClass` AS r', $sql);
        $this->assertStringContainsString('INNER JOIN `stdClass` AS t', $sql);
    }
}
