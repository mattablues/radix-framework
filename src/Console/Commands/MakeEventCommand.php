<?php

declare(strict_types=1);

namespace Radix\Console\Commands;

class MakeEventCommand extends BaseCommand
{
    private string $eventPath;
    private string $templatePath;

    public function __construct(string $eventPath, string $templatePath)
    {
        $this->eventPath = $eventPath;
        $this->templatePath = $templatePath;
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
        if (in_array('--help', $args, true)) {
            $this->showHelp();
            return;
        }

        // Kontrollera om event_name skickats
        $eventName = $args[0] ?? null;

        if (!$eventName) {
            $this->coloredOutput("Error: 'event_name' is required.", "red");
            echo "Tip: Use '--help' to see how to use this command.\n";
            return;
        }

        // Skapa event filen
        $this->createEventFile($eventName);
    }

    private function showHelp(): void
    {
        $this->coloredOutput("Usage:", "green");
        $this->coloredOutput("  make:event [event_name]                       Create a new event.", "yellow");
        $this->coloredOutput("Options:", "green");
        $this->coloredOutput("  [event_name]                                  The name of the event to create.", "yellow");
        $this->coloredOutput("  --help                                        Display this help message.", "yellow");
        echo PHP_EOL;
    }

    private function createEventFile(string $eventName): void
    {
        $filename = "$eventName.php";
        $filePath = "$this->eventPath/$filename";

        // Kontrollera om event redan finns
        if (file_exists($filePath)) {
            $this->coloredOutput("Error: Event '$eventName' already exists at $filePath.", "red");
            return;
        }

        // Läs in korrekt mall fil (byt från .php till .stub)
        $templateFile = "$this->templatePath/event.stub";

        if (!file_exists($templateFile)) {
            $this->coloredOutput("Error: Event template not found at $templateFile.", "red");
            return;
        }

        $template = file_get_contents($templateFile);

        /** @var string $template */
        // Byt ut placeholders i mallen
        $content = str_replace(
            ['[EventName]', '[Namespace]'],
            [$eventName, 'App\Events'],
            $template
        );

        // Skriv ny event till fil
        file_put_contents($filePath, $content);

        $this->coloredOutput("Event created: $filePath", "green");
    }
}
