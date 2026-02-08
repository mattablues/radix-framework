<?php

declare(strict_types=1);

namespace Radix\Tests\Support;

use PHPUnit\Framework\TestCase;
use Radix\Support\Logger;
use ReflectionClass;

final class LoggerBootstrapPathTest extends TestCase
{
    private function normalizePath(string $p): string
    {
        return rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $p), "/\\");
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

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testDefaultBaseDirWhenRootPathIsNotDefinedUsesDirnameOfLoggerFile(): void
    {
        $this->assertFalse(defined('ROOT_PATH'), 'ROOT_PATH får inte vara definierad i den här processen.');

        $logger = new Logger(channel: 'boot', baseDir: null);

        $actual = $this->normalizePath($this->getPrivateString($logger, 'dir'));

        $loggerFile = (new ReflectionClass(Logger::class))->getFileName();
        $this->assertIsString($loggerFile);

        $loggerDir = dirname($loggerFile);
        $expectedRoot = dirname($loggerDir, 3); // måste vara exakt 3

        $expected = $this->normalizePath(
            rtrim($expectedRoot, "/\\")
            . DIRECTORY_SEPARATOR . 'storage'
            . DIRECTORY_SEPARATOR . 'logs'
        );

        $this->assertSame(
            $expected,
            $actual,
            'När ROOT_PATH saknas ska Logger använda dirname(__DIR__, 3) (dödar dirname 2/4-mutanterna).'
        );
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testDefaultBaseDirTrimsTrailingSlashFromRootPath(): void
    {
        $this->assertFalse(defined('ROOT_PATH'), 'ROOT_PATH ska definieras i testet, inte redan finnas.');

        $root = rtrim(sys_get_temp_dir(), "/\\")
            . DIRECTORY_SEPARATOR
            . 'radix_logger_root_' . bin2hex(random_bytes(4));

        @mkdir($root, 0o755, true);

        // Viktigt: trailing separator så rtrim() behövs
        define('ROOT_PATH', $root . DIRECTORY_SEPARATOR);

        $logger = new Logger(channel: 'trim', baseDir: null);

        $actual = $this->normalizePath($this->getPrivateString($logger, 'dir'));

        $expected = $this->normalizePath(
            rtrim((string) ROOT_PATH, "/\\")
            . DIRECTORY_SEPARATOR . 'storage'
            . DIRECTORY_SEPARATOR . 'logs'
        );

        $this->assertSame(
            $expected,
            $actual,
            'ROOT_PATH med trailing separator måste rtrim:as innan /storage/logs byggs (dödar UnwrapRtrim-mutanten).'
        );

        // Städa (best effort)
        @rmdir($expected);
        @rmdir(dirname($expected));
        @rmdir(dirname(dirname($expected)));
        @rmdir($root);
    }
}
