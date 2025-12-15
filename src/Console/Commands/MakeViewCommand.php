<?php

declare(strict_types=1);

namespace Radix\Console\Commands;

class MakeViewCommand extends BaseCommand
{
    private string $viewsBasePath;
    private string $templatePath;

    public function __construct(string $viewsBasePath, string $templatePath)
    {
        $this->viewsBasePath = rtrim($viewsBasePath, '/');
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
     * @param array<int,string> $args
     */
    public function __invoke(array $args): void
    {
        $usage = 'make:view <path> [--layout=main|sidebar|admin|auth] [--ext=ratio.php]';
        $options = [
            '<path>' => "View path, e.g. 'about/index' or 'docs/guide/intro'.",
            '--layout=main|sidebar|admin|auth' => 'Choose layout (default: main).',
            '--ext=ratio.php' => 'File extension (default: ratio.php).',
            '--help, -h' => 'Display this help message.',
            '--md, --markdown' => 'Output help as Markdown.',
        ];
        $examples = [
            'make:view about/index --layout=main',
            'make:view auth/login --layout=auth',
            'make:view admin/dashboard --layout=admin',
            'make:view docs/guide/intro --layout=sidebar --ext=ratio.php',
        ];

        if ($this->handleHelpFlag($args, $usage, $options, $examples)) {
            return;
        }

        // Ta path exakt som MakeController tar controllerName
        $viewPath = null;
        foreach ($args as $arg) {
            if ($arg === '' || $arg[0] === '-') {
                continue;
            }
            $viewPath = $arg;
            break;
        }

        if (!$viewPath) {
            $this->coloredOutput("Error: You must provide a view path, e.g. 'test/index'.", "red");
            echo "Tip: Use '--help' for usage information.\n";
            return;
        }

        // Plocka options (--layout=..., --ext=...)
        $options = $this->parseOptions($args);
        $layout  = $options['layout'] ?? 'main';
        $ext     = $options['ext'] ?? 'ratio.php';

        if (!in_array($layout, ['main', 'sidebar', 'admin', 'auth'], true)) {
            $this->coloredOutput("Error: Invalid --layout. Allowed: main, sidebar, admin, auth.", "red");
            return;
        }

        // Samma normalisering som i MakeControllerCommand
        $normalizedPath = $this->generatePath($viewPath);

        $targetFile = $this->viewsBasePath . '/' . $normalizedPath . '.' . $ext;
        $targetDir  = dirname($targetFile);

        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0o755, true);
        }

        if (file_exists($targetFile)) {
            $this->coloredOutput("Error: View already exists: $targetFile", "red");
            return;
        }

        $stubFile = $this->templatePath . '/view.stub';
        if (!file_exists($stubFile)) {
            $this->coloredOutput("Error: Stub not found: $stubFile", "red");
            return;
        }

        $stub   = (string) file_get_contents($stubFile);
        $title  = $this->deriveTitle(basename($normalizedPath));
        $pageId = $this->derivePageId($normalizedPath);

        $content = str_replace(
            ['[LAYOUT]', '[TITLE]', '[PAGEID]'],
            [$layout, $title, $pageId],
            $stub
        );

        if (file_put_contents($targetFile, $content) === false) {
            $this->coloredOutput("Error: Failed writing file: $targetFile", "red");
            return;
        }

        $this->coloredOutput("View created: $targetFile", "green");
    }

    /**
     * @param array<int,string> $args
     * @return array<string,string>
     */
    private function parseOptions(array $args): array
    {
        $opts = [];
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--')) {
                $parts = explode('=', substr($arg, 2), 2);
                $key = $parts[0] ?? '';
                $val = $parts[1] ?? '1';
                if ($key !== '') {
                    $opts[$key] = $val;
                }
            }
        }
        return $opts;
    }

    // Enkel path-normalisering likt MakeController
    private function generatePath(string $name): string
    {
        return str_replace(['\\', '/'], '/', $name);
    }

    private function deriveTitle(string $basename): string
    {
        $name = str_replace(['-', '_'], ' ', $basename);
        return mb_convert_case($name, MB_CASE_TITLE, 'UTF-8');
    }

    private function derivePageId(string $normalizedPath): string
    {
        return strtolower(str_replace(['/', ' '], ['-', '-'], $normalizedPath));
    }
}
