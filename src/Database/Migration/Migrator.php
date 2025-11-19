<?php

declare(strict_types=1);

namespace Radix\Database\Migration;

use Radix\Database\Connection;

class Migrator
{
    private Connection $connection;
    private string $migrationsPath;

    public function __construct(Connection $connection, string $migrationsPath)
    {
        $this->connection = $connection;
        $this->migrationsPath = $migrationsPath;
        $this->ensureMigrationsTable();
    }

    /**
     * Kontrollera och skapa migrations-tabellen om den inte finns.
     */
    private function ensureMigrationsTable(): void
    {
        $this->connection->execute("CREATE TABLE IF NOT EXISTS `migrations` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `migration` VARCHAR(255) NOT NULL,
            `run_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
    }

    /**
     * Kör alla nya migreringar.
     */
    public function run(): int
    {
        $migrations = glob($this->migrationsPath . "/*.php") ?: [];
        /** @var array<int, string> $migrations */

        $executedMigrations = $this->getExecutedMigrations();

        $executedCount = 0;

        foreach ($migrations as $migrationFile) {
            $className = pathinfo($migrationFile, PATHINFO_FILENAME);

            if (!in_array($className, $executedMigrations, true)) {
                /** @var object $migration */
                $migration = require_once $migrationFile;
                $schema = new Schema($this->connection);

                // Kör migrationen
                if (method_exists($migration, 'up')) {
                    $migration->up($schema);
                } elseif (class_exists($className)) {
                    /** @var object $migrationInstance */
                    $migrationInstance = new $className();
                    if (method_exists($migrationInstance, 'up')) {
                        $migrationInstance->up($schema);
                    }
                }

                $this->markAsExecuted($className);
                $executedCount++;
            }
        }

        return $executedCount; // Returnera antal körda migrationer
    }

    /**
     * Hämta alla migreringar som redan har körts.
     *
     * @return array<int,string>
     */
    private function getExecutedMigrations(): array
    {
        /** @var array<int, array<string, mixed>> $results */
        $results = $this->connection->fetchAll("SELECT `migration` FROM `migrations`");

        $migrations = [];
        foreach ($results as $row) {
            $name = $row['migration'] ?? null;
            if (is_string($name)) {
                $migrations[] = $name;
            }
        }

        return $migrations;
    }

    /**
     * Märk en migration som körd.
     */
    private function markAsExecuted(string $migrationName): void
    {
        $this->connection->execute("INSERT INTO `migrations` (`migration`) VALUES (?)", [$migrationName]);
    }

    /**
     * Rulla tillbaka den senaste körda migreringen.
     *
     * @return array<int,string>
     */
    public function rollback(?string $partialName = null): array
    {
        $rolledBackMigrations = [];

        if ($partialName) {
            // Hämta matchande migrationer för $partialName
            $matchedMigrations = $this->getMatchingMigrations($partialName);

            if (empty($matchedMigrations)) {
                // Om inga matchningar hittas
                return ["No migrations found matching '$partialName'."];
            }

            // Om flera matchningar hittas
            if (count($matchedMigrations) > 1) {
                // Sortera migrationer efter namn (tidsstämpeln ordnas i fallande ordning)
                usort($matchedMigrations, static function (string $a, string $b): int {
                    return strcmp($b, $a); // Sortera i fallande ordning baserat på filnamnet
                });

                // Hämta den senaste migrationen
                $latestMigration = $matchedMigrations[0] ?? null;

                if ($latestMigration === null) {
                    return ["Error: Could not determine the latest migration to rollback."];
                }

                // Returnera information och köra rollback för den senaste
                return [
                    "Multiple migrations match '$partialName'. Rolling back the latest migration:",
                    $this->rollbackMigration($latestMigration),
                ];
            }

            // Om en enda matchning hittas
            $singleMatch = $matchedMigrations[0] ?? null;

            if ($singleMatch !== null) {
                $rolledBackMigrations[] = $this->rollbackMigration($singleMatch);
            } else {
                return ["Error: Single match not found even though matches exist."];
            }
        } else {
            // Om ingen $partialName anges, rulla tillbaka de senaste migrationerna
            /** @var array<int, array<string, mixed>> $migrations */
            $migrations = $this->connection->fetchAll(
                "SELECT `migration` FROM `migrations` ORDER BY `migration` DESC"
            );

            foreach ($migrations as $row) {
                $name = $row['migration'] ?? null;
                if (!is_string($name)) {
                    continue;
                }

                $rolledBackMigrations[] = $this->rollbackMigration($name);
            }
        }

        return $rolledBackMigrations ?: ["No migrations to rollback."];
    }

    /**
     * Hämta migrationer som matchar del av namnet.
     *
     * @return array<int,string>
     */
    private function getMatchingMigrations(string $partialName): array
    {
        /** @var array<int, array<string, mixed>> $rows */
        $rows = $this->connection->fetchAll(
            "SELECT `migration` FROM `migrations`"
        );

        $matches = [];
        foreach ($rows as $row) {
            $name = $row['migration'] ?? null;
            if (!is_string($name)) {
                continue;
            }

            // stripos kräver string, och $partialName är redan string
            if (stripos($name, $partialName) !== false) {
                $matches[] = $name;
            }
        }

        // $matches är redan list<string>
        return $matches;
    }

    private function rollbackMigration(string $migrationName): string
    {
        $migrationFile = $this->migrationsPath . "/$migrationName.php";

        if (file_exists($migrationFile)) {
            /** @var object $migration */
            $migration = require_once $migrationFile;

            $schema = new Schema($this->connection);

            if (method_exists($migration, 'down')) {
                $migration->down($schema);
            } elseif (class_exists($migrationName)) {
                /** @var object $migrationInstance */
                $migrationInstance = new $migrationName();
                if (method_exists($migrationInstance, 'down')) {
                    $migrationInstance->down($schema);
                }
            }

            $this->connection->execute(
                "DELETE FROM `migrations` WHERE `migration` = ?",
                [$migrationName]
            );

            return "Rolled back migration: $migrationName";
        }

        return "Migration file for $migrationName not found.";
    }
}
