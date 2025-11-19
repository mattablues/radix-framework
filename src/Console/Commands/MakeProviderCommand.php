<?php

declare(strict_types=1);

namespace Radix\Console\Commands;

class MakeProviderCommand extends BaseCommand
{
    private string $providerPath;
    private string $templatePath;

    public function __construct(string $providerPath, string $templatePath)
    {
        $this->providerPath = $providerPath;
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

        // Kontrollera om provider_name skickats
        $providerName = $args[0] ?? null;

        if (!$providerName) {
            $this->coloredOutput("Error: 'provider_name' is required.", "red");
            echo "Tip: Use '--help' to see how to use this command.\n";
            return;
        }

        // Skapa provider filen
        $this->createServiceFile($providerName);
    }

    private function showHelp(): void
    {
        $this->coloredOutput("Usage:", "green");
        $this->coloredOutput("  make:provider [provider_name]                 Create a new provider.", "yellow");
        $this->coloredOutput("Options:", "green");
        $this->coloredOutput("  [provider_name]                               The name of the provider to create.", "yellow");
        $this->coloredOutput("  --help                                        Display this help message.", "yellow");
        echo PHP_EOL;
    }

    private function createServiceFile(string $providerName): void
    {
        $filename = "$providerName.php";
        $filePath = "$this->providerPath/$filename";

        // Kontrollera om provider redan finns
        if (file_exists($filePath)) {
            $this->coloredOutput("Error: Provider '$providerName' already exists at $filePath.", "red");
            return;
        }

        // Läs in korrekt mall fil (byt från .php till .stub)
        $templateFile = "$this->templatePath/provider.stub";

        if (!file_exists($templateFile)) {
            $this->coloredOutput("Error: Provider template not found at $templateFile.", "red");
            return;
        }

        $template = file_get_contents($templateFile);
        if ($template === false) {
            $this->coloredOutput("Error: Failed to read provider template at $templateFile.", "red");
            return;
        }

        /** @var string $template */
        // Byt ut placeholders i mallen
        $content = str_replace(
            ['[ProviderName]', '[Namespace]'],
            [$providerName, 'App\Providers'],
            $template
        );

        // Skriv ny provider till fil
        file_put_contents($filePath, $content);

        $this->coloredOutput("Provider created: $filePath", "green");
    }
}
