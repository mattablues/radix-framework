<?php

declare(strict_types=1);

namespace Radix\Tests\Database\ORM;

use PHPUnit\Framework\TestCase;
use Radix\Database\Connection;
use Radix\Database\ORM\Model;
use Radix\Database\ORM\Relationships\BelongsTo;

final class BelongsToQueryTest extends TestCase
{
    public function testQueryAddsOwnerKeyWhereFromParentForeignKey(): void
    {
        $connection = $this->createMock(Connection::class);

        $parent = new class extends Model {
            protected string $table = 'posts';
            /** @var array<int,string> */
            protected array $fillable = ['id', 'user_id'];
        };
        $parent->forceFill(['user_id' => 123]);

        $related = new class extends Model {
            protected string $table = 'users';
            /** @var array<int,string> */
            protected array $fillable = ['id'];
        };

        $relation = new BelongsTo(
            $connection,
            get_class($related),
            'user_id',
            'id',
            $parent
        );

        $qb = $relation->query();

        $this->assertSame(
            'SELECT * FROM `users` WHERE `id` = ? LIMIT 1',
            $qb->toSql()
        );
        $this->assertSame([123], $qb->getBindings());
    }

    public function testQueryWithNullForeignKeyReturnsEmptyResultQuery(): void
    {
        $connection = $this->createMock(Connection::class);

        $parent = new class extends Model {
            protected string $table = 'posts';
            /** @var array<int,string> */
            protected array $fillable = ['id', 'user_id'];
        };
        $parent->forceFill(['user_id' => null]);

        $related = new class extends Model {
            protected string $table = 'users';
            /** @var array<int,string> */
            protected array $fillable = ['id'];
        };

        $relation = new BelongsTo(
            $connection,
            get_class($related),
            'user_id',
            'id',
            $parent
        );

        $qb = $relation->query();

        $this->assertStringContainsString('1 = 0', $qb->toSql());
        $this->assertStringContainsString('LIMIT 1', $qb->toSql());
        $this->assertSame([], $qb->getBindings());
    }
}
