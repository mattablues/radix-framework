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
     * Kör en SQL-fråga via PDO.
     *
     * @param  string  $query  SQL-frågan som ska köras.
     * @param  array  $params  Parametrar som ska bindas.
     * @return PDOStatement Returnerar true om operationen lyckades, false annars.
     */
    public function execute(string $query, array $params = []): PDOStatement
    {
        $statement = $this->pdo->prepare($query);
        $statement->execute($params);

        return $statement; // Returnera statement istället för bool
    }
    /**
     * Hämta alla rader från en fråga.
     *
     * @param string $query
     * @param array $params
     * @return array
     */
    public function fetchAll(string $query, array $params = []): array
    {
        $statement = $this->pdo->prepare($query);
        $statement->execute($params);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Hämta alla rader från en fråga som en viss klass.
     *
     * @param string $query SQL-frågan som ska köras.
     * @param array $params Parametrar som ska bindas.
     * @param string|null $className Namnet på klassen som raderna ska mappas till.
     * @return array|object[]
     */
    public function fetchAllAsClass(string $query, array $params = [], ?string $className = null): array
    {
        $statement = $this->pdo->prepare($query);
        $statement->execute($params);

        if ($className) {
            return $statement->fetchAll(PDO::FETCH_CLASS, $className);
        }

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Hämta en enskild rad från en fråga som en viss klass.
     */
    public function fetchOneAsClass(string $query, array $params = [], ?string $className = null): ?object
    {
        $statement = $this->pdo->prepare($query);
        $statement->execute($params);

        if ($className) {
            $statement->setFetchMode(PDO::FETCH_CLASS, $className);
            return $statement->fetch() ?: null;
        }

        return $statement->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Hämta en enskild rad från en fråga.
     *
     * @param string $query SQL-frågan som ska köras.
     * @param array $params Parametrar som ska bindas.
     * @return array|null Returnerar raden som en array eller null om ingen rad hittades.
     */
    public function fetchOne(string $query, array $params = []): ?array
    {
        $statement = $this->pdo->prepare($query);
        $statement->execute($params);
        $result = $statement->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Hämta antalet påverkade rader från den senaste operationen.
     *
     * @param string $query SQL-frågan som ska köras.
     * @param array $params Parametrar som ska bindas.
     * @return int Antalet påverkade rader.
     */
    public function fetchAffected(string $query, array $params = []): int
    {
        $statement = $this->pdo->prepare($query);
        $statement->execute($params);
        return $statement->rowCount();
    }

    public function lastInsertId(): string
    {
        return $this->pdo->lastInsertId();
    }

    /**
     * Starta en transaktion.
     */
    public function beginTransaction(): void
    {
        $this->pdo->beginTransaction();
    }

    /**
     * Commit en transaktion.
     */
    public function commitTransaction(): void
    {
        $this->pdo->commit();
    }

    /**
     * Rulla tillbaka en transaktion.
     */
    public function rollbackTransaction(): void
    {
        $this->pdo->rollBack();
    }

    /**
     * Kontrollera om anslutningen är aktiv.
     *
     * @return bool
     */
    public function isConnected(): bool
    {
        try {
            $this->pdo->getAttribute(PDO::ATTR_CONNECTION_STATUS);
            return true;
        } catch (Exception $e) {
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
    public function getPDO(): PDO
    {
        return $this->pdo;
    }
}