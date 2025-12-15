<?php

declare(strict_types=1);

namespace Radix\Console\Commands;

abstract class BaseCommand
{
    /**
     * Kör kommandot med givna argument.
     *
     * @param array<int, string> $args
     */
    abstract public function execute(array $args): void;

    /**
     * Visa hjälptext för ett kommando.
     *
     * @param array<string, string> $options  Nyckel = flagga, värde = beskrivning.
     * @param list<string>         $examples Exempelkommandon (utan "php radix"), t.ex. ["make:view about/index --layout=main"].
     */
    protected function displayHelp(string $usage, array $options, bool $asMarkdown = false, array $examples = []): void
    {
        if ($asMarkdown) {
            echo "## Usage\n\n";
            echo "```bash\n{$usage}\n```\n\n";

            if ($options !== []) {
                echo "## Options\n\n";
                foreach ($options as $option => $description) {
                    echo "- `{$option}`: {$description}\n";
                }
                echo "\n";
            }

            if ($examples !== []) {
                echo "## Examples\n\n";
                echo "```bash\n";
                foreach ($examples as $example) {
                    echo $example . "\n";
                }
                echo "```\n\n";
            }

            echo "## Tip\n\n";
            echo "- Use `--help` for more information.\n";
            echo "- Use `--md` to output help as Markdown.\n\n";
            return;
        }

        echo "Tip: You can always use '--help' for more information.\n\n";
        echo "Usage: $usage\n";

        if ($options !== []) {
            echo "Options:\n";

            $maxLen = 0;
            foreach ($options as $option => $_description) {
                $len = strlen((string) $option);
                if ($len > $maxLen) {
                    $maxLen = $len;
                }
            }

            foreach ($options as $option => $description) {
                $opt = str_pad((string) $option, $maxLen, ' ');
                echo "  {$opt}  {$description}\n";
            }

            echo "\n";
        } else {
            echo "\n";
        }

        if ($examples !== []) {
            echo "Examples:\n";
            foreach ($examples as $example) {
                echo "  $example\n";
            }
            echo "\n";
        }
    }

    /**
     * Hantera --help/-h-flaggan för ett kommando.
     *
     * @param array<int, string>    $args     Råa argv-argument.
     * @param array<string, string> $options  Nyckel = flagga, värde = beskrivning.
     * @param list<string>         $examples Exempelkommandon (utan "php radix").
     */
    public function handleHelpFlag(array $args, string $usage, array $options, array $examples = []): bool
    {
        $asMarkdown = in_array('--md', $args, true) || in_array('--markdown', $args, true);

        // Kontrollera om --help flaggan finns bland argumenten
        if (in_array('--help', $args, true) || in_array('-h', $args, true)) {
            $this->displayHelp($usage, $options, $asMarkdown, $examples);
            return true; // Returnera true för att indikera att hjälpen visades
        }

        return false; // Ingen hjälpflagga funnen
    }

    /**
     * Hämta värdet för en flagga/option från argv-listan.
     *
     * @param array<int, string> $options
     */
    protected function getOptionValue(array $options, string $key): ?string
    {
        foreach ($options as $option) {
            if (str_starts_with($option, "$key=")) {
                return substr($option, strlen($key) + 1);
            }
        }
        return null;
    }

    /**
     * Färgad terminal-output för bättre läsbarhet.
     */
    protected function coloredOutput(string $message, string $color): void
    {
        $colors = [
            'red' => "\033[31m",
            'green' => "\033[32m",
            'yellow' => "\033[33m",
            'blue' => "\033[34m",
            'reset' => "\033[0m",
        ];

        $colorCode = $colors[$color] ?? $colors['reset'];

        // Lägg till radbrytningar före och efter meddelandet
        echo $colorCode . $message . $colors['reset'] . PHP_EOL;
    }
}
