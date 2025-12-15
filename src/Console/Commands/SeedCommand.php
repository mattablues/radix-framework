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

        $usage = ($command === 'seeds:rollback')
            ? 'seeds:rollback [name?]'
            : 'seeds:run [name?]';

        $options = [
            '[name?]' => 'Optional partial seeder name filter.',
            '--help, -h' => 'Display this help message.',
            '--md, --markdown' => 'Output help as Markdown.',
        ];

        $examples = [];
        if ($command === 'seeds:rollback') {
            $examples = [
                'seeds:rollback',
                'seeds:rollback users',
                'seeds:rollback UserSeeder',
            ];
        } else {
            $examples = [
                'seeds:run',
                'seeds:run users',
                'seeds:run UserSeeder',
            ];
        }

        if ($this->handleHelpFlag($args, $usage, $options, $examples)) {
            return;
        }

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
                // Om någon kör "seed" utan subcommand: visa en generell help
                $this->displayHelp(
                    'seeds:run [name?] | seeds:rollback [name?]',
                    [
                        'seeds:run [name?]' => 'Run seeders (partial name supported).',
                        'seeds:rollback [name?]' => 'Rollback seeders if down() is implemented.',
                        '--help, -h' => 'Display this help message.',
                        '--md, --markdown' => 'Output help as Markdown.',
                    ],
                    in_array('--md', $args, true) || in_array('--markdown', $args, true),
                    [
                        'seeds:run',
                        'seeds:run users',
                        'seeds:rollback',
                        'seeds:rollback users',
                    ]
                );
                break;
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
}
