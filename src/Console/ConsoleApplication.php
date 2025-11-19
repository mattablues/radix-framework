<?php

declare(strict_types=1);

namespace Radix\Console;

use Exception;

//use Psr\Container\ContainerInterface;

class ConsoleApplication
{
    /**
     * @var array<string, callable>
     */
    private array $commands = [];

    /**
     * Lägg till ett nytt kommando med dess namn och callable.
     */
    public function addCommand(string $name, callable $callable): void
    {
        $this->commands[$name] = $callable;
    }

    /**
     * Kör CLI-applikationen.
     *
     * @param array<int, string> $argv Lista av argument (vanligtvis från $argv).
     */
    public function run(array $argv): void
    {
        $command = $argv[1] ?? null;

        if (!$command) {
            $this->displayHelp(); // Visa hjälp om inget kommando anges
            return;
        }

        if (isset($this->commands[$command])) {
            $args = array_slice($argv, 2);

            // Kontrollera om flaggan "--help" finns, och ge specifik hjälp
            if (in_array('--help', $args, true)) {
                echo "\nUsage: php radix [command] [arguments]\n";
                echo "Available commands:\n";
                foreach ($this->commands as $name => $cmd) { // Byt variabelnamnet till $cmd för att undvika konflikt
                    echo "  - $name\n";
                }
                echo "\nTip: Use '[command] --help' for more information about a specific command.\n\n";

                // Skicka till kommandots egna hjälp om tillgängligt
                call_user_func($this->commands[$command], ['_command' => '--help']);
                return; // Avsluta hjälpflödet
            }

            try {
                call_user_func($this->commands[$command], $args);
            } catch (Exception $e) {
                echo "Error: " . $e->getMessage() . "\n";
            }
        } else {
            echo "Unknown command: '$command'.\n\n";
            $this->displayHelp();
        }
    }

    /**
     * Visa hjälpmeddelande med tillgängliga kommandon.
     */
    private function displayHelp(): void
    {
        echo PHP_EOL;
        echo "Usage: php radix [command] [arguments]\n";
        echo "Available commands:\n";

        foreach ($this->commands as $name => $command) {
            echo "  - $name\n";
        }

        echo "\nTip: Use '[command] --help' for more information about a specific command.\n\n";
    }
}
