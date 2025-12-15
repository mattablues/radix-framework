<?php

declare(strict_types=1);

namespace Radix\Console;

use Exception;

class ConsoleApplication
{
    /**
     * Max antal argv-tokens (efter script-namnet) vi skannar efter ett kommando.
     * Detta är en säkerhetsventil mot oändliga loopar vid mutationer/buggar.
     */
    private const int MAX_ARG_SCAN = 1000;

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

        // Säkerställ CLI-kontrakt: script-namn måste finnas på index 0.
        // Detta gör array_values() semantiskt viktig om någon skickar en "holey" argv.
        $script = $argv[0] ?? null;
        if (!is_string($script) || $script === '') {
            $this->displayHelp($asMarkdown);
            return;
        }

        // Hitta första "riktiga" token som inte är en flagga (börjar med "-")
        $command = null;
        $commandIndex = null;

        // Skanna argv[1..] men aldrig mer än MAX_ARG_SCAN tokens
        $steps = 0;
        $tail = array_slice($argv, 1);

        foreach ($tail as $offset => $token) {
            $steps++;

            // Cap ska trigga EXAKT en gång (på MAX+1), så break vs continue blir testbart.
            if ($steps === self::MAX_ARG_SCAN + 1) {
                break;
            }

            if ($token === '') {
                continue;
            }

            if (!is_string($token)) {
                continue;
            }

            if (str_starts_with($token, '-')) {
                continue;
            }

            $command = $token;
            $commandIndex = $offset + 1; // +1 pga array_slice startar från argv[1]
            break;
        }

        // Ingen command => visa global help (ev. i Markdown)
        if ($command === null) {
            $this->displayHelp($asMarkdown);
            return;
        }

        // Extra defensivt: ska aldrig hända om $command hittades, men skyddar mot inkonsekvent state.
        if ($commandIndex === null) {
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
