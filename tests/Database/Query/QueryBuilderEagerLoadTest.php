<?php

declare(strict_types=1);

namespace Radix\Tests\Database\Query;

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
}
