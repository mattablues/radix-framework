<?php

declare(strict_types=1);

namespace Radix\Console\Commands;

// ... existing code ...

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
        $usage = 'make:migration [operation] [table_name]';
        $options = [
            '[operation]' => 'Migration operation (e.g. create, alter). Must have a matching stub: {operation}_table.stub.',
            '[table_name]' => 'Table name (for create: will be lowercased).',
            '--help, -h' => 'Display this help message.',
            '--md, --markdown' => 'Output help as Markdown.',
        ];
        $examples = [
            'make:migration create users',
            'make:migration alter users',
            'make:migration create blog_posts',
        ];

        if ($this->handleHelpFlag($args, $usage, $options, $examples)) {
            return;
        }

        // Plocka "riktiga" argument (ignorera flags som börjar med -)
        $positionals = [];
        foreach ($args as $arg) {
            if ($arg === '' || $arg[0] === '-') {
                continue;
            }
            $positionals[] = $arg;
        }

        $operation = $positionals[0] ?? null;
        $tableName = $positionals[1] ?? null;

        if (!$operation || !$tableName) {
            $this->coloredOutput("Error: Both 'operation' and 'table_name' are required.", "red");
            echo "Tip: Use '--help' to see how to use this command.\n";
            return;
        }

        // Skapa migrationsfilen
        $this->createMigrationFile($operation, $tableName);
    }

    // ... existing code ...
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
