<?php

declare(strict_types=1);

namespace Radix\Tests\Database\ORM;

use PHPUnit\Framework\TestCase;
use Radix\Database\Connection;
use Radix\Database\ORM\Model;
use Radix\Database\ORM\Relationships\BelongsTo;
use Radix\Database\ORM\Relationships\BelongsToMany;
use Radix\Database\ORM\Relationships\HasMany;
use Radix\Database\ORM\Relationships\HasManyThrough;
use Radix\Database\ORM\Relationships\HasOne;
use Radix\Database\ORM\Relationships\HasOneThrough;

final class RelationCardinalityTest extends TestCase
{
    public function testHasManyIsManyRelation(): void
    {
        $connection = $this->createMock(Connection::class);

        $related = new class extends Model {
            protected string $table = 'children';
        };

        $relation = new HasMany($connection, get_class($related), 'parent_id', 'id');

        $this->assertTrue($relation->isMany());
        $this->assertFalse($relation->isOne());
    }

    public function testHasOneIsOneRelation(): void
    {
        $connection = $this->createMock(Connection::class);

        $related = new class extends Model {
            protected string $table = 'profiles';
        };

        $relation = new HasOne($connection, get_class($related), 'user_id', 'id');

        $this->assertTrue($relation->isOne());
        $this->assertFalse($relation->isMany());
    }

    public function testBelongsToIsOneRelation(): void
    {
        $connection = $this->createMock(Connection::class);

        $parent = new class extends Model {
            protected string $table = 'posts';
        };

        $related = new class extends Model {
            protected string $table = 'users';
        };

        $relation = new BelongsTo($connection, get_class($related), 'user_id', 'id', $parent);

        $this->assertTrue($relation->isOne());
        $this->assertFalse($relation->isMany());
    }

    public function testBelongsToManyIsManyRelation(): void
    {
        $connection = $this->createMock(Connection::class);

        $related = new class extends Model {
            protected string $table = 'users';
        };

        $relation = new BelongsToMany(
            $connection,
            get_class($related),
            'role_user',
            'role_id',
            'user_id',
            'id'
        );

        $this->assertTrue($relation->isMany());
        $this->assertFalse($relation->isOne());
    }

    public function testHasManyThroughIsManyRelation(): void
    {
        $connection = $this->createMock(Connection::class);

        $related = new class extends Model {
            protected string $table = 'votes';
        };

        $through = new class extends Model {
            protected string $table = 'subjects';
        };

        $relation = new HasManyThrough(
            $connection,
            get_class($related),
            get_class($through),
            'category_id',
            'subject_id'
        );

        $this->assertTrue($relation->isMany());
        $this->assertFalse($relation->isOne());
    }

    public function testHasOneThroughIsOneRelation(): void
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
            'subject_id'
        );

        $this->assertTrue($relation->isOne());
        $this->assertFalse($relation->isMany());
    }
}
