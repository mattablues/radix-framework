<?php

declare(strict_types=1);

namespace Radix\Console\Commands;

use Radix\Console\GeneratorConfig;

final class MakeFormRequestCommand extends BaseCommand
{
    public function __construct(
        private string $requestsPath,
        private string $templatePath,
        private readonly GeneratorConfig $config = new GeneratorConfig()
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
        $usage = 'make:form-request [namespace]/[request_name] [--no-honeypot]';
        $options = [
            '[namespace]' => 'Optional folder(s) under Requests (e.g. Admin/Auth).',
            '[request_name]' => 'Request class name (without .php).',
            '--no-honeypot' => 'Generate request without honeypot hooks.',
            '--help, -h' => 'Display this help message.',
            '--md, --markdown' => 'Output help as Markdown.',
        ];
        $examples = [
            'make:form-request CreateUserRequest',
            'make:form-request Admin/CreateUserRequest',
            'make:form-request Admin/CreateUserRequest --no-honeypot',
        ];

        if ($this->handleHelpFlag($args, $usage, $options, $examples)) {
            return;
        }

        $noHoneypot = in_array('--no-honeypot', $args, true);

        $requestName = null;
        foreach ($args as $arg) {
            if ($arg === '' || $arg[0] === '-') {
                continue;
            }
            $requestName = $arg;
            break;
        }

        if (!$requestName) {
            $this->coloredOutput("Error: 'request_name' is required.", 'red');
            echo "Tip: Use '--help' for usage information.\n";
            return;
        }

        $this->createRequestFile($requestName, $noHoneypot);
    }

    private function createRequestFile(string $requestName, bool $noHoneypot): void
    {
        $path = $this->generatePath($requestName);
        $namespace = $this->generateNamespace($requestName);
        $className = $this->getClassName($requestName);

        $filePath = "{$this->requestsPath}/{$path}.php";
        if (file_exists($filePath)) {
            $this->coloredOutput("Error: FormRequest '{$requestName}' already exists at {$filePath}.", 'red');
            return;
        }

        $this->ensureDirectoryExists(dirname($filePath));

        $stubName = $noHoneypot ? 'no-honeypot.stub' : 'form-request.stub';
        $stubFile = "{$this->templatePath}/{$stubName}";

        if (!file_exists($stubFile)) {
            $this->coloredOutput("Error: FormRequest template not found at {$stubFile}.", 'red');
            return;
        }

        $stub = file_get_contents($stubFile);
        if (!is_string($stub) || $stub === '') {
            $this->coloredOutput("Error: FormRequest template is empty or unreadable at {$stubFile}.", 'red');
            return;
        }

        $content = str_replace(
            ['[Namespace]', '[RequestName]'],
            [$namespace, $className],
            $stub
        );

        file_put_contents($filePath, $content);

        $this->coloredOutput(
            "FormRequest '{$requestName}' created at '{$filePath}' (stub: {$stubName})",
            'green'
        );
    }

    private function generatePath(string $name): string
    {
        return str_replace(['\\', '/'], '/', $name);
    }

    private function generateNamespace(string $name): string
    {
        $parts = explode('/', str_replace('\\', '/', $name));
        array_pop($parts);

        $base = $this->config->ns('Requests');

        return $base . (count($parts) ? '\\' . implode('\\', $parts) : '');
    }

    private function getClassName(string $name): string
    {
        $parts = explode('/', str_replace('\\', '/', $name));
        return (string) end($parts);
    }

    private function ensureDirectoryExists(string $directory): void
    {
        if (!is_dir($directory)) {
            mkdir($directory, 0o755, true);
        }
    }
}
