<?php

declare(strict_types=1);

namespace Radix\Database\Migration;

use Radix\Database\Connection;

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
        $sql = "DROP TABLE IF EXISTS `$table`";
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

        $sqlStatements = $blueprint->toAlterSql();
        foreach ($sqlStatements as $sql) {
            $this->connection->execute($sql);
        }
    }
}
