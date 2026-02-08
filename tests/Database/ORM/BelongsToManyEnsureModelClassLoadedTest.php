<?php

declare(strict_types=1);

namespace Radix\Tests\Database\ORM;

use Exception;
use PHPUnit\Framework\TestCase;
use Radix\Database\Connection;
use Radix\Database\ORM\Relationships\BelongsToMany;

final class BelongsToManyEnsureModelClassLoadedTest extends TestCase
{
    public function testConstructorThrowsNotFoundFromEnsureModelClassLoaded(): void
    {
        $connection = $this->createMock(Connection::class);

        $missing = 'Dummy\\Models\\__MissingBelongsToManyModel__';

        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Model class '{$missing}' not found.");

        new BelongsToMany(
            $connection,
            $missing,
            'pivot_table',
            'foreign_id',
            'related_id',
            'parent_id',
            null
        );
    }
}
