<?php

declare(strict_types=1);

namespace Radix\Database\QueryBuilder\Concerns;

use LogicException;
use Throwable;

trait Transactions
{
    public function transaction(callable $callback): void
    {
        try {
            $this->startTransaction();
            $callback($this);
            $this->commitTransaction();
        } catch (Throwable $e) {
            $this->rollbackTransaction();
            throw $e;
        }
    }

    protected function startTransaction(): void
    {
        if ($this->connection === null) {
            throw new LogicException('Ingen databasanslutning är inställd. Använd setConnection() innan du startar en transaktion.');
        }
        $this->connection->beginTransaction();
    }

    protected function commitTransaction(): void
    {
        if ($this->connection === null) {
            throw new LogicException('Ingen databasanslutning är inställd. Använd setConnection() innan du gör commit.');
        }
        $this->connection->commitTransaction();
    }

    protected function rollbackTransaction(): void
    {
        if ($this->connection === null) {
            throw new LogicException('Ingen databasanslutning är inställd. Använd setConnection() innan du gör rollback.');
        }
        $this->connection->rollbackTransaction();
    }
}
