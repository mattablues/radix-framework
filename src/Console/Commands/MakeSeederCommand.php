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
        if (in_array('--help', $args, true)) {
            $this->showHelp();
            return;
        }

        $name = $args[0] ?? null;
        if (!$name) {
            $this->coloredOutput("Error: seeder name is required.", "red");
            echo "Tip: make:seeder Users\n";
            return;
        }

        $this->createSeederFile($name);
    }

    private function showHelp(): void
    {
        $this->coloredOutput("Usage:", "green");
        $this->coloredOutput("  make:seeder [Name]                            Create a new seeder.", "yellow");
        $this->coloredOutput("Options:", "green");
        $this->coloredOutput("  --help                                        Display this help message.", "yellow");
        echo PHP_EOL;
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