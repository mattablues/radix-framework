<?php

declare(strict_types=1);

namespace Radix\Console\Commands;

use Radix\Database\Migration\Migrator;

class MigrationCommand extends BaseCommand
{
    private Migrator $migrator;

    public function __construct(Migrator $migrator)
    {
        $this->migrator = $migrator;
    }

    /**
     * Kör kommandot med givna argument.
     *
     * @param array<int, string> $args
     */
    public function execute(array $args): void
    {
        $this->__invoke($args); // Anropa __invoke-logiken
    }

    /**
     * Gör objektet anropbart som ett kommando.
     *
     * @param array<int, string> $args
     */
    public function __invoke(array $args): void
    {
        // Hämta vilket kommando som körs
        $command = $args['_command'] ?? null;

        switch ($command) {
            case 'migrations:migrate':
                $this->runMigrate(); // Kör migreringar
                break;

            case 'migrations:rollback':
                $migrationName = $args[0] ?? null; // Använd första argumentet för att matcha migration
                $this->runRollback($migrationName); // Rollback-handling
                break;

            default:
                $this->showHelp(); // Visa hjälp vid okänt kommando
                break;
        }
    }

    private function runMigrate(): void
    {
        $executedCount = $this->migrator->run();

        if ($executedCount === 0) {
            $this->coloredOutput("No new migrations to execute.", "yellow");
        } else {
            $this->coloredOutput("Migrations executed successfully. Total: $executedCount.", "green");
        }
    }

    private function runRollback(?string $migrationName): void
    {
        $rolledBackMigrations = $this->migrator->rollback($migrationName);

        if (empty($rolledBackMigrations)) {
            $this->coloredOutput("No migrations to rollback.", "yellow");
            return;
        }

        // Skriv ut alla rollback-meddelanden på separata rader utan extra mellanrum
        $this->coloredOutput(implode("\n", $rolledBackMigrations), "yellow");
    }

    private function showHelp(): void
    {
        $this->coloredOutput("Usage:", "green");
        $this->coloredOutput("  migrations:migrate                            Run new migrations.", "yellow");
        $this->coloredOutput("  migrations:rollback                           Rollback migrations.", "yellow");
        $this->coloredOutput("Options:", "green");
        $this->coloredOutput("  [migration_name]                              Rollback migrations (partial name is supported).", "yellow");
        $this->coloredOutput("  --help                                        Display this help message.", "yellow");
        echo PHP_EOL;
    }
}
