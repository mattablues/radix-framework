<?php

declare(strict_types=1);

namespace Radix\Console\Commands;

class MakeListenerCommand extends BaseCommand
{
    private string $listenerPath;
    private string $templatePath;

    public function __construct(string $listenerPath, string $templatePath)
    {
        $this->listenerPath = $listenerPath;
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
        // Hantera hjälpinformation
        if (in_array('--help', $args, true)) {
            $this->showHelp();
            return;
        }

        // Kontrollera om listener_name skickats
        $listenerName = $args[0] ?? null;

        if (!$listenerName) {
            $this->coloredOutput("Error: 'listener_name' is required.", "red");
            echo "Tip: Use '--help' to see how to use this command.\n";
            return;
        }

        // Skapa listener filen
        $this->createListenerFile($listenerName);
    }

    private function showHelp(): void
    {
        $this->coloredOutput("Usage:", "green");
        $this->coloredOutput("  make:listener [listener_name]                 Create a new listener.", "yellow");
        $this->coloredOutput("Options:", "green");
        $this->coloredOutput("  [listener_name]                               The name of the listener to create.", "yellow");
        $this->coloredOutput("  --help                                        Display this help message.", "yellow");
        echo PHP_EOL;
    }

    private function createListenerFile(string $listenerName): void
    {
        $filename = "$listenerName.php";
        $filePath = "$this->listenerPath/$filename";

        // Kontrollera om listener redan finns
        if (file_exists($filePath)) {
            $this->coloredOutput("Error: Listener '$listenerName' already exists at $filePath.", "red");
            return;
        }

        // Läs in korrekt mall fil (byt från .php till .stub)
        $templateFile = "$this->templatePath/listener.stub";

        if (!file_exists($templateFile)) {
            $this->coloredOutput("Error: Listener template not found at $templateFile.", "red");
            return;
        }

        $template = file_get_contents($templateFile);

        /** @var string $template */
        // Byt ut placeholders i mallen
        $content = str_replace(
            ['[ListenerName]', '[Namespace]'],
            [$listenerName, 'App\EventListeners'],
            $template
        );

        // Skriv ny listener till fil
        file_put_contents($filePath, $content);

        $this->coloredOutput("Listener created: $filePath", "green");
    }
}
