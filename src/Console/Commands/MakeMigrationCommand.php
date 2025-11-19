<?php

declare(strict_types=1);

namespace Radix\Console\Commands;

class MakeMigrationCommand extends BaseCommand
{
    private string $migrationPath;
    private string $templatePath;

    public function __construct(string $migrationPath, string $templatePath)
    {
        $this->migrationPath = $migrationPath;
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

        // Kontrollera om argument tillhandahålls
        $operation = $args[0] ?? null;
        $tableName = $args[1] ?? null;

        if (!$operation || !$tableName) {
            $this->coloredOutput("Error: Both 'operation' and 'table_name' are required.", "red");
            echo "Tip: Use '--help' to see how to use this command.\n";
            return;
        }

        // Skapa migrationsfilen
        $this->createMigrationFile($operation, $tableName);
    }

    private function showHelp(): void
    {
        $this->coloredOutput("Usage:", "green");
        $this->coloredOutput("  make:migration [operation] [table_name]       Create a new migration.", "yellow");
        $this->coloredOutput("Options:", "green");
        $this->coloredOutput("  [operation]                                   The migration operation (e.g., create, alter.", "yellow");
        $this->coloredOutput("  [table_name]                                  The name of the table to create or alter.", "yellow");
        $this->coloredOutput("  --help                                        Display this help message.", "yellow");
        echo PHP_EOL;
    }

    private function createMigrationFile(string $operation, string $tableName): void
    {
        $timestamp = date('YmdHis');
        $filename = "{$timestamp}_{$operation}_$tableName.php";
        $filePath = "$this->migrationPath/$filename";

        // Använd .stub istället för .php
        $templateFile = "$this->templatePath/{$operation}_table.stub";

        if (!file_exists($templateFile)) {
            $this->coloredOutput("Error: No template found for '$operation' at $templateFile.", "red");
            return;
        }

        // Läs in mallen från .stub-fil
        $template = file_get_contents($templateFile);

        // Generera rätt tabellnamn
        $generatedTableName = ($operation === 'create') ? strtolower($tableName) : $tableName;

        /** @var string $template */
        // Byt ut placeholders i stub-filen
        $content = str_replace(
            ['[TableName]', '[OperationType]'],
            [$generatedTableName, ucfirst($operation)],
            $template
        );

        // Skriv filens innehåll till målfilén
        file_put_contents($filePath, $content);

        $this->coloredOutput("Migration created: $filePath", "green");
    }
}
