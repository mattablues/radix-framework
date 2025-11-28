<?php

declare(strict_types=1);

namespace Radix\Console\Commands;

use Radix\Database\Migration\SeederRunner;

final class SeedCommand extends BaseCommand
{
    public function __construct(
        private readonly SeederRunner $runner
    ) {}

    /**
     * @param array<int, string> $args
     */
    public function execute(array $args): void
    {
        $this->__invoke($args);
    }

    /**
     * @param array<int, string> $args
     */
    public function __invoke(array $args): void
    {
        $command = $args['_command'] ?? null;

        switch ($command) {
            case 'seeds:run':
                $name = $args[0] ?? null;
                $this->run($name);
                break;

            case 'seeds:rollback':
                $name = $args[0] ?? null;
                $this->rollback($name);
                break;

            default:
                $this->showHelp();
        }
    }

    private function run(?string $partialName): void
    {
        $executed = $this->runner->run($partialName);

        if (empty($executed)) {
            $this->coloredOutput('No seeders executed.', 'yellow');
            return;
        }

        $this->coloredOutput("Executed seeders:", 'green');
        $this->coloredOutput(implode("\n", $executed), 'yellow');
    }

    private function rollback(?string $partialName): void
    {
        $rolled = $this->runner->rollback($partialName);

        if (empty($rolled)) {
            $this->coloredOutput('No seeders rolled back.', 'yellow');
            return;
        }

        $this->coloredOutput("Rolled back seeders:", 'green');
        $this->coloredOutput(implode("\n", $rolled), 'yellow');
    }

    private function showHelp(): void
    {
        $this->coloredOutput("Usage:", "green");
        $this->coloredOutput("  seeds:run [name?]                             Run seeders (partial name supported).", "yellow");
        $this->coloredOutput("  seeds:rollback [name?]                        Rollback seeders if down() is implemented.", "yellow");
        $this->coloredOutput("Options:", "green");
        $this->coloredOutput("  --help                                        Display this help message.", "yellow");
        echo PHP_EOL;
    }
}
