<?php

declare(strict_types=1);

namespace Radix\Tests\Database\ORM;

use Exception;
use PHPUnit\Framework\TestCase;
use Radix\Database\Connection;
use Radix\Database\ORM\Relationships\HasMany;

final class HasManyEnsureModelClassLoadedTest extends TestCase
{
    public function testConstructorThrowsNotFoundFromEnsureModelClassLoaded(): void
    {
        $connection = $this->createMock(Connection::class);

        $missing = 'Dummy\\Models\\__MissingHasManyModel__';

        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Model class '{$missing}' not found.");

        new HasMany(
            $connection,
            $missing,
            'parent_id',
            'id'
        );
    }
}
