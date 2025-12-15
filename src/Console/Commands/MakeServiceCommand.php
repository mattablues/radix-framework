<?php

declare(strict_types=1);

namespace Radix\Console\Commands;

class MakeServiceCommand extends BaseCommand
{
    private string $servicePath;
    private string $templatePath;

    public function __construct(string $servicePath, string $templatePath)
    {
        $this->servicePath = $servicePath;
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
        $usage = 'make:service [service_name]';
        $options = [
            '[service_name]' => 'The name of the service to create.',
            '--help, -h' => 'Display this help message.',
            '--md, --markdown' => 'Output help as Markdown.',
        ];

        if ($this->handleHelpFlag($args, $usage, $options)) {
            return;
        }

        // Plocka första "riktiga" argumentet (ignorera flags som börjar med -)
        $serviceName = null;
        foreach ($args as $arg) {
            if ($arg === '' || $arg[0] === '-') {
                continue;
            }
            $serviceName = $arg;
            break;
        }

        if (!$serviceName) {
            $this->coloredOutput("Error: 'service_name' is required.", 'red');
            echo "Tip: Use '--help' to see how to use this command.\n";
            return;
        }

        // Skapa servicefilén
        $this->createServiceFile($serviceName);
    }

    private function createServiceFile(string $serviceName): void
    {
        $filename = "$serviceName.php";
        $filePath = "$this->servicePath/$filename";

        // Kontrollera om service redan finns
        if (file_exists($filePath)) {
            $this->coloredOutput("Error: Service '$serviceName' already exists at $filePath.", "red");
            return;
        }

        // Läs in korrekt mallfil (byt från .php till .stub)
        $templateFile = "$this->templatePath/service.stub";

        if (!file_exists($templateFile)) {
            $this->coloredOutput("Error: Service template not found at $templateFile.", "red");
            return;
        }

        $template = file_get_contents($templateFile);
        if ($template === false) {
            $this->coloredOutput("Error: Failed to read service template at $templateFile.", "red");
            return;
        }

        /** @var string $template */
        // Byt ut placeholders i mallen
        $content = str_replace(
            ['[ServiceName]', '[Namespace]'],
            [$serviceName, 'App\Services'],
            $template
        );

        // Skriv ny service till fil
        file_put_contents($filePath, $content);

        $this->coloredOutput("Service created: $filePath", "green");
    }
}
