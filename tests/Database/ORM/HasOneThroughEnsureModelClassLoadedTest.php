<?php

declare(strict_types=1);

namespace Radix\Tests\Database\ORM;

use Exception;
use PHPUnit\Framework\TestCase;
use Radix\Database\Connection;
use Radix\Database\ORM\Model;
use Radix\Database\ORM\Relationships\HasOne;
use Radix\Database\ORM\Relationships\HasOneThrough;

final class HasOneThroughEnsureModelClassLoadedTest extends TestCase
{
    public function testThrowsExceptionWhenRelatedModelClassIsMissing(): void
    {
        $connection = $this->createMock(Connection::class);

        $parent = new class extends Model {
            protected string $table = 'parents';
            /** @var array<int, string> */
            protected array $fillable = ['id'];
        };
        $parent->forceFill(['id' => 1]);

        $missingRelated = 'Dummy\\Models\\__MissingRelatedModel__';
        $existingThrough = DummyThroughModel::class;

        $rel = new HasOneThrough(
            $connection,
            $missingRelated,
            $existingThrough,
            'through_fk',
            'related_fk',
            'id',
            'id'
        );
        $rel->setParent($parent);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Model class '{$missingRelated}' not found.");

        $rel->first();
    }

    public function testThrowsExceptionWhenThroughModelClassIsMissing(): void
    {
        $connection = $this->createMock(Connection::class);

        $parent = new class extends Model {
            protected string $table = 'parents';
            /** @var array<int, string> */
            protected array $fillable = ['id'];
        };
        $parent->forceFill(['id' => 1]);

        $existingRelated = DummyRelatedModel::class;
        $missingThrough = 'Dummy\\Models\\__MissingThroughModel__';

        $rel = new HasOneThrough(
            $connection,
            $existingRelated,
            $missingThrough,
            'through_fk',
            'related_fk',
            'id',
            'id'
        );
        $rel->setParent($parent);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Model class '{$missingThrough}' not found.");

        $rel->first();
    }

    public function testConstructorThrowsNotFoundFromEnsureModelClassLoaded(): void
    {
        $connection = $this->createMock(Connection::class);

        $missing = 'Dummy\\Models\\__MissingHasOneModel__';

        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Model class '{$missing}' not found.");

        new HasOne(
            $connection,
            $missing,
            'parent_id',
            'id'
        );
    }
}

final class DummyRelatedModel extends Model
{
    protected string $table = 'related';
}

final class DummyThroughModel extends Model
{
    protected string $table = 'through';
}
