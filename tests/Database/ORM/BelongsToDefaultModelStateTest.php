<?php

declare(strict_types=1);

namespace Radix\Tests\Database\ORM;

use PHPUnit\Framework\TestCase;
use Radix\Database\Connection;
use Radix\Database\ORM\Model;
use Radix\Database\ORM\Relationships\BelongsTo;

final class BelongsToDefaultModelStateTest extends TestCase
{
    public function testWithDefaultCreatesModelMarkedAsNew(): void
    {
        $connection = $this->createMock(Connection::class);
        // Inga riktiga DB-anrop – default ska användas eftersom foreign key är null
        $connection->method('fetchOne')->willReturn(null);

        $parent = new class extends Model {
            protected string $table = 'parents';
            /** @var array<int, string> */
            protected array $fillable = ['foo_id'];
        };
        // foreign key är null -> BelongsTo ska returnera default-modell
        $parent->forceFill(['foo_id' => null]);

        $related = new class extends Model {
            protected string $table = 'relateds';
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

        $rel = new BelongsTo(
            $connection,
            $related::class, // FQCN
            'foo_id',
            'id',
            $parent
        );

        $result = $rel->withDefault(['foo' => 'bar'])->get();

        $this->assertInstanceOf($related::class, $result);

        /** @var Model&object{wasMarkedNew(): bool} $result */
        $this->assertTrue(
            $result->wasMarkedNew(),
            'Default-modellen från BelongsTo ska markeras som ny via markAsNew().'
        );
    }

    public function testGetWithNullForeignKeyDoesNotHitDatabaseAndReturnsDefault(): void
    {
        $connection = $this->createMock(Connection::class);

        // Med korrekt implementation ska inget SELECT göras när foreign key är null.
        $connection
            ->expects($this->never())
            ->method('fetchOne');

        $parent = new class extends Model {
            protected string $table = 'parents';
            /** @var array<int, string> */
            protected array $fillable = ['foo_id'];
        };
        $parent->forceFill(['foo_id' => null]);

        $related = new class extends Model {
            protected string $table = 'relateds';
        };

        $rel = new BelongsTo(
            $connection,
            $related::class,
            'foo_id',
            'id',
            $parent
        );

        $result = $rel->withDefault(['foo' => 'bar'])->get();

        $this->assertInstanceOf(
            $related::class,
            $result,
            'När foreign key är null och withDefault används ska BelongsTo returnera en default-modell utan DB-anrop.'
        );
        $this->assertSame('bar', $result->getAttribute('foo'));
    }
}
