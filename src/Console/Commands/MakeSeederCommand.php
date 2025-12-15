<?php

declare(strict_types=1);

namespace Radix\Console\Commands;

final class MakeSeederCommand extends BaseCommand
{
    public function __construct(
        private readonly string $seederPath,
        private readonly string $templatePath
    ) {}

    /**
     * @param array<int, string> $args
     */
    public function execute(array $args): void
    {
        $this->__invoke($args);
    }

    /**
     * @param array<int, string> $args
     */
    public function __invoke(array $args): void
    {
        $usage = 'make:seeder [Name]';
        $options = [
            '[Name]' => 'Seeder base name, e.g. Users (will generate UsersSeeder class).',
            '--help, -h' => 'Display this help message.',
            '--md, --markdown' => 'Output help as Markdown.',
        ];

        if ($this->handleHelpFlag($args, $usage, $options)) {
            return;
        }

        // Plocka första "riktiga" argumentet (ignorera flags som börjar med -)
        $name = null;
        foreach ($args as $arg) {
            if ($arg === '' || $arg[0] === '-') {
                continue;
            }
            $name = $arg;
            break;
        }

        if (!$name) {
            $this->coloredOutput("Error: seeder name is required.", "red");
            echo "Tip: make:seeder Users\n";
            return;
        }

        $this->createSeederFile($name);
    }

    private function createSeederFile(string $name): void
    {
        $timestamp = date('YmdHis');

        $converted = preg_replace('/(?<!^)[A-Z]/', '_$0', $name);
        $fileSlug  = strtolower($converted ?? '');

        $filename  = "{$timestamp}_{$fileSlug}_seeder.php";
        $filePath  = "{$this->seederPath}/{$filename}";

        $stubPath = "{$this->templatePath}/seeder.stub";
        if (!file_exists($stubPath)) {
            $this->coloredOutput("Error: Stub not found at {$stubPath}.", "red");
            return;
        }

        $stub = file_get_contents($stubPath);
        if (!is_string($stub)) {
            $this->coloredOutput("Error: Could not read stub.", "red");
            return;
        }

        $className = rtrim($name, 'Seeder') . 'Seeder';
        $contents = str_replace(
            ['[ClassName]'],
            [$className],
            $stub
        );

        if (!is_dir($this->seederPath)) {
            mkdir($this->seederPath, 0o755, true);
        }

        file_put_contents($filePath, $contents);
        $this->coloredOutput("Seeder created: {$filePath}", "green");
    }
}
