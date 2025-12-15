<?php

declare(strict_types=1);

namespace Radix\Console\Commands;

class MakeTestCommand extends BaseCommand
{
    private string $testsPath;
    private string $templatePath;

    public function __construct(string $testsPath, string $templatePath)
    {
        $this->testsPath = rtrim($testsPath, '/');
        $this->templatePath = rtrim($templatePath, '/');
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
        $usage = 'make:test [namespace]/[TestName]';
        $options = [
            '[namespace]' => 'Optional folder(s) under tests/ (e.g. Api/Auth).',
            '[TestName]' => 'Test class name (without .php).',
            '--help, -h' => 'Display this help message.',
            '--md, --markdown' => 'Output help as Markdown.',
        ];
        $examples = [
            'make:test Container/ContainerTest',
            'make:test Api/UserControllerTest',
            'make:test Database/QueryBuilderTest',
        ];

        if ($this->handleHelpFlag($args, $usage, $options, $examples)) {
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
            $this->coloredOutput("Error: 'test_name' is required.", 'red');
            echo "Tip: Use '--help' for usage information.\n";
            return;
        }

        $this->createTestFile($name);
    }

    private function createTestFile(string $testName): void
    {
        $path = $this->generatePath($testName);
        $namespace = $this->generateNamespace($testName);
        $className = $this->getClassName($testName);

        $filePath = "{$this->testsPath}/{$path}.php";
        if (file_exists($filePath)) {
            $this->coloredOutput("Error: Test '$testName' already exists at $filePath.", "red");
            return;
        }

        $this->ensureDirectoryExists(dirname($filePath));

        $stubFile = "{$this->templatePath}/test.stub";
        if (!file_exists($stubFile)) {
            $this->coloredOutput("Error: Test template not found at $stubFile.", "red");
            return;
        }

        $stub = file_get_contents($stubFile);

        /** @var string $stub */
        $content = str_replace(
            ['[Namespace]', '[TestClass]'],
            [$namespace, $className],
            $stub
        );

        file_put_contents($filePath, $content);
        $this->coloredOutput("Test '$testName' created at '$filePath'", "green");
    }

    private function generatePath(string $name): string
    {
        return str_replace(['\\', '/'], '/', $name);
    }

    private function generateNamespace(string $name): string
    {
        $parts = explode('/', $name);
        array_pop($parts);
        return 'Radix\\Tests' . (count($parts) ? '\\' . implode('\\', $parts) : '');
    }

    private function getClassName(string $name): string
    {
        $parts = explode('/', $name);
        return end($parts);
    }

    private function ensureDirectoryExists(string $directory): void
    {
        if (!is_dir($directory)) {
            mkdir($directory, 0o755, true);
        }
    }
}
