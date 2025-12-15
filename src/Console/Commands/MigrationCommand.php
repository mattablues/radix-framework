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

        $usage = ($command === 'migrations:rollback')
            ? 'migrations:rollback [migration_name?]'
            : 'migrations:migrate';

        $options = [
            '--help, -h' => 'Display this help message.',
            '--md, --markdown' => 'Output help as Markdown.',
        ];

        $examples = [];
        if ($command === 'migrations:rollback') {
            $options = [
                '[migration_name?]' => 'Optional partial migration name filter.',
                '--help, -h' => 'Display this help message.',
                '--md, --markdown' => 'Output help as Markdown.',
            ];
            $examples = [
                'migrations:rollback',
                'migrations:rollback create_users',
                'migrations:rollback users',
            ];
        } else {
            $examples = [
                'migrations:migrate',
            ];
        }

        if ($this->handleHelpFlag($args, $usage, $options, $examples)) {
            return;
        }

        switch ($command) {
            case 'migrations:migrate':
                $this->runMigrate(); // Kör migreringar
                break;

            case 'migrations:rollback':
                $migrationName = $args[0] ?? null; // Använd första argumentet för att matcha migration
                $this->runRollback($migrationName); // Rollback-handling
                break;

            default:
                // Om någon kör "migration" utan subcommand: visa en generell help
                $this->displayHelp(
                    'migrations:migrate | migrations:rollback [migration_name?]',
                    [
                        'migrations:migrate' => 'Run new migrations.',
                        'migrations:rollback [migration_name?]' => 'Rollback migrations (partial name supported).',
                        '--help, -h' => 'Display this help message.',
                        '--md, --markdown' => 'Output help as Markdown.',
                    ],
                    in_array('--md', $args, true) || in_array('--markdown', $args, true),
                    [
                        'migrations:migrate',
                        'migrations:rollback',
                        'migrations:rollback users',
                    ]
                );
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
}
