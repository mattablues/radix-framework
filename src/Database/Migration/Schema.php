<?php

declare(strict_types=1);

namespace Radix\Database\Migration;

use PDO;
use Radix\Database\Connection;
use Throwable;

class Schema
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public function create(string $table, callable $callback): void
    {
        $blueprint = new Blueprint($table);
        $callback($blueprint);

        $this->connection->execute($blueprint->toSql());
    }

    public function drop(string $table): void
    {
        $sql = "DROP TABLE IF EXISTS `$table`;";
        $this->connection->execute($sql);
    }

    public function dropIfExists(string $table): void
    {
        $this->drop($table);
    }

    public function alter(string $table, callable $callback): void
    {
        $blueprint = new Blueprint($table, true);
        $callback($blueprint);

        $driver = $this->driverName();

        $sqlStatements = $blueprint->toAlterSql($driver);
        foreach ($sqlStatements as $sql) {
            $this->connection->execute($sql);
        }
    }

    private function driverName(): string
    {
        try {
            $name = $this->connection->getPDO()->getAttribute(PDO::ATTR_DRIVER_NAME);
            return is_string($name) ? strtolower($name) : 'mysql';
        } catch (Throwable) {
            return 'mysql';
        }
    }
}
