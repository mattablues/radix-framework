<?php

declare(strict_types=1);

namespace Radix\Console\Commands;

class MakeControllerCommand extends BaseCommand
{
    private string $controllerPath;
    private string $templatePath;

    public function __construct(string $controllerPath, string $templatePath)
    {
        $this->controllerPath = $controllerPath;
        $this->templatePath = $templatePath;
    }

    /**
     * Kör kommandot med givna argument.
     *
     * @param array<int, string> $args
     */
    public function execute(array $args): void
    {
        $this->__invoke($args);
    }

    /**
     * Gör objektet anropbart som ett kommando.
     *
     * @param array<int, string> $args
     */
    public function __invoke(array $args): void
    {
        // Visa hjälp om ingen input ges
        if (in_array('--help', $args, true)) {
            $this->showHelp();
            return;
        }

        // Kontrollera att controller-namnet har skickats in
        $controllerName = $args[0] ?? null;

        if (!$controllerName) {
            $this->coloredOutput("Error: 'controller_name' is required.", "red");
            echo "Tip: Use '--help' for usage information.\n";
            return;
        }

        // Skapa controller-filen
        $this->createControllerFile($controllerName);
    }

    private function showHelp(): void
    {
        $this->coloredOutput("Usage:", "green");
        $this->coloredOutput("  make:controller [namespace]/[controller_name] Create a new controller.", "yellow");
        $this->coloredOutput("Options:", "green");
        $this->coloredOutput("  [namespace]                                   The namespace of the controller to create. (optional)", "yellow");
        $this->coloredOutput("  [controller_name]                             The name of the controller to create.", "yellow");
        $this->coloredOutput("  --help                                        Display this help message.", "yellow");
        echo PHP_EOL;
    }

    private function createControllerFile(string $controllerName): void
    {
        // Omvandla namn till rätt sökväg och namespace
        $path = $this->generatePath($controllerName);
        $namespace = $this->generateNamespace($controllerName);

        // Kontrollera om filen redan finns
        $filePath = "$this->controllerPath/$path.php";
        if (file_exists($filePath)) {
            $this->coloredOutput("Error: Controller '$controllerName' already exists at $filePath.", "red");
            return;
        }

        // Kontrollera och skapa mappen om nödvändigt
        $this->ensureDirectoryExists(dirname($filePath));

        // Läs in stub-filen
        $stubFile = "$this->templatePath/controller.stub";

        if (!file_exists($stubFile)) {
            $this->coloredOutput("Error: Controller template not found at $stubFile.", "red");
            return;
        }

        $stub = file_get_contents($stubFile);

        /** @var string $stub */
        // Ersätt placeholder med korrekt innehåll
        $className = $this->getClassName($controllerName);
        $content = str_replace(
            ['[Namespace]', '[ControllerName]'],
            [$namespace, $className],
            $stub
        );

        // Skriv innehållet till en fil
        file_put_contents($filePath, $content);
        $this->coloredOutput("Controller '$controllerName' created at '$filePath'", "green");
    }

    private function generatePath(string $name): string
    {
        return str_replace(['\\', '/'], '/', $name);
    }

    private function generateNamespace(string $name): string
    {
        $parts = explode('/', $name);
        array_pop($parts); // Exkludera själva filnamnet
        return 'App\Controllers' . (count($parts) ? '\\' . implode('\\', $parts) : '');
    }

    private function getClassName(string $name): string
    {
        $parts = explode('/', $name);
        return end($parts); // Returnera sista delen som klassnamn
    }

    private function ensureDirectoryExists(string $directory): void
    {
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
    }
}
