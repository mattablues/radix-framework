<?php

declare(strict_types=1);

namespace Radix\Console\Commands;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

final class ScaffoldInstallCommand extends BaseCommand
{
    public function __construct(
        private readonly string $presetsRoot,  // t.ex. ROOT_PATH . '/templates/scaffolds'
        private readonly string $projectRoot   // t.ex. ROOT_PATH
    ) {}

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
        $usage = 'scaffold:install <preset> [--force] [--dry-run]';
        $options = [
            '<preset>'        => 'Namn eller path till preset under presets-root (t.ex. "auth", "routes/auth").',
            '--force'         => 'Skriv över befintliga filer.',
            '--dry-run'       => 'Visa vad som skulle göras utan att skriva några filer.',
            '--help, -h'      => 'Visa hjälp för kommandot.',
            '--md, --markdown' => 'Output hjälp som Markdown.',
        ];
        $examples = [
            'scaffold:install auth',
            'scaffold:install user',
            'scaffold:install admin --force',
            'scaffold:install routes/auth --dry-run',
        ];

        if ($this->handleHelpFlag($args, $usage, $options, $examples)) {
            return;
        }

        $preset = $this->extractPresetArgument($args);
        if ($preset === null || $preset === '') {
            $this->coloredOutput("Error: <preset> är obligatoriskt.", "red");
            echo "Tip: Använd '--help' för hjälp.\n";
            return;
        }

        if ($this->containsPathTraversal($preset)) {
            $this->coloredOutput("Error: Ogiltigt preset-namn (path traversal är inte tillåtet).", "red");
            return;
        }

        $force = in_array('--force', $args, true);
        $dryRun = in_array('--dry-run', $args, true);

        try {
            $this->installPreset($preset, $force, $dryRun);
        } catch (RuntimeException $e) {
            $this->coloredOutput("Error: " . $e->getMessage(), "red");
        }
    }

    /**
     * @param array<int, string> $args
     */
    private function extractPresetArgument(array $args): ?string
    {
        foreach ($args as $arg) {
            if ($arg === '' || $arg[0] === '-') {
                continue;
            }
            return $arg;
        }

        return null;
    }

    private function containsPathTraversal(string $preset): bool
    {
        // Enkel guard mot .. i preset-namnet
        return str_contains($preset, '..');
    }

    private function installPreset(string $preset, bool $force, bool $dryRun): void
    {
        $this->coloredOutput("--- Installing scaffold preset: {$preset} ---", "blue");

        // 1) Bestäm source-path: katalog eller .stub‑fil
        $base = rtrim($this->presetsRoot, DIRECTORY_SEPARATOR);
        $normalizedPreset = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $preset);

        $sourceDir = $base . DIRECTORY_SEPARATOR . $normalizedPreset;
        $sourceFile = $sourceDir . '.stub';

        if (is_dir($sourceDir)) {
            // Katalog‑preset: kopiera allt rekursivt
            $this->installFromDirectory($sourceDir, $force, $dryRun);
            return;
        }

        if (is_file($sourceFile)) {
            // Enstaka stub‑fil: kopiera denna
            $this->installSingleStubFile($sourceFile, $normalizedPreset, $force, $dryRun);
            return;
        }

        throw new RuntimeException("Preset '{$preset}' kunde inte hittas under {$this->presetsRoot}.");
    }

    private function installFromDirectory(string $sourceDir, bool $force, bool $dryRun): void
    {
        $sourceDirReal = realpath($sourceDir);
        if ($sourceDirReal === false) {
            throw new RuntimeException("Kunde inte läsa preset-katalog: {$sourceDir}");
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceDirReal, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        $projectRootReal = $this->requireProjectRootRealpath();

        /** @var SplFileInfo $item */
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                continue;
            }

            $relativePath = substr($item->getPathname(), strlen($sourceDirReal) + 1);

            // Om filen slutar på .stub, ta bort extension i target
            if (str_ends_with($relativePath, '.stub')) {
                $relativePath = substr($relativePath, 0, -5);
            }

            $target = $projectRootReal . DIRECTORY_SEPARATOR . $relativePath;
            $this->guardInsideProject($target, $projectRootReal);

            $this->copyFile($item->getPathname(), $target, $force, $dryRun);
        }

        $this->coloredOutput("Preset installerad från katalog: {$sourceDirReal}", "green");
    }

    private function installSingleStubFile(string $sourceFile, string $normalizedPreset, bool $force, bool $dryRun): void
    {
        $projectRootReal = $this->requireProjectRootRealpath();

        // normalizedPreset kan t.ex. vara "routes/auth"
        // Vi tar den som target-relativ path och byter .stub mot .php implicit
        $relativePath = $normalizedPreset;

        // Om preset ligger under t.ex. "routes/auth" vill vi hamna i "routes/auth.php"
        $target = $projectRootReal . DIRECTORY_SEPARATOR . $relativePath . '.php';

        $this->guardInsideProject($target, $projectRootReal);

        $this->copyFile($sourceFile, $target, $force, $dryRun);

        $this->coloredOutput("Preset installerad till: {$target}", "green");
    }

    private function requireProjectRootRealpath(): string
    {
        $real = realpath($this->projectRoot);

        if ($real === false) {
            throw new RuntimeException("Ogiltigt projectRoot: {$this->projectRoot}");
        }

        return rtrim($real, DIRECTORY_SEPARATOR);
    }

    private function guardInsideProject(string $target, string $projectRootReal): void
    {
        $targetDir = dirname($target);

        // Vi behöver en realpath för katalogen; skapa den inte om den inte finns
        $targetDirReal = realpath($targetDir);
        if ($targetDirReal === false) {
            // Finns inte än – kolla ändå med en simpel starts‑med‑kontroll när den väl finns.
            // Vi bygger en "simulerad" path.
            $normalized = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $targetDir);
            if (!str_starts_with($normalized, $projectRootReal)) {
                throw new RuntimeException("Target path är utanför projektet: {$target}");
            }
            return;
        }

        if (!str_starts_with($targetDirReal, $projectRootReal)) {
            throw new RuntimeException("Target path är utanför projektet: {$targetDirReal}");
        }
    }

    private function copyFile(string $source, string $target, bool $force, bool $dryRun): void
    {
        $targetDir = dirname($target);

        if (!is_dir($targetDir)) {
            if ($dryRun) {
                $this->coloredOutput("[dry-run] Skulle skapa katalog: {$targetDir}", "yellow");
            } else {
                if (!mkdir($targetDir, 0o755, true) && !is_dir($targetDir)) {
                    throw new RuntimeException("Kunde inte skapa katalog: {$targetDir}");
                }
                $this->coloredOutput("Skapade katalog: {$targetDir}", "green");
            }
        }

        if (file_exists($target) && !$force) {
            $this->coloredOutput("Hoppar över (finns redan): {$target}", "yellow");
            return;
        }

        if ($dryRun) {
            $action = file_exists($target) ? 'Skulle skriva över' : 'Skulle skapa';
            $this->coloredOutput("[dry-run] {$action} fil: {$target}", "yellow");
            return;
        }

        if (!copy($source, $target)) {
            throw new RuntimeException("Kunde inte kopiera {$source} till {$target}");
        }

        $action = file_exists($target) && $force ? 'Skrev över' : 'Skapade';
        $this->coloredOutput("{$action} fil: {$target}", "green");
    }
}
