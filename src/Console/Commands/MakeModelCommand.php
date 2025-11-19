<?php

declare(strict_types=1);

namespace Radix\Console\Commands;

use Radix\Support\StringHelper;

// använd relationernas pluralizer
class MakeModelCommand extends BaseCommand
{
    private string $modelPath;
    private string $templatePath;

    public function __construct(string $modelPath, string $templatePath)
    {
        $this->modelPath = $modelPath;
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
        if (in_array('--help', $args, true)) {
            $this->showHelp();
            return;
        }

        $modelName = $args[0] ?? null;
        if (!$modelName) {
            $this->coloredOutput("Error: 'model_name' is required.", "red");
            echo "Tip: Use '--help' to see how to use this command.\n";
            return;
        }

        $explicitTable = null;
        $usePlural = false;
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--table=')) {
                $explicitTable = substr($arg, strlen('--table='));
            } elseif ($arg === '--plural') {
                $usePlural = true;
            }
        }

        $this->createModelFile($modelName, $explicitTable, $usePlural);
    }

    private function showHelp(): void
    {
        $this->coloredOutput("Usage:", "green");
        $this->coloredOutput("  make:model [model_name] [--table=table_name] [--plural]  Create a new model.", "yellow");
        $this->coloredOutput("Options:", "green");
        $this->coloredOutput("  [model_name]                                  The name of the model to create.", "yellow");
        $this->coloredOutput("  --table=table_name                            Override inferred table name.", "yellow");
        $this->coloredOutput("  --plural                                      Pluralize inferred table name (uses config/pluralization.php).", "yellow");
        $this->coloredOutput("  --help                                        Display this help message.", "yellow");
        echo PHP_EOL;
    }

    private function createModelFile(string $modelName, ?string $explicitTable = null, bool $usePlural = false): void
    {
        $filename = "$modelName.php";
        $filePath = "$this->modelPath/$filename";

        if (file_exists($filePath)) {
            $this->coloredOutput("Error: Model '$modelName' already exists at $filePath.", "red");
            return;
        }

        $templateFile = "$this->templatePath/model.stub";
        if (!file_exists($templateFile)) {
            $this->coloredOutput("Error: Model template not found at $templateFile.", "red");
            return;
        }

        $template = file_get_contents($templateFile);

        // Ny helper: konvertera ModelName -> model_name
        $toSnake = static function (string $name): string {
            $snake = preg_replace('/(?<!^)[A-Z]/', '_$0', $name);
            return strtolower($snake ?? $name);
        };

        if ($explicitTable) {
            $tableName = $explicitTable;
        } elseif ($usePlural) {
            // Pluralisera model-namnet först, därefter snake_case
            $tableName = $toSnake(StringHelper::pluralize($modelName));
        } else {
            // Standard: snake_case av model-namnet
            $tableName = $toSnake($modelName);
        }

        /** @var string $template */
        $content = str_replace(
            ['[ModelName]', '[Namespace]', '[table_name]'],
            [$modelName, 'App\Models', $tableName],
            $template
        );

        file_put_contents($filePath, $content);
        $this->coloredOutput("Model created: $filePath", "green");
    }
}
