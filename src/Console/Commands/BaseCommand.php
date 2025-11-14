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
     */
    protected function displayHelp(string $usage, array $options): void
    {
        // Lägg till --help-referens
        echo "Tip: You can always use '--help' for more information.\n\n";

        // Visa användningsinformation
        echo "Usage: $usage\n";
        echo "Options:\n";

        foreach ($options as $option => $description) {
            echo "  $option\t$description\n";
        }
    }

    /**
     * Hantera --help/-h-flaggan för ett kommando.
     *
     * @param array<int, string>   $args    Råa argv-argument.
     * @param array<string, string> $options Nyckel = flagga, värde = beskrivning.
     */
    public function handleHelpFlag(array $args, string $usage, array $options): bool
    {
        // Kontrollera om --help flaggan finns bland argumenten
        if (in_array('--help', $args, true)) {
            $this->displayHelp($usage, $options);
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
