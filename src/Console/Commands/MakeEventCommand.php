<?php

declare(strict_types=1);

namespace Radix\Console\Commands;

use Radix\Console\GeneratorConfig;

class MakeEventCommand extends BaseCommand
{
    private string $eventPath;
    private string $templatePath;

    public function __construct(
        string $eventPath,
        string $templatePath,
        private readonly GeneratorConfig $config = new GeneratorConfig()
    ) {
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
        $usage = 'make:event [event_name]';
        $options = [
            '[event_name]' => 'The name of the event to create.',
            '--help, -h' => 'Display this help message.',
            '--md, --markdown' => 'Output help as Markdown.',
        ];

        if ($this->handleHelpFlag($args, $usage, $options)) {
            return;
        }

        // Plocka första "riktiga" argumentet (ignorera flags som börjar med -)
        $eventName = null;
        foreach ($args as $arg) {
            if ($arg === '' || $arg[0] === '-') {
                continue;
            }
            $eventName = $arg;
            break;
        }

        if (!$eventName) {
            $this->coloredOutput("Error: 'event_name' is required.", 'red');
            echo "Tip: Use '--help' to see how to use this command.\n";
            return;
        }

        // Skapa event filen
        $this->createEventFile($eventName);
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
        $content = str_replace(
            ['[EventName]', '[Namespace]'],
            [$eventName, $this->config->ns('Events')],
            $template
        );

        // Skriv ny event till fil
        file_put_contents($filePath, $content);

        $this->coloredOutput("Event created: $filePath", "green");
    }
}
