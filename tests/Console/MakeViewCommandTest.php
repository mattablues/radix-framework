<?php

declare(strict_types=1);

namespace Radix\Tests\Console;

use PHPUnit\Framework\TestCase;
use Radix\Console\Commands\MakeViewCommand;
use ReflectionClass;
use Throwable;

final class MakeViewCommandTest extends TestCase
{
    private string $tmpRoot;
    private string $viewsDir;
    private string $stubDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'radix_make_view_' . bin2hex(random_bytes(4));
        $this->viewsDir = $this->tmpRoot . DIRECTORY_SEPARATOR . 'views';
        $this->stubDir  = $this->tmpRoot . DIRECTORY_SEPARATOR . 'stubs';

        @mkdir($this->viewsDir, 0o755, true);
        @mkdir($this->stubDir, 0o755, true);

        file_put_contents($this->stubDir . DIRECTORY_SEPARATOR . 'view.stub', "[LAYOUT]\n[TITLE]\n[PAGEID]\n");
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->tmpRoot);
        parent::tearDown();
    }

    public function testInvokeWithoutPathShowsPlainHelpAndDoesNotCreateFiles(): void
    {
        $cmd = new MakeViewCommand($this->viewsDir, $this->stubDir);

        $out = $this->captureOutput(static function () use ($cmd): void {
            /** @var array<int|string,string> $args */
            $args = ['_command' => 'make:view'];
            $cmd->__invoke($args);
        });

        self::assertStringContainsString('Usage: make:view <path>', $out);
        self::assertStringContainsString("Tip: You can always use '--help' for more information.", $out);

        $created = glob($this->viewsDir . DIRECTORY_SEPARATOR . '*') ?: [];
        self::assertSame([], $created, 'Inga filer/kataloger ska skapas när <path> saknas.');
    }

    public function testInvokeWithoutPathShowsMarkdownHelpWhenMdFlagIsProvided(): void
    {
        $cmd = new MakeViewCommand($this->viewsDir, $this->stubDir);

        $out = $this->captureOutput(static function () use ($cmd): void {
            /** @var array<int|string,string> $args */
            $args = ['--md', '_command' => 'make:view'];
            $cmd->__invoke($args);
        });

        self::assertStringContainsString('## Usage', $out);
        self::assertStringContainsString('```bash', $out);
        self::assertStringContainsString('make:view <path>', $out);
        self::assertStringContainsString('## Options', $out);
        self::assertStringContainsString('## Examples', $out);

        $created = glob($this->viewsDir . DIRECTORY_SEPARATOR . '*') ?: [];
        self::assertSame([], $created, 'Inga filer/kataloger ska skapas när <path> saknas (markdown-läge).');
    }

    public function testHelpContainsPathOptionAndFirstExample(): void
    {
        $cmd = new MakeViewCommand($this->viewsDir, $this->stubDir);

        $out = $this->captureOutput(static function () use ($cmd): void {
            /** @var array<int|string,string> $args */
            $args = ['--help', '_command' => 'make:view'];
            $cmd->__invoke($args);
        });

        // Dödar ArrayItemRemoval för "<path>"
        self::assertStringContainsString('<path>', $out);
        self::assertStringContainsString("View path, e.g. 'about/index'", $out);

        // Dödar ArrayItemRemoval för första exemplet
        self::assertStringContainsString('make:view about/index --layout=main', $out);
    }

    public function testCreatesViewWhenFirstArgIsAssocCommandMetaThenRealPath(): void
    {
        $viewsBase = rtrim($this->viewsDir, '/\\') . '/';
        $stubsBase = rtrim($this->stubDir, '/\\') . '/';

        $cmd = new MakeViewCommand($viewsBase, $stubsBase);

        $out = $this->captureOutput(static function () use ($cmd): void {
            /** @var array<int|string,string> $args */
            $args = [
                '_command' => 'make:view', // assoc ska ignoreras
                0 => 'about/index',        // riktig path
            ];
            $cmd->__invoke($args);
        });

        // Dödar UnwrapRtrim (båda): om rtrim tas bort får du ofta "//" i output-path
        self::assertStringNotContainsString('//', $out, 'Paths ska inte innehålla dubbel-slash (rtrim måste användas).');

        $expected = rtrim($this->viewsDir, '/\\') . DIRECTORY_SEPARATOR . 'about' . DIRECTORY_SEPARATOR . 'index.ratio.php';
        self::assertFileExists($expected);

        // Dödar Foreach_/Identical: om loop ersätts med [] eller villkor muteras skapas ingen fil
        self::assertStringContainsString('View created:', $out);
    }

    public function testSkipsFlagLikeArgAndUsesNextPath(): void
    {
        $cmd = new MakeViewCommand($this->viewsDir, $this->stubDir);

        $out = $this->captureOutput(static function () use ($cmd): void {
            /** @var array<int|string,string> $args */
            $args = [
                0 => '-x',          // ska ignoreras
                1 => 'docs/guide',  // ska användas
            ];
            $cmd->__invoke($args);
        });

        // Dödar Continue_->break på "$arg[0] === '-'"-grenen (om den breakar vid -x hittas aldrig docs/guide)
        $expected = rtrim($this->viewsDir, '/\\') . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'guide.ratio.php';
        self::assertFileExists($expected);
        self::assertStringContainsString('View created:', $out);
    }

    public function testPathWithSecondCharDashIsAllowedBecauseOnlyLeadingDashIsAFlag(): void
    {
        $cmd = new MakeViewCommand($this->viewsDir, $this->stubDir);

        $out = $this->captureOutput(static function () use ($cmd): void {
            /** @var array<int|string,string> $args */
            $args = [
                0 => 'a-', // argv[0] = 'a' (inte flagga), argv[1] = '-' (ska inte spela roll)
            ];
            $cmd->__invoke($args);
        });

        // Dödar IncrementInteger-mutanten ($arg[1] === '-') som annars skulle felaktigt ignorera 'a-'
        $expected = rtrim($this->viewsDir, '/\\') . DIRECTORY_SEPARATOR . 'a-.ratio.php';
        self::assertFileExists($expected);
        self::assertStringContainsString('View created:', $out);
    }

    public function testCreatedFileContainsUtf8TitleCasedByMbConvertCaseNotUcwords(): void
    {
        $cmd = new MakeViewCommand($this->viewsDir, $this->stubDir);

        $out = $this->captureOutput(static function () use ($cmd): void {
            /** @var array<int|string,string> $args */
            $args = [
                0 => 'docs/ångström',
            ];
            $cmd->__invoke($args);
        });

        self::assertStringContainsString('View created:', $out);

        $expectedFile = rtrim($this->viewsDir, '/\\') . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'ångström.ratio.php';
        self::assertFileExists($expectedFile);

        $lines = $this->readCreatedFileLines($expectedFile);

        self::assertSame('main', $lines[0]);

        // Här är det viktiga: INGA "?? null" (PHPStan säger att offset finns)
        self::assertSame('Ångström', $lines[1]);
        self::assertNotSame('ångström', $lines[1]);
    }

    public function testCreatedFileContainsPageIdLowercasedAndWithSlashesAndSpacesConvertedToDashes(): void
    {
        $cmd = new MakeViewCommand($this->viewsDir, $this->stubDir);

        $out = $this->captureOutput(static function () use ($cmd): void {
            /** @var array<int|string,string> $args */
            $args = [
                0 => 'Docs/Guide Intro',
            ];
            $cmd->__invoke($args);
        });

        self::assertStringContainsString('View created:', $out);

        $expectedFile = rtrim($this->viewsDir, '/\\') . DIRECTORY_SEPARATOR . 'Docs' . DIRECTORY_SEPARATOR . 'Guide Intro.ratio.php';
        self::assertFileExists($expectedFile);

        $content = (string) file_get_contents($expectedFile);

        // Dödar:
        // - ArrayItemRemoval (missar '/')
        // - ArrayItemRemoval (missar mellanslag)
        // - UnwrapStrReplace (ingen replace alls)
        // - UnwrapStrToLower (ingen strtolower)
        self::assertStringContainsString("docs-guide-intro\n", $content);
        self::assertStringNotContainsString("Docs/Guide Intro\n", $content);
        self::assertStringNotContainsString("Docs-Guide Intro\n", $content);
        self::assertStringNotContainsString("docs/guide intro\n", $content);
    }

    public function testConstructorTrimsTrailingSlashOnBothBasePaths(): void
    {
        $viewsBase = rtrim($this->viewsDir, "/\\") . '/';
        $stubsBase = rtrim($this->stubDir, "/\\") . '/';

        $cmd = new MakeViewCommand($viewsBase, $stubsBase);

        $ref = new ReflectionClass($cmd);

        $pViews = $ref->getProperty('viewsBasePath');
        $pViews->setAccessible(true);
        $viewsValue = $pViews->getValue($cmd);

        $pTpl = $ref->getProperty('templatePath');
        $pTpl->setAccessible(true);
        $tplValue = $pTpl->getValue($cmd);

        self::assertIsString($viewsValue);
        self::assertIsString($tplValue);

        self::assertSame(rtrim($viewsBase, '/'), $viewsValue, 'viewsBasePath ska rtrimmas på "/".');
        self::assertSame(rtrim($stubsBase, '/'), $tplValue, 'templatePath ska rtrimmas på "/".');

        self::assertFalse(str_ends_with($viewsValue, '/'));
        self::assertFalse(str_ends_with($tplValue, '/'));
    }

    public function testHelpFlagMustReturnEarlyAndNotCreateFileEvenIfPathProvided(): void
    {
        $cmd = new MakeViewCommand($this->viewsDir, $this->stubDir);

        $out = $this->captureOutput(static function () use ($cmd): void {
            /** @var array<int|string,string> $args */
            $args = ['--help', 'about/index'];
            $cmd->__invoke($args);
        });

        self::assertStringContainsString('Usage: make:view <path>', $out);

        $shouldNotExist = rtrim($this->viewsDir, '/\\') . DIRECTORY_SEPARATOR . 'about' . DIRECTORY_SEPARATOR . 'index.ratio.php';
        self::assertFileDoesNotExist($shouldNotExist, 'När --help anges ska ingen fil skapas (return måste ske).');
    }

    public function testFirstNonFlagPositionalArgIsUsedAsViewPath(): void
    {
        $cmd = new MakeViewCommand($this->viewsDir, $this->stubDir);

        $this->captureOutput(static function () use ($cmd): void {
            /** @var array<int|string,string> $args */
            $args = [
                0 => 'first/path',
                1 => 'second/path',
            ];
            $cmd->__invoke($args);
        });

        $first = rtrim($this->viewsDir, '/\\') . DIRECTORY_SEPARATOR . 'first' . DIRECTORY_SEPARATOR . 'path.ratio.php';
        $second = rtrim($this->viewsDir, '/\\') . DIRECTORY_SEPARATOR . 'second' . DIRECTORY_SEPARATOR . 'path.ratio.php';

        // Dödar Break_->continue: annars skulle den råka välja "second/path"
        self::assertFileExists($first);
        self::assertFileDoesNotExist($second);
    }

    public function testParseOptionsAppliesLayoutAndExtAndKeepsBothOptions(): void
    {
        $cmd = new MakeViewCommand($this->viewsDir, $this->stubDir);

        $out = $this->captureOutput(static function () use ($cmd): void {
            /** @var array<int|string,string> $args */
            $args = [
                0 => 'admin/dashboard',
                1 => '--layout=admin',
                2 => '--ext=php',
            ];
            $cmd->__invoke($args);
        });

        self::assertStringContainsString('View created:', $out);

        // Dödar Coalesce layout/ext + parseOptions Foreach/IfNegation + ArrayOneItem
        $expectedFile = rtrim($this->viewsDir, '/\\') . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'dashboard.php';
        self::assertFileExists($expectedFile);

        $lines = $this->readCreatedFileLines($expectedFile);

        // Stubformat: [LAYOUT]\n[TITLE]\n[PAGEID]\n
        self::assertSame('admin', $lines[0] ?? null, 'Layout ska komma från --layout=admin (inte alltid main).');
        self::assertSame('Dashboard', $lines[1] ?? null);
        self::assertSame('admin-dashboard', $lines[2] ?? null);
    }

    public function testGeneratePathDeriveTitleAndTemplateReplacementAreApplied(): void
    {
        $cmd = new MakeViewCommand($this->viewsDir, $this->stubDir);

        $out = $this->captureOutput(static function () use ($cmd): void {
            /** @var array<int|string,string> $args */
            $args = [
                0 => 'docs/dash-board_test',
            ];
            $cmd->__invoke($args);
        });

        self::assertStringContainsString('View created:', $out);

        $expectedFile = rtrim($this->viewsDir, '/\\') . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'dash-board_test.ratio.php';
        self::assertFileExists($expectedFile);

        $content = (string) file_get_contents($expectedFile);

        // Dödar UnwrapStrReplace på hela content (annars skulle [TITLE] osv ligga kvar)
        self::assertStringNotContainsString('[LAYOUT]', $content);
        self::assertStringNotContainsString('[TITLE]', $content);
        self::assertStringNotContainsString('[PAGEID]', $content);

        $lines = $this->readCreatedFileLines($expectedFile);

        // Dödar ArrayItemRemoval/UnwrapStrReplace i deriveTitle (måste ersätta '-' och '_' med space)
        self::assertSame('Dash Board Test', $lines[1] ?? null);

        // Dödar ArrayItemRemoval/UnwrapStrReplace i derivePageId + UnwrapStrToLower
        self::assertSame('docs-dash-board_test', $lines[2] ?? null, 'PageId ska byta "/" -> "-" och lowercasa.');
    }

    public function testDirModeConstantIsExactly0755(): void
    {
        $ref = new ReflectionClass(MakeViewCommand::class);
        $const = $ref->getReflectionConstant('DIR_MODE');

        self::assertNotFalse($const, 'MakeViewCommand::DIR_MODE måste finnas.');
        self::assertSame(0o755, $const->getValue(), 'DIR_MODE ska vara exakt 0755.');
    }

    public function testExtOptionKeepsValueAfterFirstEqualsSign(): void
    {
        $cmd = new MakeViewCommand($this->viewsDir, $this->stubDir);

        $this->captureOutput(static function () use ($cmd): void {
            /** @var array<int|string,string> $args */
            $args = [
                0 => 'docs/ext_eq_test',
                1 => '--ext=ratio=php',
            ];
            $cmd->__invoke($args);
        });

        // explode(..., 2) => ext = "ratio=php"
        $expectedFile = rtrim($this->viewsDir, '/\\')
            . DIRECTORY_SEPARATOR . 'docs'
            . DIRECTORY_SEPARATOR . 'ext_eq_test.ratio=php';

        self::assertFileExists(
            $expectedFile,
            'parseOptions() måste använda explode-limit=2 så att allt efter första "=" behålls i värdet.'
        );
    }

    public function testGeneratePathConvertsBackslashesToForwardSlashesSoPageIdDoesNotContainBackslash(): void
    {
        $cmd = new MakeViewCommand($this->viewsDir, $this->stubDir);

        $this->captureOutput(static function () use ($cmd): void {
            /** @var array<int|string,string> $args */
            $args = [
                0 => "Docs\\Guide Intro",
            ];
            $cmd->__invoke($args);
        });

        $expectedFile = rtrim($this->viewsDir, '/\\')
            . DIRECTORY_SEPARATOR . 'Docs'
            . DIRECTORY_SEPARATOR . 'Guide Intro.ratio.php';

        self::assertFileExists($expectedFile);

        $lines = $this->readCreatedFileLines($expectedFile);

        self::assertSame('docs-guide-intro', $lines[2], 'Backslash måste normaliseras bort i generatePath().');
        self::assertStringNotContainsString('\\', $lines[2], 'PageId får inte innehålla backslash.');
    }

    public function testHelpFlagInNonZeroIndexMustStillBeDetectedBecauseArgsAreReindexed(): void
    {
        $cmd = new MakeViewCommand($this->viewsDir, $this->stubDir);

        $out = $this->captureOutput(static function () use ($cmd): void {
            /** @var array<int|string,string> $args */
            $args = [
                2 => '--help',
                3 => 'about/index',
            ];
            $cmd->__invoke($args);
        });

        // Original: MakeViewCommand bygger $argv med array_values(...) => '--help' hamnar på index 0 => help visas
        // Mutant (UnwrapArrayValues): $argv behåller keys 2/3 => BaseCommand missar '--help' => fil skapas => test failar
        self::assertStringContainsString('Usage: make:view <path>', $out);

        $shouldNotExist = rtrim($this->viewsDir, '/\\') . DIRECTORY_SEPARATOR . 'about' . DIRECTORY_SEPARATOR . 'index.ratio.php';
        self::assertFileDoesNotExist($shouldNotExist);
    }

    public function testAssocMetaValueMustNotBeAbleToTriggerHelpFlag(): void
    {
        $cmd = new MakeViewCommand($this->viewsDir, $this->stubDir);

        $out = $this->captureOutput(static function () use ($cmd): void {
            /** @var array<int|string,string> $args */
            $args = [
                '_command' => '--help',   // assoc ska ignoreras i $argv
                0 => 'about/index',
            ];
            $cmd->__invoke($args);
        });

        // Original: array_filter(... is_int key ...) gör att assoc '--help' INTE hamnar i $argv => help triggas inte => fil skapas
        // Mutant (UnwrapArrayFilter): $argv = array_values($args) tar med assoc '--help' => help triggas => fil skapas inte => test failar
        self::assertStringContainsString('View created:', $out);

        $expected = rtrim($this->viewsDir, '/\\') . DIRECTORY_SEPARATOR . 'about' . DIRECTORY_SEPARATOR . 'index.ratio.php';
        self::assertFileExists($expected);
    }

    /**
     * @return array<int,string>
     */
    private function readCreatedFileLines(string $file): array
    {
        $content = (string) file_get_contents($file);
        $lines = preg_split("/\r\n|\n|\r/", $content);
        if (!is_array($lines)) {
            self::fail('Kunde inte splitta filinnehållet i rader.');
        }

        // Säkerställ att stub-formatet finns: [LAYOUT], [TITLE], [PAGEID]
        self::assertGreaterThanOrEqual(3, count($lines), 'Filen ska innehålla minst 3 rader (layout/title/pageId).');

        /** @var array<int,string> $lines */
        return $lines;
    }

    /**
     * @param callable():void $fn
     */
    private function captureOutput(callable $fn): string
    {
        $levelBefore = ob_get_level();

        ob_start();
        $levelAfterStart = ob_get_level();

        try {
            $fn();

            if (ob_get_level() < $levelAfterStart) {
                $this->fail('Tested code stängde output-buffer som skapades av testet.');
            }

            $out = ob_get_clean();

            self::assertSame(
                $levelBefore,
                ob_get_level(),
                'Output-buffer-nivån efter captureOutput() ska vara samma som innan.'
            );

            return is_string($out) ? $out : '';
        } catch (Throwable $e) {
            if (ob_get_level() >= $levelAfterStart) {
                ob_end_clean();
            }

            self::assertSame(
                $levelBefore,
                ob_get_level(),
                'Output-buffer-nivån efter exception i captureOutput() ska vara samma som innan.'
            );

            throw $e;
        }
    }

    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $p = $dir . DIRECTORY_SEPARATOR . $file;
            if (is_dir($p)) {
                $this->deleteDirectory($p);
            } else {
                @unlink($p);
            }
        }
        @rmdir($dir);
    }
}
