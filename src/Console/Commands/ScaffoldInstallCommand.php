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
    /**
     * @var array{
     *   presetsPlanned:int,
     *   presetsProcessed:int,
     *   created:int,
     *   overwritten:int,
     *   skipped:int,
     *   dirsCreated:int,
     *   dirsWouldCreate:int,
     *   filesWouldCreate:int,
     *   filesWouldOverwrite:int
     * }
     */
    private array $stats = [
        'presetsPlanned' => 0,
        'presetsProcessed' => 0,
        'created' => 0,
        'overwritten' => 0,
        'skipped' => 0,
        'dirsCreated' => 0,
        'dirsWouldCreate' => 0,
        'filesWouldCreate' => 0,
        'filesWouldOverwrite' => 0,
    ];

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
        $usage = 'scaffold:install <preset>|--all [--force] [--force-placeholders] [--dry-run]';
        $options = [
            '<preset>'             => 'Namn eller path till preset under presets-root (t.ex. "auth", "routes/auth").',
            '--all'                => 'Installera ALLA presets under presets-root (top-level + dependencies).',
            '--force'              => 'Skriv över befintliga filer.',
            '--force-placeholders' => 'Skriv över endast placeholder-filer (t.ex. i minimal install för PHPStan).',
            '--dry-run'            => 'Visa vad som skulle göras utan att skriva några filer.',
            '--help, -h'           => 'Visa hjälp för kommandot.',
            '--md, --markdown'     => 'Output hjälp som Markdown.',
        ];
        $examples = [
            'scaffold:install auth',
            'scaffold:install auth --force-placeholders',
            'scaffold:install admin --force',
            'scaffold:install --all --dry-run',
            'scaffold:install --all --force-placeholders',
            'scaffold:install routes/auth --dry-run',
        ];

        if ($this->handleHelpFlag($args, $usage, $options, $examples)) {
            return;
        }

        $args = $this->stripCommandNameFromArgs($args);

        $force = in_array('--force', $args, true);
        $forcePlaceholders = in_array('--force-placeholders', $args, true);
        $dryRun = in_array('--dry-run', $args, true);
        $all = in_array('--all', $args, true);

        $this->resetStats();

        $preset = $this->extractPresetArgument($args);

        if ($all && $preset !== null && $preset !== '') {
            $this->coloredOutput("Error: Ange antingen <preset> eller --all, inte båda.", "red");
            echo "Tip: Använd '--help' för hjälp.\n";
            return;
        }

        if ($all) {
            try {
                $this->installAllPresets($force, $forcePlaceholders, $dryRun);
            } catch (RuntimeException $e) {
                $this->coloredOutput("Error: " . $e->getMessage(), "red");
            } finally {
                $this->printSummary($dryRun);
            }
            return;
        }

        if ($preset === null || $preset === '') {
            $this->coloredOutput("Error: <preset> är obligatoriskt (eller använd --all).", "red");
            echo "Tip: Använd '--help' för hjälp.\n";
            return;
        }

        if ($this->containsPathTraversal($preset)) {
            $this->coloredOutput("Error: Ogiltigt preset-namn (path traversal är inte tillåtet).", "red");
            return;
        }

        try {
            $this->stats['presetsPlanned'] = 1;
            $this->stats['presetsProcessed'] = 0;

            $this->installPreset($preset, $force, $forcePlaceholders, $dryRun);

            $this->stats['presetsProcessed'] = 1;
        } catch (RuntimeException $e) {
            $this->coloredOutput("Error: " . $e->getMessage(), "red");
        } finally {
            $this->printSummary($dryRun);
        }
    }

    /**
     * Vissa runners skickar med kommandonamnet i args (t.ex. "scaffold:install").
     * Då kan extractPresetArgument() annars feltolka kommandonamnet som <preset>.
     *
     * @param array<int, string> $args
     * @return array<int, string>
     */
    private function stripCommandNameFromArgs(array $args): array
    {
        foreach ($args as $i => $arg) {
            if ($arg === '' || $arg[0] === '-') {
                continue;
            }

            if ($arg === 'scaffold:install') {
                unset($args[$i]);
                return array_values($args);
            }

            break;
        }

        return $args;
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

    private function installPreset(string $preset, bool $force, bool $forcePlaceholders, bool $dryRun): void
    {
        $this->coloredOutput("--- Installing scaffold preset: {$preset} ---", "blue");

        $base = rtrim($this->presetsRoot, DIRECTORY_SEPARATOR);
        $normalizedPreset = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $preset);

        $sourceDir = $base . DIRECTORY_SEPARATOR . $normalizedPreset;
        $sourceFile = $sourceDir . '.stub';

        if (is_dir($sourceDir)) {
            $projectRootReal = $this->requireProjectRootRealpath();
            $this->ensureRequirementsInstalled($sourceDir, $normalizedPreset, $projectRootReal);

            $this->installFromDirectory($sourceDir, $force, $forcePlaceholders, $dryRun);

            if (!$dryRun) {
                $this->writeInstalledMarker($normalizedPreset, $projectRootReal);
            }
            return;
        }

        if (is_file($sourceFile)) {
            $projectRootReal = $this->requireProjectRootRealpath();
            $this->ensureRequirementsInstalled(dirname($sourceFile), $normalizedPreset, $projectRootReal);

            $this->installSingleStubFile($sourceFile, $normalizedPreset, $force, $forcePlaceholders, $dryRun);

            if (!$dryRun) {
                $this->writeInstalledMarker($normalizedPreset, $projectRootReal);
            }
            return;
        }

        throw new RuntimeException("Preset '{$preset}' kunde inte hittas under {$this->presetsRoot}.");
    }

    /**
     * @return array<int,string>
     */
    private function readRequires(string $presetDir): array
    {
        $file = rtrim($presetDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.requires.php';

        if (!is_file($file)) {
            return [];
        }

        $requires = require $file;

        if (!is_array($requires)) {
            throw new RuntimeException(".requires.php måste returnera en array.");
        }

        $out = [];
        foreach ($requires as $r) {
            if (is_string($r) && $r !== '') {
                $out[] = $r;
            }
        }

        return $out;
    }

    private function ensureRequirementsInstalled(string $presetDir, string $normalizedPreset, string $projectRootReal): void
    {
        $requires = $this->readRequires($presetDir);
        if ($requires === []) {
            return;
        }

        foreach ($requires as $req) {
            if (!$this->isPresetInstalled($req, $projectRootReal)) {
                throw new RuntimeException(sprintf(
                    "Preset '%s' kräver att '%s' är installerat först.",
                    $normalizedPreset,
                    $req
                ));
            }
        }
    }

    private function isPresetInstalled(string $presetName, string $projectRootReal): bool
    {
        // Samma normalisering som i writeInstalledMarker()
        $normalized = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $presetName);
        $name = str_replace(DIRECTORY_SEPARATOR, '-', $normalized);

        $marker = $projectRootReal
            . DIRECTORY_SEPARATOR . '.radix'
            . DIRECTORY_SEPARATOR . 'scaffolds'
            . DIRECTORY_SEPARATOR . $name . '.installed';

        return is_file($marker);
    }

    private function writeInstalledMarker(string $normalizedPreset, string $projectRootReal): void
    {
        // Normalisera: "routes/auth" => "routes-auth" som marker-namn
        $name = str_replace(DIRECTORY_SEPARATOR, '-', $normalizedPreset);

        $dir = $projectRootReal
            . DIRECTORY_SEPARATOR . '.radix'
            . DIRECTORY_SEPARATOR . 'scaffolds';

        if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
            throw new RuntimeException("Kunde inte skapa marker-katalog: {$dir}");
        }

        $marker = $dir . DIRECTORY_SEPARATOR . $name . '.installed';

        // En markerfil räcker (innehållet spelar ingen roll)
        if (!is_file($marker)) {
            file_put_contents($marker, 'installed');
        }
    }

    private function installFromDirectory(string $sourceDir, bool $force, bool $forcePlaceholders, bool $dryRun): void
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

            if (str_ends_with($relativePath, '.stub')) {
                $relativePath = substr($relativePath, 0, -5);
            }

            $target = $projectRootReal . DIRECTORY_SEPARATOR . $relativePath;
            $this->guardInsideProject($target, $projectRootReal);

            $this->copyFile($item->getPathname(), $target, $force, $forcePlaceholders, $dryRun);
        }

        $this->coloredOutput("Preset installerad från katalog: {$sourceDirReal}", "green");
    }

    private function installSingleStubFile(
        string $sourceFile,
        string $normalizedPreset,
        bool $force,
        bool $forcePlaceholders,
        bool $dryRun
    ): void {
        $projectRootReal = $this->requireProjectRootRealpath();

        $relativePath = $normalizedPreset;

        $targetRelative = str_ends_with($relativePath, '.php')
            ? $relativePath
            : ($relativePath . '.php');

        $target = $projectRootReal . DIRECTORY_SEPARATOR . $targetRelative;

        $this->guardInsideProject($target, $projectRootReal);

        $this->copyFile($sourceFile, $target, $force, $forcePlaceholders, $dryRun);

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

    private function copyFile(string $source, string $target, bool $force, bool $forcePlaceholders, bool $dryRun): void
    {
        $targetDir = dirname($target);

        if (!is_dir($targetDir)) {
            if ($dryRun) {
                $this->stats['dirsWouldCreate']++;
                $this->coloredOutput("[dry-run] Skulle skapa katalog: {$targetDir}", "yellow");
            } else {
                if (!mkdir($targetDir, 0o755, true) && !is_dir($targetDir)) {
                    throw new RuntimeException("Kunde inte skapa katalog: {$targetDir}");
                }
                $this->stats['dirsCreated']++;
                $this->coloredOutput("Skapade katalog: {$targetDir}", "green");
            }
        }

        if (file_exists($target) && !$force) {
            if (!$forcePlaceholders || !$this->isPlaceholderFile($target)) {
                $this->stats['skipped']++;
                $this->coloredOutput("Hoppar över (finns redan): {$target}", "yellow");
                return;
            }
            // annars: tillåt overwrite av placeholder
        }

        $exists = file_exists($target);

        if ($dryRun) {
            if ($exists) {
                $this->stats['filesWouldOverwrite']++;
            } else {
                $this->stats['filesWouldCreate']++;
            }

            $action = $exists ? 'Skulle skriva över' : 'Skulle skapa';
            $this->coloredOutput("[dry-run] {$action} fil: {$target}", "yellow");
            return;
        }

        if (!copy($source, $target)) {
            throw new RuntimeException("Kunde inte kopiera {$source} till {$target}");
        }

        if ($exists) {
            $this->stats['overwritten']++;
            $this->coloredOutput("Skrev över fil: {$target}", "green");
            return;
        }

        $this->stats['created']++;
        $this->coloredOutput("Skapade fil: {$target}", "green");
    }

    private function installAllPresets(bool $force, bool $forcePlaceholders, bool $dryRun): void
    {
        $this->coloredOutput("--- Installing ALL scaffold presets ---", "blue");

        $base = rtrim($this->presetsRoot, DIRECTORY_SEPARATOR);
        if (!is_dir($base)) {
            throw new RuntimeException("presetsRoot är ingen katalog: {$base}");
        }

        $rootPresets = $this->discoverTopLevelPresets($base);
        if ($rootPresets === []) {
            $this->coloredOutput("Inga presets hittades under: {$base}", "yellow");
            return;
        }

        $allKnownPresets = $this->discoverAllPresets($base);
        $presets = $this->collectDependencyClosure($base, $rootPresets, $allKnownPresets);

        $this->stats['presetsPlanned'] = count($presets);
        $this->stats['presetsProcessed'] = 0;

        $plan = $this->buildInstallPlan($base, $presets);

        $this->stats['presetsPlanned'] = count($plan);
        $this->stats['presetsProcessed'] = 0;

        if ($dryRun) {
            $this->coloredOutput('[dry-run] Installationsordning:', 'yellow');
            foreach ($plan as $i => $p) {
                $n = $i + 1;
                echo "  {$n}. {$p}\n";
            }
        }

        foreach ($plan as $preset) {
            $this->installPreset($preset, $force, $forcePlaceholders, $dryRun);
            $this->stats['presetsProcessed']++;
        }
    }

    /**
     * @return array<int, string> top-level presets (t.ex. "auth", "admin")
     */
    private function discoverTopLevelPresets(string $base): array
    {
        $entries = scandir($base);
        if ($entries === false) {
            throw new RuntimeException("Kunde inte läsa katalog: {$base}");
        }

        $out = [];

        foreach ($entries as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            if (str_starts_with($name, '.')) {
                continue;
            }

            $full = $base . DIRECTORY_SEPARATOR . $name;
            if (!is_dir($full)) {
                continue;
            }

            $hasPresetMarker = is_file($full . DIRECTORY_SEPARATOR . '.preset')
                || is_file($full . DIRECTORY_SEPARATOR . '.requires.php');

            if ($hasPresetMarker) {
                $out[] = $name; // top-level => inga "/"
            }
        }

        sort($out);
        return array_values(array_unique($out));
    }

    /**
     * Tar en startlista (ofta top-level) och lägger till alla transitive requirements.
     *
     * @param array<int, string> $startPresets
     * @param array<int, string> $allKnownPresets discovery av hela trädet (inkl nested)
     * @return array<int, string>
     */
    private function collectDependencyClosure(string $base, array $startPresets, array $allKnownPresets): array
    {
        $known = array_fill_keys($allKnownPresets, true);

        $result = [];
        $queue = [];

        foreach ($startPresets as $p) {
            $queue[] = $p;
        }

        while ($queue !== []) {
            $p = array_shift($queue);
            if ($p === null || $p === '') {
                continue;
            }

            if (isset($result[$p])) {
                continue;
            }

            if (!isset($known[$p])) {
                throw new RuntimeException("Preset '{$p}' hittades inte under presets-root.");
            }

            $result[$p] = true;

            $dirForRequires = $this->resolvePresetDirForRequires($base, $p);
            $reqs = $this->readRequires($dirForRequires);

            foreach ($reqs as $r) {
                if (!isset($known[$r])) {
                    throw new RuntimeException("Preset '{$p}' kräver '{$r}', men '{$r}' hittades inte under presets-root.");
                }
                $queue[] = $r;
            }
        }

        $out = array_keys($result);
        sort($out);

        return $out;
    }

    /**
     * Rekursivt:
     * - Katalog-presets: måste vara explicit markerade med .preset eller .requires.php i preset-roten.
     * - Single-file presets: *.stub-filer räknas alltid som presets.
     *
     * @return array<int, string> preset-namn med "/" som separator (t.ex. "routes/auth")
     */
    private function discoverAllPresets(string $base): array
    {
        $dirCandidates = [];
        $stubCandidates = [];

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        /** @var SplFileInfo $item */
        foreach ($it as $item) {
            if ($item->isDir()) {
                continue;
            }

            $filename = $item->getFilename();

            // Endast dessa dot-filer är relevanta för discovery
            if (
                $filename !== '.requires.php'
                && $filename !== '.preset'
                && str_starts_with($filename, '.')
            ) {
                continue;
            }

            $dirPath = $item->getPath();
            $dirRel = $this->toLogicalPresetName($base, $dirPath);

            // Explicit marker för katalog-preset
            if ($filename === '.requires.php' || $filename === '.preset') {
                if ($dirRel !== '') {
                    $dirCandidates[$dirRel] = true;
                }
                continue;
            }

            // Single-file preset (*.stub)
            if (str_ends_with($filename, '.stub')) {
                $stem = substr($filename, 0, -5);
                $preset = $dirRel === '' ? $stem : ($dirRel . '/' . $stem);
                $stubCandidates[$preset] = true;
            }
        }

        $dirPresets = array_keys($dirCandidates);
        $stubPresets = array_keys($stubCandidates);

        $dirSet = array_fill_keys($dirPresets, true);

        // Om det finns en katalog med samma preset-namn, föredra katalogen (ignorera stub)
        $out = $dirPresets;
        foreach ($stubPresets as $p) {
            if (!isset($dirSet[$p])) {
                $out[] = $p;
            }
        }

        sort($out);
        return array_values(array_unique($out));
    }

    /**
     * Bygger en deterministisk plan utifrån .requires.php (topologisk sortering).
     *
     * @param array<int, string> $presets
     * @return array<int, string> sorterad installationsordning
     */
    private function buildInstallPlan(string $base, array $presets): array
    {
        $set = array_fill_keys($presets, true);

        /** @var array<string, array<int, string>> $requires */
        $requires = [];

        foreach ($presets as $preset) {
            $dirForRequires = $this->resolvePresetDirForRequires($base, $preset);
            $reqs = $this->readRequires($dirForRequires);

            foreach ($reqs as $r) {
                if (!isset($set[$r])) {
                    throw new RuntimeException("Preset '{$preset}' kräver '{$r}', men '{$r}' hittades inte under presets-root.");
                }
            }

            $requires[$preset] = $reqs;
        }

        // Kahn's algorithm
        $inDegree = array_fill_keys($presets, 0);
        $dependents = array_fill_keys($presets, []);

        foreach ($requires as $p => $reqs) {
            foreach ($reqs as $r) {
                $inDegree[$p]++;

                /** @var array<int, string> $list */
                $list = $dependents[$r];
                $list[] = $p;
                $dependents[$r] = $list;
            }
        }

        $queue = [];
        foreach ($inDegree as $p => $deg) {
            if ($deg === 0) {
                $queue[] = $p;
            }
        }
        sort($queue);

        $out = [];

        while ($queue !== []) {
            $node = array_shift($queue);
            if ($node === null) {
                break;
            }

            $out[] = $node;

            /** @var array<int, string> $deps */
            $deps = $dependents[$node];
            foreach ($deps as $dep) {
                $inDegree[$dep]--;
                if ($inDegree[$dep] === 0) {
                    $queue[] = $dep;
                }
            }

            sort($queue);
        }

        if (count($out) !== count($presets)) {
            // Hitta en tydlig rest-lista för felmeddelande
            $remaining = [];
            foreach ($inDegree as $p => $deg) {
                if ($deg > 0) {
                    $remaining[] = $p;
                }
            }
            sort($remaining);

            throw new RuntimeException(
                "Cykel eller olösliga dependencies upptäckta bland presets: " . implode(', ', $remaining)
            );
        }

        return $out;
    }

    private function resolvePresetDirForRequires(string $base, string $preset): string
    {
        $normalized = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $preset);
        $sourceDir = $base . DIRECTORY_SEPARATOR . $normalized;
        $sourceFile = $sourceDir . '.stub';

        if (is_dir($sourceDir)) {
            return $sourceDir;
        }

        if (is_file($sourceFile)) {
            return dirname($sourceFile);
        }

        // Borde inte ske om discoverAllPresets() är källan, men håll det tydligt.
        throw new RuntimeException("Kunde inte resolva preset för requirements: {$preset}");
    }

    private function toLogicalPresetName(string $base, string $path): string
    {
        $baseNorm = rtrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $base), DIRECTORY_SEPARATOR);
        $pathNorm = rtrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);

        if ($pathNorm === $baseNorm) {
            return '';
        }

        if (!str_starts_with($pathNorm, $baseNorm . DIRECTORY_SEPARATOR)) {
            return '';
        }

        $rel = substr($pathNorm, strlen($baseNorm) + 1);
        $rel = str_replace(DIRECTORY_SEPARATOR, '/', $rel);

        return trim($rel, '/');
    }

    private function resetStats(): void
    {
        $this->stats = [
            'presetsPlanned' => 0,
            'presetsProcessed' => 0,
            'created' => 0,
            'overwritten' => 0,
            'skipped' => 0,
            'dirsCreated' => 0,
            'dirsWouldCreate' => 0,
            'filesWouldCreate' => 0,
            'filesWouldOverwrite' => 0,
        ];
    }

    private function printSummary(bool $dryRun): void
    {
        $this->coloredOutput('--- Sammanfattning ---', 'blue');

        $filesTouchedDry = $this->stats['filesWouldCreate'] + $this->stats['filesWouldOverwrite'] + $this->stats['skipped'];
        $filesTouched = $this->stats['created'] + $this->stats['overwritten'] + $this->stats['skipped'];

        if ($dryRun) {
            echo "Presets: planerade {$this->stats['presetsPlanned']}\n";
            echo "Kataloger: skulle skapa {$this->stats['dirsWouldCreate']}\n";
            echo "Filer: skulle skapa {$this->stats['filesWouldCreate']}, skulle skriva över {$this->stats['filesWouldOverwrite']}, skulle hoppa över {$this->stats['skipped']} (totalt {$filesTouchedDry})\n";
            return;
        }

        echo "Presets: körda {$this->stats['presetsProcessed']} av {$this->stats['presetsPlanned']}\n";
        echo "Kataloger: skapade {$this->stats['dirsCreated']}\n";
        echo "Filer: skapade {$this->stats['created']}, skrev över {$this->stats['overwritten']}, hoppade över {$this->stats['skipped']} (totalt {$filesTouched})\n";
    }

    private function isPlaceholderFile(string $path): bool
    {
        $contents = @file_get_contents($path);
        if (!is_string($contents)) {
            return false;
        }

        return str_contains($contents, 'RADIX_PLACEHOLDER');
    }
}
