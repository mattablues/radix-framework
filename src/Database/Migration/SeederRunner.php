<?php

declare(strict_types=1);

namespace Radix\Database\Migration;

use Radix\Database\Connection;
use PDO;
use RuntimeException;

final class SeederRunner
{
    private PDO $pdo;

    public function __construct(
        private readonly string $seedersPath,
        Connection $connection
    ) {
        $this->pdo = $connection->getPDO();
    }

    /**
     * @return list<string>
     */
    public function run(?string $partialName = null): array
    {
        $seedFiles = $this->discoverSeedFiles();

        // Autoladda (klassdefinitioner), men styr körning via filtrering
        foreach ($seedFiles as $f) {
            require_once $f;
        }

        // Partial: kör ENDAST de filer som matchar, EXKLUDERA DatabaseSeeder
        if ($partialName !== null) {
            $seedFiles = array_filter(
                $seedFiles,
                function (string $file) use ($partialName): bool {
                    $base = basename($file);
                    if (stripos($base, 'database_seeder.php') !== false) {
                        return false; // exkludera globala
                    }
                    return stripos($base, $partialName) !== false;
                }
            );

            sort($seedFiles);

            $executed = [];
            foreach ($seedFiles as $file) {
                $class = $this->requireSeederClass($file);
                $seeder = new $class($this->pdo);
                if (!method_exists($seeder, 'run')) {
                    throw new RuntimeException("Seeder $class saknar metoden run().");
                }
                $seeder->run();
                $executed[] = $class;
            }
            return $executed; // viktigt
        }

        // Ingen partial: kör DatabaseSeeder om den finns
        $dbSeederFile = $this->seedersPath . '/00000000000000_database_seeder.php';
        if (is_file($dbSeederFile)) {
            $class = $this->requireSeederClass($dbSeederFile);
            $seeder = new $class($this->pdo);
            if (!method_exists($seeder, 'run')) {
                throw new RuntimeException("Seeder $class saknar metoden run().");
            }
            $seeder->run();
            return [$class];
        }

        // Fallback: kör alla i ordning
        sort($seedFiles);

        $executed = [];
        for ($i = 0, $n = count($seedFiles); $i < $n; $i++) {
            $file = $seedFiles[$i];
            $class = $this->requireSeederClass($file);
            $seeder = new $class($this->pdo);
            if (!method_exists($seeder, 'run')) {
                throw new RuntimeException("Seeder $class saknar metoden run().");
            }
            $seeder->run();
            $executed[] = $class;
        }

        return $executed;
    }

    /**
     * @return list<string>
     */
    public function rollback(?string $partialName = null): array
    {
        $seedFiles = $this->discoverSeedFiles();

        if ($partialName !== null) {
            $seedFiles = array_filter(
                $seedFiles,
                fn (string $file) => stripos(basename($file), $partialName) !== false
            );
        }

        // Exkludera DatabaseSeeder i rollback av partials
        $seedFiles = array_filter(
            $seedFiles,
            fn (string $file) => stripos(basename($file), 'database_seeder.php') === false
        );

        // Kör barn först: sortera alfabetiskt och kör i omvänd ordning
        usort($seedFiles, fn(string $a, string $b) => strcmp(basename($a), basename($b)));
        $seedFiles = array_reverse($seedFiles);

        $rolledBack = [];
        foreach ($seedFiles as $file) {
            $class = $this->requireSeederClass($file);
            $seeder = new $class($this->pdo);
            if (method_exists($seeder, 'down')) {
                $seeder->down();
                $rolledBack[] = $class;
            }
        }

        return $rolledBack;
    }

    /**
     * @return list<string>
     */
    private function discoverSeedFiles(): array
    {
        if (!is_dir($this->seedersPath)) {
            return [];
        }

        $files = glob($this->seedersPath . '/*.php') ?: [];
        return $files;
    }

    private function requireSeederClass(string $file): string
    {
        require_once $file;

        $base = basename($file, '.php');
        $name = preg_replace('/^\d+_/', '', $base) ?? $base;
        $name = preg_replace('/_seeder$/i', '', $name) ?? $name;

        $parts = preg_split('/[_\-]/', $name) ?: [$name];
        $classBase = implode('', array_map(fn($p) => ucfirst(strtolower($p)), $parts));
        $class = $classBase . 'Seeder';

        if (!class_exists($class)) {
            throw new RuntimeException("Klass $class kunde inte hittas i $file.");
        }

        return $class;
    }
}