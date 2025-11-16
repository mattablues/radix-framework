<?php

declare(strict_types=1);

namespace Radix\Database;

use Exception;
use PDO;
use PDOStatement;

class Connection
{
    private ?PDO $pdo;

    /**
     * Acceptera en PDO-instans vid instansiering.
     */
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Intern hjälpare: returnera PDO eller kasta om anslutningen är stängd.
     */
    private function getPdoInternal(): PDO
    {
        if ($this->pdo === null) {
            throw new \RuntimeException('PDO instance is not initialized in Connection (connection may be disconnected).');
        }

        return $this->pdo;
    }

    /**
     * Kör ett statement och returnera PDOStatement.
     *
     * @param array<int|string,mixed> $params
     */
    public function execute(string $query, array $params = []): PDOStatement
    {
        $pdo = $this->getPdoInternal();
        $statement = $pdo->prepare($query);
        $statement->execute($params);

        return $statement; // Returnera statement istället för bool
    }

    /**
     * Hämta alla rader som assoc‑arrayer.
     *
     * @param array<int|string,mixed> $params
     * @return array<int,array<string,mixed>>
     */
    public function fetchAll(string $query, array $params = []): array
    {
        $pdo = $this->getPdoInternal();
        $statement = $pdo->prepare($query);
        $statement->execute($params);

        /** @var array<int,array<string,mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }

    /**
     * Hämta alla rader som objekt eller assoc‑arrayer.
     *
     * @param array<int|string,mixed> $params
     * @return array<int, array<string,mixed>|object>
     */
    public function fetchAllAsClass(string $query, array $params = [], ?string $className = null): array
    {
        $pdo = $this->getPdoInternal();
        $statement = $pdo->prepare($query);
        $statement->execute($params);

        if ($className) {
            /** @var array<int, object> $rows */
            $rows = $statement->fetchAll(PDO::FETCH_CLASS, $className);
        } else {
            /** @var array<int, array<string,mixed>> $rows */
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        }

        /** @var array<int, array<string,mixed>|object> $rows */
        return $rows;
    }

    /**
     * Hämta första raden som objekt (klass eller standard).
     *
     * @param array<int|string,mixed> $params
     */
    public function fetchOneAsClass(string $query, array $params = [], ?string $className = null): ?object
    {
        $pdo = $this->getPdoInternal();
        $statement = $pdo->prepare($query);
        $statement->execute($params);

        if ($className) {
            $statement->setFetchMode(PDO::FETCH_CLASS, $className);
            /** @var object|false $row */
            $row = $statement->fetch();
            return $row === false ? null : $row;
        }

        /** @var array<string,mixed>|false $row */
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        // Gör assoc‑arrayen till ett stdClass‑objekt för att hålla signaturen object|null
        return (object) $row;
    }

    /**
     * Hämta första raden som assoc‑array (eller null).
     *
     * @param array<int|string,mixed> $params
     * @return array<string,mixed>|null
     */
    public function fetchOne(string $query, array $params = []): ?array
    {
        $pdo = $this->getPdoInternal();
        $statement = $pdo->prepare($query);
        $statement->execute($params);

        /** @var array<string,mixed>|false $result */
        $result = $statement->fetch(PDO::FETCH_ASSOC);

        if ($result === false) {
            return null;
        }

        return $result;
    }

    /**
     * Kör ett statement och returnera antal påverkade rader.
     *
     * @param array<int|string,mixed> $params
     */
    public function fetchAffected(string $query, array $params = []): int
    {
        $pdo = $this->getPdoInternal();
        $statement = $pdo->prepare($query);
        $statement->execute($params);
        return $statement->rowCount();
    }

    public function lastInsertId(): string
    {
        $pdo = $this->getPdoInternal();
        $id = $pdo->lastInsertId();

        if ($id === false) {
            throw new \RuntimeException('No last insert id available for this connection.');
        }

        return $id;
    }

    /**
     * Starta en transaktion.
     */
    public function beginTransaction(): void
    {
        $this->getPdoInternal()->beginTransaction();
    }

    /**
     * Commit en transaktion.
     */
    public function commitTransaction(): void
    {
        $this->getPdoInternal()->commit();
    }

    /**
     * Rulla tillbaka en transaktion.
     */
    public function rollbackTransaction(): void
    {
        $this->getPdoInternal()->rollBack();
    }

    /**
     * Kontrollera om anslutningen är aktiv.
     *
     * @return bool
     */
    public function isConnected(): bool
    {
        try {
            $this->getPdoInternal()->getAttribute(PDO::ATTR_CONNECTION_STATUS);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Koppla från databasen.
     */
    public function disconnect(): void
    {
        $this->pdo = null;
    }

    /**
     * Hämta den underliggande PDO-instansen.
     */
    public function getPDO(): \PDO
    {
        return $this->getPdoInternal();
    }
}