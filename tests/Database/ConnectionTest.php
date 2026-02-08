<?php

declare(strict_types=1);

namespace Radix\Tests\Database;

use PDO;
use PHPUnit\Framework\TestCase;
use Radix\Database\Connection;

class ConnectionTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        // Skapa en PDO-instans för SQLite i minnet
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Använd den uppdaterade Connection-klassen
        $this->connection = new Connection($pdo);
    }

    public function testConnectionCanExecuteQueries(): void
    {
        // Skapa tabell
        $this->connection->execute('CREATE TABLE test_table (id INTEGER PRIMARY KEY, name TEXT)');

        // Infoga data
        $this->connection->execute(
            'INSERT INTO test_table (name) VALUES (:name)',
            ['name' => 'Test Name']
        );

        // Hämta data
        /** @var array<int, array<string, mixed>> $result */
        $result = $this->connection
            ->execute(
                'SELECT * FROM test_table WHERE name = :name',
                ['name' => 'Test Name']
            )
            ->fetchAll();

        $this->assertCount(1, $result, 'One row should be retrieved');
        $this->assertEquals(
            'Test Name',
            $result[0]['name'],
            'The retrieved name should match the inserted value'
        );
    }


    public function testTransactionRollback(): void
    {
        $this->connection->execute('CREATE TABLE test_table (id INTEGER PRIMARY KEY, name TEXT)');

        $this->connection->beginTransaction();
        $this->connection->execute('INSERT INTO test_table (name) VALUES (:name)', ['name' => 'Should Rollback']);
        $this->connection->rollbackTransaction();

        $result = $this->connection->execute('SELECT * FROM test_table WHERE name = :name', ['name' => 'Should Rollback'])
            ->fetchAll();

        $this->assertCount(0, $result, 'Rollbacked transaction should not persist the inserted row');
    }
}
