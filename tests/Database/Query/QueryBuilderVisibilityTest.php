<?php

declare(strict_types=1);

namespace Radix\Tests\Database\Query;

use PHPUnit\Framework\TestCase;
use Radix\Database\QueryBuilder\QueryBuilder;
use ReflectionMethod;

final class QueryBuilderVisibilityTest extends TestCase
{
    public function testToSqlIsPublic(): void
    {
        $ref = new ReflectionMethod(QueryBuilder::class, 'toSql');
        self::assertTrue($ref->isPublic(), 'QueryBuilder::toSql() ska vara public.');
    }
}
