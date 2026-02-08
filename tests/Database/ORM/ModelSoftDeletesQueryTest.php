<?php

declare(strict_types=1);

namespace Radix\Tests\Database\ORM;

use PHPUnit\Framework\TestCase;
use Radix\Container\ApplicationContainer;
use Radix\Container\Container;
use Radix\Database\Connection;
use Radix\Database\DatabaseManager;
use Radix\Database\ORM\Model;

final class ModelSoftDeletesQueryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Sätt upp en minimal DB‑container så Model::getConnection() fungerar
        ApplicationContainer::reset();
        $container = new Container();

        $conn = $this->createMock(Connection::class);
        $dbManager = new class ($conn) {
            public function __construct(private Connection $conn) {}
            public function connection(): Connection
            {
                return $this->conn;
            }
        };

        $container->add(DatabaseManager::class, fn() => $dbManager);
        ApplicationContainer::set($container);
    }

    public function testQueryAddsDeletedAtNullFilterByDefault(): void
    {
        // Modellklass utan egen konstruktor (krav p.g.a. Model::query() → new $modelClass())
        $modelClass = new class extends Model {
            protected string $table = 'items';
            protected bool $softDeletes = true;
            /** @var array<int, string> */
            protected array $fillable = ['id'];
        };

        $className = $modelClass::class;

        // Default query: ska ha deleted_at IS NULL
        $sqlDefault = $className::query()->toSql();
        $this->assertStringContainsString(
            'deleted_at',
            $sqlDefault,
            'Default-query för soft-delete-modell ska filtrera bort soft-deletade rader.'
        );

        // Med withSoftDeletes(): ska INTE ha deleted_at IS NULL‑villkoret
        $sqlWithSoft = $className::query()->withSoftDeletes()->toSql();
        $this->assertStringNotContainsString(
            'deleted_at',
            $sqlWithSoft,
            'withSoftDeletes() ska slå av default-filtret på deleted_at.'
        );
    }
}
