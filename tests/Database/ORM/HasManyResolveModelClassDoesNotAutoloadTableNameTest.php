<?php

declare(strict_types=1);

namespace Radix\Tests\Database\ORM;

use PHPUnit\Framework\TestCase;
use Radix\Database\Connection;
use Radix\Database\ORM\Model;
use Radix\Database\ORM\ModelClassResolverInterface;
use Radix\Database\ORM\Relationships\HasMany;
use RuntimeException;

final class HasManyResolveModelClassDoesNotAutoloadTableNameTest extends TestCase
{
    public function testResolveModelClassDoesNotAutoloadRawTableName(): void
    {
        $connection = $this->createMock(Connection::class);

        // Resolver som mappar tabellnamn -> en redan laddad modellklass
        $resolver = new class implements ModelClassResolverInterface {
            public function resolve(string $classOrTable): string
            {
                return DummyUserModel::class;
            }
        };

        $autoloadCalls = 0;
        $cb = static function (string $class) use (&$autoloadCalls): void {
            $autoloadCalls++;

            // Om mutanten kör class_exists('users', true) så hamnar vi här med "users"
            if ($class === 'users') {
                throw new RuntimeException("Autoloader should not be called for table name '{$class}'.");
            }
        };

        spl_autoload_register($cb);
        try {
            new HasMany($connection, 'users', 'user_id', 'id', $resolver);

            // Om allt är korrekt ska autoloadern inte behöva kallas alls här.
            self::assertSame(0, $autoloadCalls);
        } finally {
            spl_autoload_unregister($cb);
        }
    }
}

final class DummyUserModel extends Model
{
    protected string $table = 'users';
}
