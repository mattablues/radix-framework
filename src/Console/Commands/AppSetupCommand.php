<?php

declare(strict_types=1);

namespace Radix\Console\Commands;

use Radix\Database\Migration\Migrator;
use Radix\Database\Migration\SeederRunner;

final class AppSetupCommand extends BaseCommand
{
    public function __construct(
        private readonly Migrator $migrator,
        private readonly SeederRunner $seederRunner,
        private readonly CacheClearCommand $cacheCommand
    ) {}

    /**
     * Kör kommandot med givna argument.
     *
     * @param array<int|string, string> $args
     */
    public function execute(array $args): void
    {
        $this->__invoke($args);
    }

    /**
     * Gör objektet anropbart som ett kommando.
     *
     * @param array<int|string, string> $args
     */
    public function __invoke(array $args): void
    {
        $usage = 'app:setup [--fresh]';
        $options = [
            '--fresh' => 'Wipe database before running migrations and seeds.',
            '--help, -h' => 'Display this help message.',
            '--md, --markdown' => 'Output help as Markdown.',
        ];
        $examples = [
            'app:setup',
            'app:setup --fresh',
        ];

        // Filtrera bort assoc-nycklar för att göra BaseCommand nöjd
        /** @var array<int, string> $argv */
        $argv = array_filter($args, static fn($k): bool => is_int($k), ARRAY_FILTER_USE_KEY);

        if ($this->handleHelpFlag($argv, $usage, $options, $examples)) {
            return;
        }

        $this->coloredOutput("--- Radix System Setup ---", "blue");

        // Kontrollera SESSION_DRIVER innan vi börjar
        $sessionDriver = getenv('SESSION_DRIVER') ?: 'file';
        if ($sessionDriver === 'database') {
            $this->coloredOutput("\n[VARNING] SESSION_DRIVER är satt till 'database'.", "yellow");
            $this->coloredOutput("Eftersom databastabellerna inte finns än (eller ska rensas), kan detta orsaka problem.", "yellow");
            $this->coloredOutput("Tips: Ändra till SESSION_DRIVER=file i din .env under installationen.\n", "yellow");

            // Vi fortsätter ändå, men användaren är nu varnad om varför det ev. kraschar senare
        }

        $isFresh = in_array('--fresh', $args, true);

        // 1. Rensa Cache
        $this->coloredOutput("\n1. Clearing system cache...", "yellow");
        $this->cacheCommand->execute([]);

        // 2. Hantera Databas (Fresh)
        if ($isFresh) {
            $this->coloredOutput("\n2. Resetting database (--fresh)...", "red");
            $this->migrator->rollback();
            $this->coloredOutput("   ✔ Database wiped.", "green");
        }

        // 3. Kör Migreringar
        $step = $isFresh ? 3 : 2;
        $this->coloredOutput("\n$step. Running migrations...", "yellow");
        $executedMigrations = $this->migrator->run();
        if ($executedMigrations > 0) {
            $this->coloredOutput("   ✔ $executedMigrations migrations executed.", "green");
        } else {
            $this->coloredOutput("   • No new migrations to run.", "yellow");
        }

        // 4. Kör Seeders
        $step++;
        $this->coloredOutput("\n$step. Seeding database...", "yellow");
        $executedSeeders = $this->seederRunner->run();
        if (!empty($executedSeeders)) {
            foreach ($executedSeeders as $seeder) {
                $this->coloredOutput("   ✔ Seeded: $seeder", "green");
            }
        } else {
            $this->coloredOutput("   • No seeders were executed.", "yellow");
        }

        $this->coloredOutput("\nSetup completed successfully! 🚀", "green");
    }
}
