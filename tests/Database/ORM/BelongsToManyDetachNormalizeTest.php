<?php

declare(strict_types=1);

namespace Radix\Tests\Database\ORM;

use PHPUnit\Framework\TestCase;
use Radix\Database\Connection;
use Radix\Database\ORM\Model;
use Radix\Database\ORM\Relationships\BelongsToMany;

final class BelongsToManyDetachNormalizeTest extends TestCase
{
    private function makeParentModelWithId(int $id): Model
    {
        $parent = new class extends Model {
            protected string $table = 'parents';
            /** @var array<int, string> */
            protected array $fillable = ['id'];
        };
        $parent->forceFill(['id' => $id]);

        return $parent;
    }

    public function testDetachNormalizesValuesToIntegersWhenIdsArrayHasAttributes(): void
    {
        $connection = $this->createMock(Connection::class);
        $parentId   = 10;

        $connection
            ->expects($this->once())
            ->method('execute')
            ->with(
                $this->stringContains('DELETE FROM `role_user`'),
                $this->callback(function (array $bindings) use ($parentId): bool {
                    // Förväntat: [parentId, 1] – andra värdet ska vara int 1, inte en array
                    return $bindings === [$parentId, 1];
                })
            );

        $rel = new BelongsToMany(
            $connection,
            DummyDetachUserModel::class,
            'role_user',
            'role_id',
            'user_id',
            'id'
        );

        $rel->setParent($this->makeParentModelWithId($parentId));

        // ids: [1 => ['ignored-attrs']]
        // Original: normalized = [1]; mutant (78) gör normalized = [['ignored-attrs']]
        $rel->detach([1 => ['note' => 'ignored']]);
    }

    public function testDetachNormalizesStringKeysToIntegers(): void
    {
        $connection = $this->createMock(Connection::class);
        $parentId   = 42;

        $connection
            ->expects($this->once())
            ->method('execute')
            ->with(
                $this->stringContains('DELETE FROM `role_user`'),
                $this->callback(function (array $bindings) use ($parentId): bool {
                    // Förväntat: (int)'foo' === 0
                    return $bindings === [$parentId, 0];
                })
            );

        $rel = new BelongsToMany(
            $connection,
            DummyDetachUserModel::class,
            'role_user',
            'role_id',
            'user_id',
            'id'
        );

        $rel->setParent($this->makeParentModelWithId($parentId));

        // Nyckeln är icke-numerisk sträng
        // Original: (int)'foo' => 0; mutant (79) använder 'foo' som value → typ och värde skiljer.
        /** @phpstan-ignore-next-line argument.type */
        $rel->detach(['foo' => ['note' => 'ignored']]);
    }
}

/**
 * Enkel dummy-modell som bara behövs för att BelongsToMany ska acceptera en modellklass.
 */
final class DummyDetachUserModel extends Model
{
    protected string $table = 'users';
    /** @var array<int, string> */
    protected array $fillable = ['id'];
}
