<?php

declare(strict_types=1);

namespace Radix\Tests\Support;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Radix\Session\RadixSessionHandler;
use ReflectionClass;
use RuntimeException;

final class RadixSessionHandlerFileTest extends TestCase
{
    private string $tmpDir;
    private RadixSessionHandler $handler;

    public static ?string $lastFilePutContentsPath = null;
    public static ?string $lastFileGetContentsPath = null;
    public static bool $forceFilePutContentsFail = false;

    /** @var list<array{path:string,mode:int}> */
    public static array $chmodCalls = [];

    /** @var list<string> */
    public static array $unlinkCalls = [];

    /** @var list<string> */
    public static array $globCalls = [];

    /** @var array<string,int> filePath => mtime */
    public static array $fileMtimes = [];

    public static ?int $fakeNow = null;

    private static bool $fsOverridesInstalled = false;

    public static ?int $lastMkdirPermissions = null;

    public static ?string $lastMkdirPath = null;

    public static bool $forceMkdirFail = false;

    public static bool $forceMkdirFailButCreateDir = false;

    private static function resetSpies(): void
    {
        self::$lastFilePutContentsPath = null;
        self::$lastFileGetContentsPath = null;
        self::$lastMkdirPermissions = null;
        self::$lastMkdirPath = null;
        self::$forceFilePutContentsFail = false;
        self::$forceMkdirFail = false;
        self::$forceMkdirFailButCreateDir = false;
        self::$chmodCalls = [];
        self::$unlinkCalls = [];
        self::$globCalls = [];
        self::$fileMtimes = [];
        self::$fakeNow = null;
    }

    private static function installFsOverridesOnce(): void
    {
        if (self::$fsOverridesInstalled) {
            return;
        }

        eval(<<<'PHP'
            namespace Radix\Session {
                function file_put_contents(string $filename, mixed $data, int $flags = 0, mixed $context = null): int|false
                {
                    \Radix\Tests\Support\RadixSessionHandlerFileTest::$lastFilePutContentsPath = $filename;

                    if (\Radix\Tests\Support\RadixSessionHandlerFileTest::$forceFilePutContentsFail) {
                        return false;
                    }

                    /** @var resource|null $context */
                    return \file_put_contents($filename, $data, $flags, $context);
                }

                function file_get_contents(
                    string $filename,
                    bool $use_include_path = false,
                    $context = null,
                    int $offset = 0,
                    ?int $length = null
                ): string|false {
                    \Radix\Tests\Support\RadixSessionHandlerFileTest::$lastFileGetContentsPath = $filename;

                    /** @var resource|null $context */
                    return \file_get_contents($filename, $use_include_path, $context, $offset, $length);
                }

                function mkdir(string $directory, int $permissions = 0o777, bool $recursive = false, $context = null): bool
                {
                    \Radix\Tests\Support\RadixSessionHandlerFileTest::$lastMkdirPath = $directory;
                    \Radix\Tests\Support\RadixSessionHandlerFileTest::$lastMkdirPermissions = $permissions;

                    if (\Radix\Tests\Support\RadixSessionHandlerFileTest::$forceMkdirFailButCreateDir) {
                        /** @var resource|null $context */
                        \mkdir($directory, $permissions, $recursive, $context);
                        return false;
                    }

                    if (\Radix\Tests\Support\RadixSessionHandlerFileTest::$forceMkdirFail) {
                        return false;
                    }

                    /** @var resource|null $context */
                    return \mkdir($directory, $permissions, $recursive, $context);
                }

                // TA BORT den duplicerade mkdir()-funktionen som råkade ligga här

                function chmod(string $filename, int $permissions): bool
                {
                    \Radix\Tests\Support\RadixSessionHandlerFileTest::$chmodCalls[] = [
                        'path' => $filename,
                        'mode' => $permissions,
                    ];
                    return true;
                }

                function unlink(string $filename): bool
                {
                    \Radix\Tests\Support\RadixSessionHandlerFileTest::$unlinkCalls[] = $filename;
                    return \unlink($filename);
                }

                function glob(string $pattern, int $flags = 0): array|false
                {
                    \Radix\Tests\Support\RadixSessionHandlerFileTest::$globCalls[] = $pattern;
                    return \glob($pattern, $flags);
                }

                function filemtime(string $filename): int|false
                {
                    $mtimes = \Radix\Tests\Support\RadixSessionHandlerFileTest::$fileMtimes;

                    if (\array_key_exists($filename, $mtimes)) {
                        return (int) $mtimes[$filename];
                    }

                    return \filemtime($filename);
                }

                function time(): int
                {
                    $fake = \Radix\Tests\Support\RadixSessionHandlerFileTest::$fakeNow;
                    return $fake === null ? \time() : $fake;
                }
            }
            PHP);

        self::$fsOverridesInstalled = true;
    }

    protected function setUp(): void
    {
        parent::setUp();

        self::installFsOverridesOnce();
        self::resetSpies();

        $this->tmpDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'radix_sess_' . bin2hex(random_bytes(4)) . DIRECTORY_SEPARATOR;
        @mkdir($this->tmpDir, 0o755, true);

        $this->handler = new RadixSessionHandler([
            'driver' => 'file',
            'path' => $this->tmpDir,
            'lifetime' => 60,
        ]);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->tmpDir);
        parent::tearDown();
    }

    public function testConstructorDoesNotThrowWhenMkdirReturnsFalseButDirectoryExists(): void
    {
        $missingDir = rtrim($this->tmpDir, '/\\') . DIRECTORY_SEPARATOR . 'ctor_race_' . bin2hex(random_bytes(3));

        $this->assertDirectoryDoesNotExist($missingDir);

        self::resetSpies();
        self::$forceMkdirFailButCreateDir = true;

        $handler = new RadixSessionHandler([
            'driver' => 'file',
            'path' => $missingDir,
            'lifetime' => 60,
        ]);

        $this->assertInstanceOf(RadixSessionHandler::class, $handler);
        $this->assertDirectoryExists(
            $missingDir,
            'Katalogen ska finnas även om mkdir() rapporterade false (simulerad race condition).'
        );
    }

    public function testConstructorThrowsWhenSessionDirectoryCannotBeCreated(): void
    {
        $missingDir = rtrim($this->tmpDir, '/\\') . DIRECTORY_SEPARATOR . 'ctor_fail_' . bin2hex(random_bytes(3));

        $this->assertDirectoryDoesNotExist($missingDir);

        self::resetSpies();
        self::$forceMkdirFail = true;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Kunde inte skapa katalog för fil baserade sessioner');

        new RadixSessionHandler([
            'driver' => 'file',
            'path' => $missingDir,
            'lifetime' => 60,
        ]);

        // (vi kommer inte hit om exception kastas korrekt)
    }

    public function testWriteTrimsTrailingSlashInPathWhenBuildingSessionFilename(): void
    {
        $pathWithTrailingSlash = rtrim($this->tmpDir, '/\\') . DIRECTORY_SEPARATOR;

        $handler = new RadixSessionHandler([
            'driver' => 'file',
            'path' => $pathWithTrailingSlash, // med trailing separator
            'lifetime' => 60,
        ]);

        $sid = 'trim-path';
        $handler->write($sid, 'x');

        $expected = rtrim($pathWithTrailingSlash, '/\\') . DIRECTORY_SEPARATOR . "sess_{$sid}";

        $this->assertSame(
            $expected,
            self::$lastFilePutContentsPath,
            'write() ska bygga sökvägen med rtrim(path) så vi inte får dubbel-separator.'
        );

        // Extra stark check: dubbel-separator får inte förekomma
        $this->assertNotFalse(self::$lastFilePutContentsPath);
        $this->assertStringNotContainsString(
            DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR . 'sess_',
            (string) self::$lastFilePutContentsPath,
            'write() får inte skapa en sökväg med dubbel directory-separator före sess_-prefixet.'
        );
    }

    public function testGcUsesRtrimmedPathInGlobPatternToAvoidDoubleSeparators(): void
    {
        $pathWithTrailingSlash = rtrim($this->tmpDir, '/\\') . DIRECTORY_SEPARATOR;

        $handler = new RadixSessionHandler([
            'driver' => 'file',
            'path' => $pathWithTrailingSlash,
            'lifetime' => 60,
        ]);

        self::resetSpies();
        $handler->gc(10);

        $expectedPattern = rtrim($pathWithTrailingSlash, '/\\') . DIRECTORY_SEPARATOR . 'sess_*';

        $this->assertSame(
            $expectedPattern,
            self::$globCalls[0] ?? null,
            'gc() ska anropa glob() med rtrim(path) för att undvika dubbel-separator.'
        );

        $this->assertStringNotContainsString(
            DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR . 'sess_',
            (string) (self::$globCalls[0] ?? ''),
            'glob()-pattern får inte innehålla dubbel directory-separator före sess_-prefixet.'
        );
    }

    public function testConstructorCreatesSessionDirectoryWhenMissingWith0755(): void
    {
        $missingDir = rtrim($this->tmpDir, '/\\') . DIRECTORY_SEPARATOR . 'ctor_missing_' . bin2hex(random_bytes(3));

        $this->assertDirectoryDoesNotExist($missingDir);

        self::resetSpies();

        $handler = new RadixSessionHandler([
            'driver' => 'file',
            'path' => $missingDir,
            'lifetime' => 60,
        ]);

        $this->assertInstanceOf(RadixSessionHandler::class, $handler);
        $this->assertDirectoryExists($missingDir, 'Konstruktorn ska skapa sessionskatalogen när den saknas.');
        $this->assertSame($missingDir, self::$lastMkdirPath, 'mkdir() ska anropas med exakt den saknade katalogen.');
        $this->assertSame(0o755, self::$lastMkdirPermissions, 'mkdir() ska anropas med permissions 0755.');
    }

    public function testReadTrimsTrailingSlashInPathWhenBuildingSessionFilename(): void
    {
        $pathWithTrailingSlash = rtrim($this->tmpDir, '/\\') . DIRECTORY_SEPARATOR;

        $handler = new RadixSessionHandler([
            'driver' => 'file',
            'path' => $pathWithTrailingSlash,
            'lifetime' => 60,
        ]);

        $sid = 'read-trim';
        $expectedFile = rtrim($pathWithTrailingSlash, '/\\') . DIRECTORY_SEPARATOR . "sess_{$sid}";
        file_put_contents($expectedFile, 'HELLO');

        self::resetSpies();
        $out = $handler->read($sid);

        $this->assertSame('HELLO', $out);

        $this->assertSame(
            $expectedFile,
            self::$lastFileGetContentsPath,
            'read() ska bygga filnamnet med rtrim(path) så vi inte får dubbel-separator.'
        );
    }

    public function testWriteCreatesDirectoryWhenMissing(): void
    {
        $missingDir = rtrim($this->tmpDir, '/\\') . DIRECTORY_SEPARATOR . 'missing_subdir_' . bin2hex(random_bytes(3));

        $this->assertDirectoryDoesNotExist($missingDir);

        $ref = new ReflectionClass($this->handler);
        $prop = $ref->getProperty('filePath');
        $prop->setAccessible(true);
        $prop->setValue($this->handler, $missingDir);

        $sid = 'needs-mkdir';
        $ok = $this->handler->write($sid, 'x');

        $this->assertTrue($ok, 'write() ska lyckas även om sessionkatalogen saknas (den ska skapas).');
        $this->assertSame(0o755, self::$lastMkdirPermissions, 'write() ska anropa mkdir() med permissions 0755.');
        $this->assertDirectoryExists($missingDir);
        $this->assertFileExists($missingDir . DIRECTORY_SEPARATOR . "sess_{$sid}");
    }

    public function testLifetimeDefaultsTo1440WhenNotProvidedAndUsesProvidedValueWhenSet(): void
    {
        $handlerDefault = new RadixSessionHandler([
            'driver' => 'file',
            'path' => $this->tmpDir,
            // lifetime utelämnas
        ]);

        $ref = new ReflectionClass($handlerDefault);
        $prop = $ref->getProperty('lifetime');
        $prop->setAccessible(true);

        $this->assertSame(
            1440,
            $prop->getValue($handlerDefault),
            'Default lifetime ska vara exakt 1440 när config saknar lifetime.'
        );

        $handlerCustom = new RadixSessionHandler([
            'driver' => 'file',
            'path' => $this->tmpDir,
            'lifetime' => 123,
        ]);

        $ref2 = new ReflectionClass($handlerCustom);
        $prop2 = $ref2->getProperty('lifetime');
        $prop2->setAccessible(true);

        $this->assertSame(
            123,
            $prop2->getValue($handlerCustom),
            'När lifetime anges i config ska den användas (inte default 1440).'
        );
    }

    public function testDatabaseDriverRequiresPdo(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('PDO är krävd för databaslagring av sessioner.');

        new RadixSessionHandler([
            'driver' => 'database',
            'table' => 'sessions',
            'lifetime' => 60,
        ], null);
    }

    public function testDestroyTrimsTrailingSlashInPathWhenBuildingSessionFilename(): void
    {
        $pathWithTrailingSlash = rtrim($this->tmpDir, '/\\') . DIRECTORY_SEPARATOR;

        $handler = new RadixSessionHandler([
            'driver' => 'file',
            'path' => $pathWithTrailingSlash,
            'lifetime' => 60,
        ]);

        $sid = 'destroy-me';
        $handler->write($sid, 'x=1');

        self::resetSpies();
        $ok = $handler->destroy($sid);

        $this->assertTrue($ok);

        $expected = rtrim($pathWithTrailingSlash, '/\\') . DIRECTORY_SEPARATOR . "sess_{$sid}";
        $this->assertSame(
            $expected,
            self::$unlinkCalls[0] ?? null,
            'destroy() ska använda rtrim(path) när den bygger filnamnet.'
        );
    }

    public function testGcDoesNotDeleteWhenFileIsExactlyAtLifetimeBoundary(): void
    {
        // Skapa en fil som gc() kan hitta (vi använder riktiga filer, men styr time()+filemtime() via overrides)
        $file = rtrim($this->tmpDir, '/\\') . DIRECTORY_SEPARATOR . 'sess_boundary';
        file_put_contents($file, 'x');

        $maxLifetime = 10;
        $now = 1000;

        self::$fakeNow = $now;
        self::$fileMtimes[$file] = $now - $maxLifetime; // exakt på gränsen

        $deleted = $this->handler->gc($maxLifetime);

        $this->assertIsInt($deleted);
        $this->assertSame(
            0,
            $deleted,
            'När filemtime + max_lifetime är exakt lika med time() ska filen INTE raderas (kräver strikt <).'
        );
        $this->assertFileExists($file, 'Boundary-filen ska finnas kvar.');
    }

    public function testWriteThenReadReturnsSameData(): void
    {
        $sid = 'abc123';
        $data = 'foo=bar;num=1';

        $ok = $this->handler->write($sid, $data);
        $this->assertTrue($ok, 'Write ska returnera true');
        $this->assertFileExists($this->tmpDir . "sess_{$sid}");

        $read = $this->handler->read($sid);
        $this->assertSame($data, $read, 'Read ska returnera samma data som skrivits');
    }

    public function testReadNonExistingReturnsEmptyString(): void
    {
        $read = $this->handler->read('does-not-exist');
        $this->assertSame('', $read);
    }

    public function testDestroyRemovesSessionFile(): void
    {
        $sid = 'to-destroy';
        $this->handler->write($sid, 'x=1');
        $file = $this->tmpDir . "sess_{$sid}";
        $this->assertFileExists($file);

        $ok = $this->handler->destroy($sid);
        $this->assertTrue($ok);
        $this->assertFileDoesNotExist($file);
    }

    public function testDestroyReturnsTrueWhenFileDoesNotExist(): void
    {
        $sid  = 'non-existing';
        $file = $this->tmpDir . "sess_{$sid}";

        // Säkerställ att filen verkligen inte finns
        if (file_exists($file)) {
            @unlink($file);
        }
        $this->assertFileDoesNotExist($file);

        // destroy() ska fortfarande returnera true när fil saknas
        $ok = $this->handler->destroy($sid);
        $this->assertTrue($ok, 'destroy() ska returnera true även om filen inte finns');
    }

    public function testGcRemovesExpiredFiles(): void
    {
        // Skapa två sessioner: en gammal, en ny
        $old = $this->tmpDir . 'sess_old';
        $new = $this->tmpDir . 'sess_new';

        file_put_contents($old, 'old');
        file_put_contents($new, 'new');

        // Backa mtime för "old" så att den blir äldre än max_lifetime
        $past = time() - 3600;
        @touch($old, $past);

        $deleted = $this->handler->gc(10);

        // Ska returnera ett heltal och exakt en raderad fil
        $this->assertIsInt($deleted);
        $this->assertSame(1, $deleted, 'gc() ska rapportera exakt en raderad sessionfil');

        $this->assertFileDoesNotExist($old, 'Gammal sessionfil ska ha raderats');
        $this->assertFileExists($new, 'Ny sessionfil ska finnas kvar');
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
