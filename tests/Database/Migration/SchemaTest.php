<?php

declare(strict_types=1);

namespace Radix\Tests\Database\Migration;

use PDO;
use PHPUnit\Framework\TestCase;
use Radix\Database\Connection;
use Radix\Database\Migration\Schema;
use RuntimeException;

final class SchemaTest extends TestCase
{
    private Connection $connection;
    private Schema $schema;

    protected function setUp(): void
    {
        parent::setUp();

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->connection = new Connection($pdo);
        $this->schema = new Schema($this->connection);
    }

    public function testGetDriverNameReturnsSqliteForSqliteConnection(): void
    {
        $this->assertSame('sqlite', $this->schema->getDriverName());
    }

    public function testStatementExecutesRawSql(): void
    {
        $this->schema->statement(
            'CREATE TABLE horses (id INTEGER PRIMARY KEY, name TEXT NOT NULL)'
        );

        $this->schema->statement(
            'INSERT INTO horses (name) VALUES (?)',
            ['Nova']
        );

        $rows = $this->connection->fetchAll('SELECT name FROM horses');

        $this->assertCount(1, $rows);
        $this->assertSame('Nova', $rows[0]['name']);
    }

    public function testGetDriverNameFallsBackToMysqlWhenGettingPdoFails(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getPDO')
            ->willThrowException(new RuntimeException('PDO unavailable'));

        $schema = new Schema($connection);

        $this->assertSame('mysql', $schema->getDriverName());
    }

    public function testGetDriverNameNormalizesDriverNameToLowercase(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('getAttribute')
            ->willReturnCallback(static function (int $attr): mixed {
                if ($attr === PDO::ATTR_DRIVER_NAME) {
                    return 'SQLite';
                }

                return null;
            });

        $connection = $this->createMock(Connection::class);
        $connection->method('getPDO')->willReturn($pdo);

        $schema = new Schema($connection);

        $this->assertSame('sqlite', $schema->getDriverName());
    }

    public function testGetDriverNameFallsBackToMysqlWhenPdoReturnsEmptyString(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('getAttribute')
            ->willReturnCallback(static function (int $attr): mixed {
                if ($attr === PDO::ATTR_DRIVER_NAME) {
                    return '';
                }

                return null;
            });

        $connection = $this->createMock(Connection::class);
        $connection->method('getPDO')->willReturn($pdo);

        $schema = new Schema($connection);

        $this->assertSame('mysql', $schema->getDriverName());
    }
}
