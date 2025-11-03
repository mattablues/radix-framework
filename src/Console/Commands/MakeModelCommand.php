<?php

declare(strict_types=1);

namespace Radix\Console\Commands;

class MakeModelCommand extends BaseCommand
{
    private string $modelPath;
    private string $templatePath;

    public function __construct(string $modelPath, string $templatePath)
    {
        $this->modelPath = $modelPath;
        $this->templatePath = $templatePath;
    }

    public function execute(array $args): void
    {
        $this->__invoke($args); // Anropa __invoke-logiken
    }

    public function __invoke(array $args): void
    {
        // Hantera hjälpinformation
        if (in_array('--help', $args, true)) {
            $this->showHelp();
            return;
        }

        // Kontrollera om model_name skickats
        $modelName = $args[0] ?? null;

        if (!$modelName) {
            $this->coloredOutput("Error: 'model_name' is required.", "red");
            echo "Tip: Use '--help' to see how to use this command.\n";
            return;
        }

        // Skapa modellfilén
        $this->createModelFile($modelName);
    }

    private function showHelp(): void
    {
        $this->coloredOutput("Usage:", "green");
        $this->coloredOutput("  make:model [model_name]                       Create a new model.", "yellow");
        $this->coloredOutput("Options:", "green");
        $this->coloredOutput("  [model_name]                                  The name of the model to create.", "yellow");
        $this->coloredOutput("  --help                                        Display this help message.", "yellow");
        echo PHP_EOL;
    }

    private function createModelFile(string $modelName): void
    {
        $filename = "$modelName.php";
        $filePath = "$this->modelPath/$filename";

        // Kontrollera om modellen redan finns
        if (file_exists($filePath)) {
            $this->coloredOutput("Error: Model '$modelName' already exists at $filePath.", "red");
            return;
        }

        // Läs in korrekt mallfil (byt från .php till .stub)
        $templateFile = "$this->templatePath/model.stub";

        if (!file_exists($templateFile)) {
            $this->coloredOutput("Error: Model template not found at $templateFile.", "red");
            return;
        }

        $template = file_get_contents($templateFile);

        // Generera tabellnamn baserat på modellnamnet
        $tableName = strtolower($modelName) . 's'; // Anpassar till plural-form med ett "s"

        // Byt ut placeholders i mallen
        $content = str_replace(
            ['[ModelName]', '[Namespace]', '[table_name]'],
            [$modelName, 'App\Models', $tableName],
            $template
        );

        // Skriv ny modell till fil
        file_put_contents($filePath, $content);

        $this->coloredOutput("Model created: $filePath", "green");
    }
}
