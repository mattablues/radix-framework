<?php

declare(strict_types=1);

namespace Radix\Tests\Database\ORM;

use PHPUnit\Framework\TestCase;
use Radix\Database\Connection;
use Radix\Database\ORM\Model;
use Radix\Database\ORM\Relationships\HasMany;

final class HasManyQueryTest extends TestCase
{
    public function testQueryAddsForeignKeyWhereFromParentLocalKey(): void
    {
        $connection = $this->createMock(Connection::class);

        $parent = new class extends Model {
            protected string $table = 'parents';
            /** @var array<int,string> */
            protected array $fillable = ['id'];
        };
        $parent->forceFill(['id' => 123]);

        $related = new class extends Model {
            protected string $table = 'children';
            /** @var array<int,string> */
            protected array $fillable = ['id', 'parent_id'];
        };

        $relation = new HasMany(
            $connection,
            get_class($related),
            'parent_id',
            'id'
        );
        $relation->setParent($parent);

        $qb = $relation->query();

        $this->assertSame(
            'SELECT * FROM `children` WHERE `parent_id` = ?',
            $qb->toSql()
        );
        $this->assertSame([123], $qb->getBindings());
    }

    public function testQueryWithoutParentUsesLocalKeyNameAsValueForBackwardsCompatibility(): void
    {
        $connection = $this->createMock(Connection::class);

        $related = new class extends Model {
            protected string $table = 'children';
            /** @var array<int,string> */
            protected array $fillable = ['id', 'parent_id'];
        };

        $relation = new HasMany(
            $connection,
            get_class($related),
            'parent_id',
            '123'
        );

        $qb = $relation->query();

        $this->assertSame(
            'SELECT * FROM `children` WHERE `parent_id` = ?',
            $qb->toSql()
        );
        $this->assertSame(['123'], $qb->getBindings());
    }

    public function testQueryWithParentMissingLocalKeyReturnsEmptyResultQuery(): void
    {
        $connection = $this->createMock(Connection::class);

        $parent = new class extends Model {
            protected string $table = 'parents';
            /** @var array<int,string> */
            protected array $fillable = ['id'];
        };

        $related = new class extends Model {
            protected string $table = 'children';
            /** @var array<int,string> */
            protected array $fillable = ['id', 'parent_id'];
        };

        $relation = new HasMany(
            $connection,
            get_class($related),
            'parent_id',
            'id'
        );
        $relation->setParent($parent);

        $qb = $relation->query();

        $this->assertStringContainsString('1 = 0', $qb->toSql());
        $this->assertSame([], $qb->getBindings());
    }
}
