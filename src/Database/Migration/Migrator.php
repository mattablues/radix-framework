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
        $migrations = glob($this->migrationsPath . "/*.php");
        $executedMigrations = $this->getExecutedMigrations();

        $executedCount = 0;

        foreach ($migrations as $migrationFile) {
            $className = pathinfo($migrationFile, PATHINFO_FILENAME);

            if (!in_array($className, $executedMigrations, true)) {
                $migration = require_once $migrationFile;
                $schema = new Schema($this->connection);

                // Kör migrationen
                if (is_object($migration)) {
                    $migration->up($schema);
                } elseif (class_exists($className)) {
                    $migrationInstance = new $className();
                    $migrationInstance->up($schema);
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
        $results = $this->connection->fetchAll("SELECT `migration` FROM `migrations`");
        return array_column($results, 'migration');
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
                usort($matchedMigrations, function ($a, $b) {
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
                    $this->rollbackMigration($latestMigration)
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
            $migrations = $this->connection->fetchAll(
                "SELECT `migration` FROM `migrations` ORDER BY `migration` DESC"
            );

            foreach ($migrations as $migration) {
                // Säkerställa att 'migration' existerar innan vi försöker använda det
                if (!isset($migration['migration'])) {
                    continue;
                }

                $rolledBackMigrations[] = $this->rollbackMigration($migration['migration']);
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
        $migrations = $this->connection->fetchAll(
            "SELECT `migration` FROM `migrations`"
        );

        $filteredMigrations = array_filter(
            array_column($migrations, 'migration'),
            fn($migration) => stripos($migration, $partialName) !== false
        );

        // Om indexera arrayen för att säkerställa att nycklarna är numeriska
        return array_values($filteredMigrations);
    }

    private function rollbackMigration(string $migrationName): string
    {
        $migrationFile = $this->migrationsPath . "/$migrationName.php";

        if (file_exists($migrationFile)) {
            $migration = require_once $migrationFile;

            // Skapa en Schema-instans och skicka den till migrationen
            $schema = new Schema($this->connection);

            if (is_object($migration)) {
                $migration->down($schema); // Skicka Schema som argument
            } elseif (class_exists($migrationName)) {
                $migrationInstance = new $migrationName();
                $migrationInstance->down($schema); // Skicka Schema som argument
            }

            $this->connection->execute(
                "DELETE FROM `migrations` WHERE `migration` = ?",
                [$migrationName]
            );

            // Returnera ett meddelande istället för att skriva ut
            return "Rolled back migration: $migrationName";
        }

        // Returnera ett felmeddelande om filen inte hittas
        return "Migration file for $migrationName not found.";
    }
}