<?php

declare(strict_types=1);

namespace Radix\Tests\Database\ORM;

use PHPUnit\Framework\TestCase;
use Radix\Database\Connection;
use Radix\Database\ORM\Model;
use Radix\Database\ORM\ModelClassResolverInterface;
use Radix\Database\ORM\Relationships\HasMany;

final class HasManyUsesResolverWhenProvidedTest extends TestCase
{
    public function testUsesModelClassResolverWhenProvided(): void
    {
        $connection = $this->createMock(Connection::class);

        $calls = 0;
        $resolver = new class ($calls) implements ModelClassResolverInterface {
            public function __construct(private int &$calls) {}

            public function resolve(string $classOrTable): string
            {
                $this->calls++;
                return DummyResolvedModel::class;
            }
        };

        new HasMany($connection, 'some_table_name', 'parent_id', 'id', $resolver);

        $this->assertSame(1, $calls, 'Resolver ska anropas exakt en gång.');
    }
}

final class DummyResolvedModel extends Model
{
    protected string $table = 'resolved';
}
