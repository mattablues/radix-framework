<?php

declare(strict_types=1);

namespace Radix\Tests\Support;

use PHPUnit\Framework\TestCase;
use Radix\Support\Logger;
use ReflectionClass;
use RuntimeException;
use Throwable;

final class TestableLogger extends Logger
{
    public function __construct() {}

    /**
     * @param array<string,mixed> $context
     */
    public function interpolatePublic(string $message, array $context): string
    {
        // Anropa den skyddade interpolate()-metoden i bas-klassen
        $ref = new ReflectionClass(Logger::class);
        $method = $ref->getMethod('interpolate');
        $method->setAccessible(true);

        /** @var string $result */
        $result = $method->invoke($this, $message, $context);

        return $result;
    }
}

final class LoggerTest extends TestCase
{
    private string $tmpRoot;
    private string $logsDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/radix_logger_test_' . bin2hex(random_bytes(4));
        $this->logsDir = $this->tmpRoot . '/storage/logs';
        @mkdir($this->logsDir, 0o755, true);

        if (!defined('ROOT_PATH')) {
            define('ROOT_PATH', $this->tmpRoot);
        }
    }

    protected function tearDown(): void
    {
        $this->deleteDir($this->tmpRoot);
        parent::tearDown();
    }

    public function testWritesToTodaysFile(): void
    {
        $logger = new Logger('unittest', $this->logsDir);
        $logger->info('hello {name}', ['name' => 'world']);

        $file = $this->logsDir . '/unittest-' . date('Y-m-d') . '.log';
        $this->assertFileExists($file);
        $this->assertStringContainsString('unittest.INFO hello world', file_get_contents($file) ?: '');
    }

    private function invokeCleanup(Logger $logger): void
    {
        $ref = new ReflectionClass(Logger::class);

        // Nollställ dag-vakten så cleanup körs deterministiskt i testet
        $prop = $ref->getProperty('lastCleanupDay');
        $prop->setAccessible(true);
        $prop->setValue($logger, null);

        $m = $ref->getMethod('cleanupOldLogs');
        $m->setAccessible(true);
        $m->invoke($logger);
    }

    public function testCleanupOldLogsUsesExactDaySecondsAndKeepsBoundaryFile(): void
    {
        $dir = $this->logsDir; // antar att ni redan har en temp logsDir i testet

        $logger = new Logger(
            channel: 'cleanup',
            baseDir: $dir,
            maxBytes: 1024 * 1024,
            retentionDays: 1
        );

        $now = time();
        $threshold = $now - 86400;

        $fileBoundary = $dir . DIRECTORY_SEPARATOR . 'boundary.log';
        $fileOld      = $dir . DIRECTORY_SEPARATOR . 'old.log';

        $this->assertNotFalse(file_put_contents($fileBoundary, "b\n"));
        $this->assertNotFalse(file_put_contents($fileOld, "o\n"));

        // mtime exakt på gränsen (ska INTE raderas med <)
        $this->assertTrue(@touch($fileBoundary, $threshold));
        // mtime 1 sekund äldre än gränsen (ska raderas)
        $this->assertTrue(@touch($fileOld, $threshold - 1));

        $this->invokeCleanup($logger);

        $this->assertFileExists(
            $fileBoundary,
            'Filen på exakt threshold ska INTE raderas (dödar <=-mutanten och 86399-mutanter).'
        );
        $this->assertFileDoesNotExist(
            $fileOld,
            'Filen som är äldre än threshold ska raderas (dödar 86401-mutanter).'
        );
    }

    public function testInterpolateReplacesPlaceholdersExactly(): void
    {
        $logger = new TestableLogger();

        $msg = $logger->interpolatePublic(
            'Hello {name}, id={id}, opt={opt}',
            [
                'name' => 'Alice',
                'id'   => 123,
                'opt'  => null,
                'ignore' => ['x' => 1],
            ]
        );

        $this->assertSame('Hello Alice, id=123, opt=', $msg);
    }

    public function testRotatesWhenMaxBytesExceeded(): void
    {
        $smallMax = 64; // tvinga rotation snabbt
        $logger = new Logger('rotate', $this->logsDir, $smallMax);

        // Skriv tills basfilen måste rotera
        for ($i = 0; $i < 50; $i++) {
            $logger->info(str_repeat('x', 40));
        }

        $base = $this->logsDir . '/rotate-' . date('Y-m-d') . '.log';
        $r1 = $base . '.1';
        $r2 = $base . '.2';

        $this->assertTrue($this->anyExisting([$base, $r1, $r2]), 'Expected at least one rotated file to exist');
        // Verifiera att inga filer överstiger gränsen med stor marginal (lite overhead för metadata)
        foreach (glob($this->logsDir . '/rotate-' . date('Y-m-d') . '.log*') ?: [] as $f) {
            $size = filesize($f) ?: 0;
            $this->assertLessThan($smallMax + 256, $size, 'Rotated file unexpectedly large: ' . $f);
        }
    }

    public function testRetentionRemovesOldFiles(): void
    {
        $retentionDays = 1;

        // Skapa en artificiellt gammal fil (2 dagar gammal) FÖRE logger initieras
        $oldFile = $this->logsDir . '/retention-' . date('Y-m-d', time() - 2 * 86400) . '.log';
        file_put_contents($oldFile, 'old');
        @touch($oldFile, time() - 2 * 86400);

        // Initiera logger EFTER att den gamla filen finns så cleanup ser den
        $logger = new Logger('retention', $this->logsDir, 1024 * 1024, $retentionDays);

        // Trigger cleanup via write
        $logger->info('trigger cleanup');

        $this->assertFileDoesNotExist($oldFile, 'Old log should be deleted by retention');
    }

    public function testRetentionDoesNotDeleteModeratelyNewFilesWithLongRetention(): void
    {
        // Retention 365 dagar, loggfil ~2 dagar gammal ska INTE raderas.
        $retentionDays = 365;

        $file = $this->logsDir . '/keep-' . date('Y-m-d', time() - 2 * 86400) . '.log';
        file_put_contents($file, 'keep');
        @touch($file, time() - 2 * 86400);

        $this->assertFileExists($file, 'Testloggfilen ska finnas innan cleanup');

        // Initiera logger EFTER att filen finns
        $logger = new Logger('keep', $this->logsDir, 1024 * 1024, $retentionDays);

        // Trigger cleanup via write
        $logger->info('trigger cleanup');

        // Med korrekt implementation (threshold = now - 365d) ska filen behållas.
        $this->assertFileExists($file, 'Loggfilen ska inte raderas vid lång retention.');
    }

    public function testRetentionOnlyRunsOncePerDayPerLoggerInstance(): void
    {
        $retentionDays = 1;

        // Skapa logger
        $logger = new Logger('daily', $this->logsDir, 1024 * 1024, $retentionDays);

        // Första körningen: gammal fil som ska rensas bort
        $old1 = $this->logsDir . '/daily-old1.log';
        file_put_contents($old1, 'old1');
        @touch($old1, time() - 2 * 86400); // 2 dagar gammal

        $this->assertFileExists($old1, 'old1 ska finnas innan första cleanup');

        $logger->info('first run');

        $this->assertFileDoesNotExist($old1, 'old1 ska raderas vid första cleanup');

        // Andra körningen samma dag: ny gammal fil ska INTE påverkas av cleanup
        $old2 = $this->logsDir . '/daily-old2.log';
        file_put_contents($old2, 'old2');
        @touch($old2, time() - 2 * 86400);

        $this->assertFileExists($old2, 'old2 ska finnas innan andra körningen');

        $logger->info('second run');

        // Med korrekt implementation (early return när lastCleanupDay === idag) ska old2 ligga kvar.
        $this->assertFileExists(
            $old2,
            'old2 ska INTE raderas av cleanup som redan körts för denna logger-instans idag'
        );
    }

    public function testContextJsonDoesNotEscapeSlashesOrUnicode(): void
    {
        $logger = new Logger('ctxjson', $this->logsDir);

        $logger->info('msg', [
            'meta' => ['url' => 'http://example.com/åäö'],
        ]);

        $file = $this->logsDir . '/ctxjson-' . date('Y-m-d') . '.log';
        $this->assertFileExists($file);

        $content = (string) file_get_contents($file);

        // Slashes ska inte vara escapade
        $this->assertStringNotContainsString('\/', $content, 'Slashes i context JSON ska inte vara escapade');

        // Unicode ska inte vara escapad (inga \uXXXX för å/ä/ö)
        $lower = strtolower($content);
        $this->assertStringNotContainsString('\u00e5', $lower);
        $this->assertStringNotContainsString('\u00e4', $lower);
        $this->assertStringNotContainsString('\u00f6', $lower);
    }

    public function testContextToStringIncludesOnlyNonScalarValues(): void
    {
        $logger = new Logger('ctx1', $this->logsDir);

        // 'a' är skalar, 'meta' är icke-skalär
        $logger->info('msg', [
            'a'    => 1,
            'meta' => ['x' => 1],
        ]);

        $file = $this->logsDir . '/ctx1-' . date('Y-m-d') . '.log';
        $this->assertFileExists($file);

        $content = (string) file_get_contents($file);

        // Icke-skalär 'meta' ska serialiseras till JSON
        $this->assertStringContainsString('"meta":{"x":1}', $content);

        // Skalar 'a' ska inte finnas i JSON-delen
        $this->assertStringNotContainsString('"a":1', $content);
    }

    public function testContextToStringOmitsScalarOnlyContext(): void
    {
        $logger = new Logger('ctx2', $this->logsDir);

        $logger->info('hello {name}', [
            'name' => 'world',
            'id'   => 123,
        ]);

        $file = $this->logsDir . '/ctx2-' . date('Y-m-d') . '.log';
        $this->assertFileExists($file);

        $content = (string) file_get_contents($file);

        // Skalära context-värden ska INTE dyka upp som JSON
        $this->assertStringNotContainsString('"name"', $content);
        $this->assertStringNotContainsString('"id"', $content);

        // Och vi vill absolut inte ha en tom JSON-array/string (som '[]')
        $this->assertStringNotContainsString('[]', $content);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function interpolateViaReflection(Logger $logger, string $message, array $context): string
    {
        $ref = new ReflectionClass(Logger::class);
        $m = $ref->getMethod('interpolate');
        $m->setAccessible(true);

        /** @var string $out */
        $out = $m->invoke($logger, $message, $context);
        return $out;
    }

    public function testInterpolateCastsNullToEmptyString(): void
    {
        $logger = new Logger('interp', $this->logsDir);

        try {
            $out = $this->interpolateViaReflection($logger, 'X{n}Y', ['n' => null]);
        } catch (Throwable $e) {
            $this->fail('interpolate() ska inte kasta när context innehåller null. Fick: ' . $e::class);
        }

        $this->assertSame('XY', $out, 'null ska interpoleras som tom sträng (dödar CastString-mutanten).');
    }

    private function resolveWritableFileViaReflection(Logger $logger, string $fileBase): string
    {
        $ref = new ReflectionClass(Logger::class);
        $m = $ref->getMethod('resolveWritableFile');
        $m->setAccessible(true);

        /** @var string $out */
        $out = $m->invoke($logger, $fileBase);
        return $out;
    }

    private function writeBytes(string $path, int $bytes): void
    {
        $this->assertNotFalse(file_put_contents($path, str_repeat('x', max(0, $bytes))));
        $this->assertFileExists($path);
        $size = filesize($path);
        $this->assertIsInt($size);
        $this->assertGreaterThanOrEqual(0, $size);
    }

    public function testResolveWritableFileIncrementsSuffixAndDoesNotUseZeroOrNegative(): void
    {
        $maxBytes = 10;
        $logger = new Logger('roll', $this->logsDir, $maxBytes);

        $base = $this->logsDir . DIRECTORY_SEPARATOR . 'roll-' . date('Y-m-d') . '.log';

        // Basfil + .1 är "fulla", så nästa lediga ska bli .2
        $this->writeBytes($base, $maxBytes + 1);
        $this->writeBytes($base . '.1', $maxBytes + 1);

        $picked = $this->resolveWritableFileViaReflection($logger, $base);

        $this->assertSame(
            $base . '.2',
            $picked,
            'När basfil och .1 är fulla ska nästa fil vara .2 (dödar $i++ -> $i-- mutanten som annars ger .0).'
        );
    }

    public function testResolveWritableFileSafetyBrakeTriggersOnlyAfter1000AndReturns1001(): void
    {
        $maxBytes = 1; // gör "full" lätt
        $logger = new Logger('brake', $this->logsDir, $maxBytes);

        $base = $this->logsDir . DIRECTORY_SEPARATOR . 'brake-' . date('Y-m-d') . '.log';

        // Basfilen måste vara full så vi går in i rullnings-loopen
        $this->writeBytes($base, $maxBytes + 1);

        // Skapa .1 ... .1000 som "fulla"
        for ($i = 1; $i <= 1000; $i++) {
            $this->writeBytes($base . '.' . $i, $maxBytes + 1);
        }

        $picked = $this->resolveWritableFileViaReflection($logger, $base);

        $this->assertSame(
            $base . '.1001',
            $picked,
            'Säkerhetsbromsen ska slå först när i > 1000, alltså returnera .1001 här (dödar >1000 -> >=1000 mutanten).'
        );
    }

    public function testResolveWritableFileDoesNotTreatExactMaxBytesAsWritable(): void
    {
        $maxBytes = 10;
        $logger = new Logger('edge', $this->logsDir, $maxBytes);

        $base = $this->logsDir . DIRECTORY_SEPARATOR . 'edge-' . date('Y-m-d') . '.log';

        // Basfil full => rotation
        $this->writeBytes($base, $maxBytes + 1);

        // Kandidat .1 är exakt på gränsen => INTE skrivbar (måste vara < maxBytes)
        $this->writeBytes($base . '.1', $maxBytes);

        $picked = $this->resolveWritableFileViaReflection($logger, $base);

        $this->assertSame(
            $base . '.2',
            $picked,
            'Fil med exakt maxBytes ska inte anses skrivbar; vi ska rotera vidare till .2 (dödar < -> <= mutanten).'
        );
    }

    public function testResolveWritableFileReturnsExistingCandidateThatIsBelowLimit(): void
    {
        $maxBytes = 10;
        $logger = new Logger('or', $this->logsDir, $maxBytes);

        $base = $this->logsDir . DIRECTORY_SEPARATOR . 'or-' . date('Y-m-d') . '.log';

        // Basfil full => rotation
        $this->writeBytes($base, $maxBytes + 1);

        // .1 finns och är under gränsen => ska väljas direkt
        $this->writeBytes($base . '.1', 0);

        $picked = $this->resolveWritableFileViaReflection($logger, $base);

        $this->assertSame(
            $base . '.1',
            $picked,
            'När .1 existerar och är under gränsen ska den väljas (dödar || -> &&-mutanten i villkoret).'
        );
    }

    public function testInterpolateCastsValuesAndDoesNotTriggerWarnings(): void
    {
        $logger = new Logger('interp2', $this->logsDir);

        $oldLevel = error_reporting(E_ALL);

        set_error_handler(
            static function (int $severity, string $message): bool {
                throw new RuntimeException("PHP warning/notice: {$message}", $severity);
            }
        );

        try {
            $out = $this->interpolateViaReflection(
                $logger,
                'A{n}B{i}C',
                ['n' => null, 'i' => 123]
            );
        } finally {
            restore_error_handler();
            error_reporting($oldLevel);
        }

        $this->assertSame('AB123C', $out, 'interpolate() måste casta null till "" och int till sträng (dödar CastString-mutanten).');
    }

    public function testContextToStringDoesNotIncludeInterpolatedScalarEvenIfValueContainsKeyPlusBrace(): void
    {
        $logger = new Logger('ctx31', $this->logsDir);

        $logger->info('m {x}', ['x' => 'x}']);

        $file = $this->logsDir . '/ctx31-' . date('Y-m-d') . '.log';
        $this->assertFileExists($file);

        $content = (string) file_get_contents($file);

        $this->assertStringNotContainsString('"x":"x}"', $content, 'Interpolerad scalar får inte hamna i JSON-context (dödar concat-operand removal som söker efter "x}").');
    }

    public function testContextToStringDoesNotIncludeInterpolatedScalarEvenIfValueContainsOpeningBracePlusKey(): void
    {
        $logger = new Logger('ctx32', $this->logsDir);

        $logger->info('m {y}', ['y' => '{y']);

        $file = $this->logsDir . '/ctx32-' . date('Y-m-d') . '.log';
        $this->assertFileExists($file);

        $content = (string) file_get_contents($file);

        $this->assertStringNotContainsString('"y":"{y"', $content, 'Interpolerad scalar får inte hamna i JSON-context (dödar concat-operand removal som söker efter "{y").');
    }

    private function getPrivateInt(Logger $logger, string $propName): int
    {
        $ref = new ReflectionClass(Logger::class);
        $prop = $ref->getProperty($propName);
        $prop->setAccessible(true);

        $v = $prop->getValue($logger);
        $this->assertIsInt($v);

        return $v;
    }

    public function testConstructorDefaultsAreExactForMaxBytesAndRetentionDays(): void
    {
        // Viktigt: skicka null för att defaults ska användas
        $logger = new Logger(channel: 'defaults', baseDir: $this->logsDir, maxBytes: null, retentionDays: null);

        $this->assertSame(
            10 * 1024 * 1024,
            $this->getPrivateInt($logger, 'maxBytes'),
            'Default maxBytes ska vara exakt 10 MiB (dödar multiplications-mutanten).'
        );

        $this->assertSame(
            14,
            $this->getPrivateInt($logger, 'retentionDays'),
            'Default retentionDays ska vara exakt 14 (dödar 13/15-mutanterna).'
        );
    }

    public function testResolveWritableFileReturnsBaseWhenBaseIsBelowLimit(): void
    {
        $maxBytes = 10;
        $logger = new Logger('basebelow', $this->logsDir, $maxBytes);

        $base = $this->logsDir . DIRECTORY_SEPARATOR . 'basebelow-' . date('Y-m-d') . '.log';

        // Basfil finns och är under gränsen => ska användas direkt
        $this->writeBytes($base, 0);

        $picked = $this->resolveWritableFileViaReflection($logger, $base);

        $this->assertSame(
            $base,
            $picked,
            'När basfilen är under gränsen ska den väljas (dödar || -> &&-mutanten i basfil-checken).'
        );
    }

    public function testResolveWritableFileDoesNotTreatBaseAtExactMaxBytesAsWritable(): void
    {
        $maxBytes = 10;
        $logger = new Logger('baseedge', $this->logsDir, $maxBytes);

        $base = $this->logsDir . DIRECTORY_SEPARATOR . 'baseedge-' . date('Y-m-d') . '.log';

        // Basfil exakt på gränsen => ska INTE anses skrivbar => rotera till .1
        $this->writeBytes($base, $maxBytes);

        $picked = $this->resolveWritableFileViaReflection($logger, $base);

        $this->assertSame(
            $base . '.1',
            $picked,
            'Basfil med exakt maxBytes ska rotera (dödar < -> <=-mutanten i basfil-checken).'
        );
    }

    public function testInterpolateCastsNullAndIntAndDoesNotTriggerWarnings(): void
    {
        $logger = new Logger('interp_cast', $this->logsDir);

        $oldLevel = error_reporting(E_ALL);

        set_error_handler(
            static function (int $severity, string $message): bool {
                throw new RuntimeException("PHP warning/notice: {$message}", $severity);
            }
        );

        try {
            $out = $this->interpolateViaReflection(
                $logger,
                'A{n}B{i}C',
                ['n' => null, 'i' => 123]
            );
        } finally {
            restore_error_handler();
            error_reporting($oldLevel);
        }

        $this->assertSame(
            'AB123C',
            $out,
            'interpolate() måste casta null till "" och int till sträng (dödar CastString-mutanten).'
        );
    }

    private function getPrivateString(Logger $logger, string $propName): string
    {
        $ref = new ReflectionClass(Logger::class);
        $prop = $ref->getProperty($propName);
        $prop->setAccessible(true);

        $v = $prop->getValue($logger);
        $this->assertIsString($v);

        return $v;
    }

    private function normalizePath(string $p): string
    {
        return rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $p), "/\\");
    }

    public function testConstructorWithNullBaseDirUsesRootPathStorageLogs(): void
    {
        if (!defined('ROOT_PATH')) {
            $this->markTestSkipped('ROOT_PATH är inte definierad av test-bootstrap.');
        }

        /** @var string $root */
        $root = (string) ROOT_PATH;

        $expected = $this->normalizePath(
            rtrim($root, "/\\") . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs'
        );

        $logger = new Logger(channel: 'defaults-base', baseDir: null);

        $actualDir = $this->normalizePath($this->getPrivateString($logger, 'dir'));

        $this->assertSame(
            $expected,
            $actualDir,
            'När baseDir=null ska Logger använda ROOT_PATH/storage/logs (dödar concat/operand-removal-mutanterna).'
        );

        $this->assertDirectoryExists($expected);
    }

    public function testConstructorCreatesBaseDirectoryWhenMissing(): void
    {
        $missing = rtrim(sys_get_temp_dir(), "/\\")
            . DIRECTORY_SEPARATOR
            . 'radix_logger_ctor_' . bin2hex(random_bytes(4))
            . DIRECTORY_SEPARATOR
            . 'storage'
            . DIRECTORY_SEPARATOR
            . 'logs';

        // Säkerställ att den inte finns
        $this->assertDirectoryDoesNotExist($missing);

        $logger = new Logger(channel: 'mk', baseDir: $missing);

        $actualDir = $this->normalizePath($this->getPrivateString($logger, 'dir'));
        $this->assertSame($this->normalizePath($missing), $actualDir);

        $this->assertDirectoryExists(
            $missing,
            'När baseDir pekar på en saknad katalog ska Logger skapa den (dödar LogicalNot-mutanten i mkdir-vakten).'
        );

        // städa upp så vi inte lämnar skräp
        $this->deleteDir(rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . basename(dirname(dirname($missing))));
    }

    public function testCleanupOldLogsDeletesFileThatIs86401SecondsOldWhenRetentionIsOneDay(): void
    {
        $dir = $this->logsDir;

        $logger = new Logger(
            channel: 'cleanup2',
            baseDir: $dir,
            maxBytes: 1024 * 1024,
            retentionDays: 1
        );

        $now = time();

        $victim = $dir . DIRECTORY_SEPARATOR . 'victim_86401.log';
        $this->assertNotFalse(file_put_contents($victim, "x\n"));

        // 86401 sekunder gammal => ska raderas med korrekt 86400-threshold
        $this->assertTrue(@touch($victim, $now - 86401));

        $this->invokeCleanup($logger);

        $this->assertFileDoesNotExist(
            $victim,
            'Fil som är 86401 sekunder gammal ska raderas vid retentionDays=1 (dödar 86400 -> 86401 mutanten).'
        );
    }

    public function testInfoInterpolatesNullAndIntsWithoutWarnings(): void
    {
        $logger = new Logger('caststring', $this->logsDir);

        $oldLevel = error_reporting(E_ALL);
        set_error_handler(
            static function (int $severity, string $message): bool {
                throw new RuntimeException("PHP warning/notice: {$message}", $severity);
            }
        );

        try {
            $logger->info('A{n}B{i}C', ['n' => null, 'i' => 123]);
        } finally {
            restore_error_handler();
            error_reporting($oldLevel);
        }

        $file = $this->logsDir . DIRECTORY_SEPARATOR . 'caststring-' . date('Y-m-d') . '.log';
        $this->assertFileExists($file);

        $content = (string) file_get_contents($file);

        $this->assertStringContainsString(
            'AB123C',
            $content,
            'interpolate() måste casta null till "" och int till sträng (dödar CastString-mutanten).'
        );
    }

    /**
     * @param array<int, string> $files
     */
    private function anyExisting(array $files): bool
    {
        foreach ($files as $f) {
            if (is_file($f)) {
                return true;
            }
        }
        return false;
    }

    private function deleteDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $p = $dir . DIRECTORY_SEPARATOR . $f;
            if (is_dir($p)) {
                $this->deleteDir($p);
            } else {
                @unlink($p);
            }
        }
        @rmdir($dir);
    }
}
