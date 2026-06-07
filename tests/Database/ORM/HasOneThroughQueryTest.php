<?php

declare(strict_types=1);

namespace Radix\Tests\Database\ORM;

use Exception;
use LogicException;
use PHPUnit\Framework\TestCase;
use Radix\Database\Connection;
use Radix\Database\ORM\Model;
use Radix\Database\ORM\Relationships\HasOneThrough;

final class HasOneThroughQueryTest extends TestCase
{
    public function testQueryBuildsJoinWhereAndLimitFromParentLocalKey(): void
    {
        $connection = $this->createMock(Connection::class);

        $parent = new class extends Model {
            protected string $table = 'categories';
            /** @var array<int,string> */
            protected array $fillable = ['id'];
        };
        $parent->forceFill(['id' => 123]);

        $related = new class extends Model {
            protected string $table = 'votes';
            /** @var array<int,string> */
            protected array $fillable = ['id', 'subject_id'];
        };

        $through = new class extends Model {
            protected string $table = 'subjects';
            /** @var array<int,string> */
            protected array $fillable = ['id', 'category_id'];
        };

        $relation = new HasOneThrough(
            $connection,
            get_class($related),
            get_class($through),
            'category_id',
            'subject_id',
            'id',
            'id'
        );
        $relation->setParent($parent);

        $qb = $relation->query();
        $sql = $qb->toSql();

        $this->assertStringContainsString('FROM `votes` AS `r`', $sql);
        $this->assertStringContainsString(
            'INNER JOIN `subjects` AS t ON t.`id` = r.`subject_id`',
            $sql
        );
        $this->assertStringContainsString('`t`.`category_id` = ?', $sql);
        $this->assertStringContainsString('LIMIT 1', $sql);
        $this->assertSame([123], $qb->getBindings());
    }

    public function testQueryWithParentMissingLocalKeyReturnsEmptyResultQuery(): void
    {
        $connection = $this->createMock(Connection::class);

        $parent = new class extends Model {
            protected string $table = 'categories';
            /** @var array<int,string> */
            protected array $fillable = ['id'];
        };

        $related = new class extends Model {
            protected string $table = 'votes';
            /** @var array<int,string> */
            protected array $fillable = ['id', 'subject_id'];
        };

        $through = new class extends Model {
            protected string $table = 'subjects';
            /** @var array<int,string> */
            protected array $fillable = ['id', 'category_id'];
        };

        $relation = new HasOneThrough(
            $connection,
            get_class($related),
            get_class($through),
            'category_id',
            'subject_id',
            'id',
            'id'
        );
        $relation->setParent($parent);

        $qb = $relation->query();
        $sql = $qb->toSql();

        $this->assertStringContainsString('FROM `votes` AS `r`', $sql);
        $this->assertStringContainsString(
            'INNER JOIN `subjects` AS t ON t.`id` = r.`subject_id`',
            $sql
        );
        $this->assertStringContainsString('1 = 0', $sql);
        $this->assertStringContainsString('LIMIT 1', $sql);
        $this->assertSame([], $qb->getBindings());
    }

    public function testQueryThrowsWhenParentIsMissing(): void
    {
        $connection = $this->createMock(Connection::class);

        $related = new class extends Model {
            protected string $table = 'votes';
        };

        $through = new class extends Model {
            protected string $table = 'subjects';
        };

        $relation = new HasOneThrough(
            $connection,
            get_class($related),
            get_class($through),
            'category_id',
            'subject_id',
            'id',
            'id'
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('HasOneThrough parent saknas.');

        $relation->query();
    }

    public function testQueryEnsuresRelatedModelClassIsLoaded(): void
    {
        $connection = $this->createMock(Connection::class);

        $parent = new class extends Model {
            protected string $table = 'categories';
            /** @var array<int,string> */
            protected array $fillable = ['id'];
        };
        $parent->forceFill(['id' => 123]);

        $through = new class extends Model {
            protected string $table = 'subjects';
            /** @var array<int,string> */
            protected array $fillable = ['id', 'category_id'];
        };

        $relation = new HasOneThrough(
            $connection,
            'Missing\\Related\\Model',
            get_class($through),
            'category_id',
            'subject_id',
            'id',
            'id'
        );
        $relation->setParent($parent);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Model class 'Missing\\Related\\Model' not found.");

        $relation->query();
    }

    public function testQueryEnsuresThroughModelClassIsLoaded(): void
    {
        $connection = $this->createMock(Connection::class);

        $parent = new class extends Model {
            protected string $table = 'categories';
            /** @var array<int,string> */
            protected array $fillable = ['id'];
        };
        $parent->forceFill(['id' => 123]);

        $related = new class extends Model {
            protected string $table = 'votes';
            /** @var array<int,string> */
            protected array $fillable = ['id', 'subject_id'];
        };

        $relation = new HasOneThrough(
            $connection,
            get_class($related),
            'Missing\\Through\\Model',
            'category_id',
            'subject_id',
            'id',
            'id'
        );
        $relation->setParent($parent);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Model class 'Missing\\Through\\Model' not found.");

        $relation->query();
    }
}
