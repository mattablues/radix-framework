<?php

declare(strict_types=1);

namespace Radix\Tests\Database\ORM;

use PHPUnit\Framework\TestCase;
use Radix\Database\Connection;
use Radix\Database\ORM\Model;
use Radix\Database\ORM\Relationships\HasOne;

final class HasOneDefaultModelStateTest extends TestCase
{
    public function testWithDefaultCreatesModelMarkedAsNew(): void
    {
        $connection = $this->createMock(Connection::class);

        // Parent-modell utan id -> HasOne ska gå via returnDefaultOrNull()
        $parent = new class extends Model {
            protected string $table = 'parents';
            /** @var array<int, string> */
            protected array $fillable = ['id'];
        };
        $parent->forceFill(['id' => null]);

        $child = new class extends Model {
            protected string $table = 'children';
            private bool $markedNew = false;

            public function markAsNew(): void
            {
                $this->markedNew = true;
                parent::markAsNew();
            }

            public function wasMarkedNew(): bool
            {
                return $this->markedNew;
            }
        };

        // foreignKey: kolumn på child som pekar på parent.id
        // localKeyName: kolumn på parent (id)
        $rel = new HasOne(
            $connection,
            $child::class,
            'parent_id',
            'id'
        );
        $rel->setParent($parent);

        $result = $rel->withDefault(['foo' => 'bar'])->get();

        $this->assertInstanceOf($child::class, $result);

        /** @var Model&object{wasMarkedNew(): bool} $result */
        $this->assertTrue(
            $result->wasMarkedNew(),
            'Default-modellen från HasOne ska markeras som ny via markAsNew().'
        );
        $this->assertSame('bar', $result->getAttribute('foo'));
    }
}
