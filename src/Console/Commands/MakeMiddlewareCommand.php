<?php

declare(strict_types=1);

namespace Radix\Console\Commands;

use Radix\Console\GeneratorConfig;

class MakeMiddlewareCommand extends BaseCommand
{
    private string $middlewarePath;
    private string $templatePath;

    public function __construct(
        string $middlewarePath,
        string $templatePath,
        private readonly GeneratorConfig $config = new GeneratorConfig()
    ) {
        $this->middlewarePath = $middlewarePath;
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
        $usage = 'make:middleware [middleware_name]';
        $options = [
            '[middleware_name]' => 'The name of the middleware to create.',
            '--help, -h' => 'Display this help message.',
            '--md, --markdown' => 'Output help as Markdown.',
        ];

        if ($this->handleHelpFlag($args, $usage, $options)) {
            return;
        }

        // Plocka första "riktiga" argumentet (ignorera flags som börjar med -)
        $middlewareName = null;
        foreach ($args as $arg) {
            if ($arg === '' || $arg[0] === '-') {
                continue;
            }
            $middlewareName = $arg;
            break;
        }

        if (!$middlewareName) {
            $this->coloredOutput("Error: 'middleware_name' is required.", 'red');
            echo "Tip: Use '--help' to see how to use this command.\n";
            return;
        }

        // Skapa middleware filen
        $this->createMiddlewareFile($middlewareName);
    }

    private function createMiddlewareFile(string $middlewareName): void
    {
        $filename = "$middlewareName.php";
        $filePath = "$this->middlewarePath/$filename";

        // Kontrollera om middleware redan finns
        if (file_exists($filePath)) {
            $this->coloredOutput("Error: Middleware '$middlewareName' already exists at $filePath.", "red");
            return;
        }

        // Läs in korrekt mall fil (byt från .php till .stub)
        $templateFile = "$this->templatePath/middleware.stub";

        if (!file_exists($templateFile)) {
            $this->coloredOutput("Error: Middleware template not found at $templateFile.", "red");
            return;
        }

        $template = file_get_contents($templateFile);

        /** @var string $template */
        // Byt ut placeholders i mallen
        $content = str_replace(
            ['[MiddlewareName]', '[Namespace]'],
            [$middlewareName, $this->config->ns('Middlewares')],
            $template
        );

        // Skriv ny middleware till fil
        file_put_contents($filePath, $content);

        $this->coloredOutput("Middleware created: $filePath", "green");
    }
}
