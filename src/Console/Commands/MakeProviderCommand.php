<?php

declare(strict_types=1);

namespace Radix\Console\Commands;

use Radix\Console\GeneratorConfig;

class MakeProviderCommand extends BaseCommand
{
    private string $providerPath;
    private string $templatePath;

    public function __construct(
        string $providerPath,
        string $templatePath,
        private readonly GeneratorConfig $config = new GeneratorConfig()
    ) {
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
        $usage = 'make:provider [provider_name]';
        $options = [
            '[provider_name]' => 'The name of the provider to create.',
            '--help, -h' => 'Display this help message.',
            '--md, --markdown' => 'Output help as Markdown.',
        ];

        if ($this->handleHelpFlag($args, $usage, $options)) {
            return;
        }

        // Plocka första "riktiga" argumentet (ignorera flags som börjar med -)
        $providerName = null;
        foreach ($args as $arg) {
            if ($arg === '' || $arg[0] === '-') {
                continue;
            }
            $providerName = $arg;
            break;
        }

        if (!$providerName) {
            $this->coloredOutput("Error: 'provider_name' is required.", 'red');
            echo "Tip: Use '--help' to see how to use this command.\n";
            return;
        }

        // Skapa provider filen
        $this->createServiceFile($providerName);
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
            [$providerName, $this->config->ns('Providers')],
            $template
        );

        // Skriv ny provider till fil
        file_put_contents($filePath, $content);

        $this->coloredOutput("Provider created: $filePath", "green");
    }
}
