<?php

declare(strict_types=1);

namespace Radix\Console;

use Exception;

class ConsoleApplication
{
    /**
     * @var array<string, callable>
     */
    private array $commands = [];

    public function addCommand(string $name, callable $callable): void
    {
        $this->commands[$name] = $callable;
    }

    /**
     * @param list<string> $argv
     */
    public function run(array $argv): void
    {
        // Normalisera index + tala om för PHPStan att detta är en lista av strängar
        $argv = array_values($argv);
        /** @var list<string> $argv */

        $asMarkdown = in_array('--md', $argv, true) || in_array('--markdown', $argv, true);

        // Hitta första "riktiga" token som inte är en flagga (börjar med "-")
        $command = null;
        $commandIndex = null;

        $argc = count($argv);
        for ($i = 1; $i < $argc; $i++) {
            $token = $argv[$i];

            if ($token === '') {
                continue;
            }

            // Extra defensivt (PHPStan + framtidssäkert)
            if (!is_string($token)) {
                continue;
            }

            if (str_starts_with($token, '-')) {
                continue;
            }

            $command = $token;
            $commandIndex = $i;
            break;
        }

        // Ingen command => visa global help (ev. i Markdown)
        if ($command === null || $commandIndex === null) {
            $this->displayHelp($asMarkdown);
            return;
        }

        // Här är $command garanterat string
        if (array_key_exists($command, $this->commands)) {
            // Argument efter kommandot (flags som --md får ligga kvar; BaseCommand kan ignorera dem)
            $args = array_slice($argv, $commandIndex + 1);
            /** @var list<string> $args */

            if (in_array('--help', $args, true) || in_array('-h', $args, true)) {
                $helpArgs = ['--help'];
                if ($asMarkdown) {
                    $helpArgs[] = '--md';
                }

                call_user_func($this->commands[$command], $helpArgs);
                return;
            }

            try {
                call_user_func($this->commands[$command], $args);
            } catch (Exception $e) {
                if ($asMarkdown) {
                    echo "## Error\n\n";
                    echo "```text\n" . $e->getMessage() . "\n```\n";
                } else {
                    echo "Error: " . $e->getMessage() . "\n";
                }
            }

            return;
        }

        // Okänt kommando
        if ($asMarkdown) {
            echo "# Radix CLI\n\n";
            echo "## Okänt kommando\n\n";
            echo "```text\nUnknown command: '{$command}'.\n```\n\n";
            $this->displayHelp(true);
            return;
        }

        echo "Unknown command: '$command'.\n\n";
        $this->displayHelp(false);
    }

    private function displayHelp(bool $asMarkdown = false): void
    {
        if ($asMarkdown) {
            echo "# Radix CLI\n\n";
            echo "## Usage\n\n";
            echo "```bash\nphp radix <command> [arguments]\n```\n\n";

            echo "## Tillgängliga kommandon\n";
            foreach (array_keys($this->commands) as $name) {
                echo "- `{$name}`\n";
            }
            echo "\n";

            echo "## Examples\n\n";
            echo "```bash\n";
            echo "php radix make:view --help\n";
            echo "php radix make:view --help --md\n";
            echo "php radix migrations:rollback --help --md\n";
            echo "php radix --md | Out-File -FilePath docs/CLI.md -Encoding utf8\n";
            echo "```\n\n";

            echo "## Tips\n\n";
            echo "- Kör `php radix <command> --help` för kommandospecifik hjälp.\n";
            echo "- Kör `php radix --md` för att få denna hjälp som Markdown.\n\n";
            return;
        }

        echo PHP_EOL;
        echo "Usage: php radix [command] [arguments]\n";
        echo "Available commands:\n";

        foreach ($this->commands as $name => $command) {
            echo "  - $name\n";
        }

        echo "\nTip: Use '[command] --help' for more information about a specific command.\n\n";
    }
}
