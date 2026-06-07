<?php

declare(strict_types=1);

namespace Radix\Tests\Database\ORM;

use PHPUnit\Framework\TestCase;
use Radix\Database\Connection;
use Radix\Database\ORM\Model;
use Radix\Database\ORM\Relationships\HasOne;

final class HasOneQueryTest extends TestCase
{
    public function testQueryAddsForeignKeyWhereFromParentLocalKeyAndLimitOne(): void
    {
        $connection = $this->createMock(Connection::class);

        $parent = new class extends Model {
            protected string $table = 'users';
            /** @var array<int,string> */
            protected array $fillable = ['id'];
        };
        $parent->forceFill(['id' => 123]);

        $related = new class extends Model {
            protected string $table = 'profiles';
            /** @var array<int,string> */
            protected array $fillable = ['id', 'user_id'];
        };

        $relation = new HasOne(
            $connection,
            get_class($related),
            'user_id',
            'id'
        );
        $relation->setParent($parent);

        $qb = $relation->query();

        $this->assertSame(
            'SELECT * FROM `profiles` WHERE `user_id` = ? LIMIT 1',
            $qb->toSql()
        );
        $this->assertSame([123], $qb->getBindings());
    }

    public function testQueryWithoutParentUsesLocalKeyNameAsValueForBackwardsCompatibility(): void
    {
        $connection = $this->createMock(Connection::class);

        $related = new class extends Model {
            protected string $table = 'profiles';
            /** @var array<int,string> */
            protected array $fillable = ['id', 'user_id'];
        };

        $relation = new HasOne(
            $connection,
            get_class($related),
            'user_id',
            '123'
        );

        $qb = $relation->query();

        $this->assertSame(
            'SELECT * FROM `profiles` WHERE `user_id` = ? LIMIT 1',
            $qb->toSql()
        );
        $this->assertSame(['123'], $qb->getBindings());
    }

    public function testQueryWithParentMissingLocalKeyReturnsEmptyResultQuery(): void
    {
        $connection = $this->createMock(Connection::class);

        $parent = new class extends Model {
            protected string $table = 'users';
            /** @var array<int,string> */
            protected array $fillable = ['id'];
        };

        $related = new class extends Model {
            protected string $table = 'profiles';
            /** @var array<int,string> */
            protected array $fillable = ['id', 'user_id'];
        };

        $relation = new HasOne(
            $connection,
            get_class($related),
            'user_id',
            'id'
        );
        $relation->setParent($parent);

        $qb = $relation->query();

        $this->assertStringContainsString('1 = 0', $qb->toSql());
        $this->assertStringContainsString('LIMIT 1', $qb->toSql());
        $this->assertSame([], $qb->getBindings());
    }
}
