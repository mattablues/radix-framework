<?php

declare(strict_types=1);

namespace Radix\Console\Commands;

class MakeMiddlewareCommand extends BaseCommand
{
    private string $middlewarePath;
    private string $templatePath;

    public function __construct(string $middlewarePath, string $templatePath)
    {
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
        // Hantera hjälpinformation
        if (in_array('--help', $args, true)) {
            $this->showHelp();
            return;
        }

        // Kontrollera om middleware_name skickats
        $middlewareName = $args[0] ?? null;

        if (!$middlewareName) {
            $this->coloredOutput("Error: 'middleware_name' is required.", "red");
            echo "Tip: Use '--help' to see how to use this command.\n";
            return;
        }

        // Skapa middleware filen
        $this->createMiddlewareFile($middlewareName);
    }

    private function showHelp(): void
    {
        $this->coloredOutput("Usage:", "green");
        $this->coloredOutput("  make:middleware [middleware_name]             Create a new middleware.", "yellow");
        $this->coloredOutput("Options:", "green");
        $this->coloredOutput("  [middleware_name]                             The name of the middleware to create.", "yellow");
        $this->coloredOutput("  --help                                        Display this help message.", "yellow");
        echo PHP_EOL;
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
            [$middlewareName, 'App\Middlewares'],
            $template
        );

        // Skriv ny middleware till fil
        file_put_contents($filePath, $content);

        $this->coloredOutput("Middleware created: $filePath", "green");
    }
}