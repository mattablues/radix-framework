<?php

declare(strict_types=1);

namespace Radix\Tests\Database\ORM;

use PHPUnit\Framework\TestCase;
use Radix\Database\Connection;
use Radix\Database\ORM\ConventionModelClassResolver;
use Radix\Database\ORM\Model;
use Radix\Database\ORM\Relationships\BelongsTo;

final class BelongsToUcfirstConventionTest extends TestCase
{
    public function testBelongsToTableConventionUsesUcfirstSingularizedModelName(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn(null);

        $parent = new class extends Model {
            protected string $table = 'parents';
            /** @var array<int, string> */
            protected array $fillable = ['foo_id'];
        };
        $parent->forceFill(['foo_id' => 123]);

        $resolver = new ConventionModelClassResolver('Dummy\\Models\\');

        $rel = new BelongsTo(
            $connection,
            'foos',
            'foo_id',
            'id',
            $parent,
            $resolver
        );

        $this->assertNull($rel->get());
    }

    public function testBelongsToFallbackConventionUsesUcfirstSingularizedModelName(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn(null);

        $parent = new class extends Model {
            protected string $table = 'parents';
            /** @var array<int, string> */
            protected array $fillable = ['foo_id'];
        };
        $parent->forceFill(['foo_id' => 1]);

        $resolver = new ConventionModelClassResolver('Dummy\\Models\\');

        $rel = new BelongsTo(
            $connection,
            'foos',
            'foo_id',
            'id',
            $parent,
            $resolver
        );

        $this->assertNull($rel->get());
    }
}
