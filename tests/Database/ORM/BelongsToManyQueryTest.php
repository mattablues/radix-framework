<?php

declare(strict_types=1);

namespace Radix\Tests\Database\ORM;

use PHPUnit\Framework\TestCase;
use Radix\Database\Connection;
use Radix\Database\ORM\Model;
use Radix\Database\ORM\Relationships\BelongsToMany;

final class BelongsToManyQueryTest extends TestCase
{
    public function testQueryBuildsPivotJoinAndWhereFromParentKey(): void
    {
        $connection = $this->createMock(Connection::class);

        $parent = new class extends Model {
            protected string $table = 'roles';
            /** @var array<int,string> */
            protected array $fillable = ['id'];
        };
        $parent->forceFill(['id' => 10]);

        $related = new class extends Model {
            protected string $table = 'users';
            /** @var array<int,string> */
            protected array $fillable = ['id'];
        };

        $relation = new BelongsToMany(
            $connection,
            get_class($related),
            'role_user',
            'role_id',
            'user_id',
            'id'
        );
        $relation->setParent($parent);

        $qb = $relation->query();
        $sql = $qb->toSql();

        $this->assertStringContainsString('FROM `users` AS `related`', $sql);
        $this->assertStringContainsString(
            'INNER JOIN `role_user` AS pivot ON related.`id` = pivot.`user_id`',
            $sql
        );
        $this->assertStringContainsString('`pivot`.`role_id` = ?', $sql);
        $this->assertSame([10], $qb->getBindings());
    }

    public function testQueryWithoutParentUsesParentKeyNameAsValueForBackwardsCompatibility(): void
    {
        $connection = $this->createMock(Connection::class);

        $related = new class extends Model {
            protected string $table = 'users';
            /** @var array<int,string> */
            protected array $fillable = ['id'];
        };

        $relation = new BelongsToMany(
            $connection,
            get_class($related),
            'role_user',
            'role_id',
            'user_id',
            '10'
        );

        $qb = $relation->query();
        $sql = $qb->toSql();

        $this->assertStringContainsString('FROM `users` AS `related`', $sql);
        $this->assertStringContainsString(
            'INNER JOIN `role_user` AS pivot ON related.`id` = pivot.`user_id`',
            $sql
        );
        $this->assertStringContainsString('`pivot`.`role_id` = ?', $sql);
        $this->assertSame(['10'], $qb->getBindings());
    }

    public function testQueryWithParentMissingKeyReturnsEmptyResultQuery(): void
    {
        $connection = $this->createMock(Connection::class);

        $parent = new class extends Model {
            protected string $table = 'roles';
            /** @var array<int,string> */
            protected array $fillable = ['id'];
        };

        $related = new class extends Model {
            protected string $table = 'users';
            /** @var array<int,string> */
            protected array $fillable = ['id'];
        };

        $relation = new BelongsToMany(
            $connection,
            get_class($related),
            'role_user',
            'role_id',
            'user_id',
            'id'
        );
        $relation->setParent($parent);

        $qb = $relation->query();

        $this->assertStringContainsString('1 = 0', $qb->toSql());
        $this->assertSame([], $qb->getBindings());
    }

    public function testQueryIncludesPivotColumnsWhenWithPivotIsUsedBeforeQuery(): void
    {
        $connection = $this->createMock(Connection::class);

        $parent = new class extends Model {
            protected string $table = 'roles';
            /** @var array<int,string> */
            protected array $fillable = ['id'];
        };
        $parent->forceFill(['id' => 10]);

        $related = new class extends Model {
            protected string $table = 'users';
            /** @var array<int,string> */
            protected array $fillable = ['id'];
        };

        $relation = new BelongsToMany(
            $connection,
            get_class($related),
            'role_user',
            'role_id',
            'user_id',
            'id'
        );
        $relation->setParent($parent);
        $relation->withPivot('created_by', 'created_at');

        $sql = $relation->query()->toSql();

        $this->assertStringContainsString('related.*', $sql);
        $this->assertStringContainsString('pivot.`created_by` AS `pivot_created_by`', $sql);
        $this->assertStringContainsString('pivot.`created_at` AS `pivot_created_at`', $sql);
    }

    public function testWithPivotUpdatesExistingBuilderSelectColumns(): void
    {
        $connection = $this->createMock(Connection::class);

        $parent = new class extends Model {
            protected string $table = 'roles';
            /** @var array<int,string> */
            protected array $fillable = ['id'];
        };
        $parent->forceFill(['id' => 10]);

        $related = new class extends Model {
            protected string $table = 'users';
            /** @var array<int,string> */
            protected array $fillable = ['id'];
        };

        $relation = new BelongsToMany(
            $connection,
            get_class($related),
            'role_user',
            'role_id',
            'user_id',
            'id'
        );
        $relation->setParent($parent);

        $relation->query();
        $relation->withPivot('created_by');

        $sql = $relation->query()->toSql();

        $this->assertStringContainsString('related.*', $sql);
        $this->assertStringContainsString('pivot.`created_by` AS `pivot_created_by`', $sql);
    }

    public function testQueryReturnsExistingBuilderInstanceWhenAlreadyBuilt(): void
    {
        $connection = $this->createMock(Connection::class);

        $parent = new class extends Model {
            protected string $table = 'roles';
            /** @var array<int,string> */
            protected array $fillable = ['id'];
        };
        $parent->forceFill(['id' => 10]);

        $related = new class extends Model {
            protected string $table = 'users';
            /** @var array<int,string> */
            protected array $fillable = ['id'];
        };

        $relation = new BelongsToMany(
            $connection,
            get_class($related),
            'role_user',
            'role_id',
            'user_id',
            'id'
        );
        $relation->setParent($parent);

        $firstBuilder = $relation->query();
        $firstBuilder->where('related.active', '=', 1);

        $secondBuilder = $relation->query();

        $this->assertSame(
            $firstBuilder,
            $secondBuilder,
            'BelongsToMany::query() ska returnera befintlig builder när den redan är skapad.'
        );

        $this->assertStringContainsString('`related`.`active` = ?', $secondBuilder->toSql());
        $this->assertSame([10, 1], $secondBuilder->getBindings());
    }
}
