<?php

declare(strict_types=1);

namespace Radix\Tests\Support;

use PHPUnit\Framework\TestCase;
use Radix\Support\FsSpy;
use Radix\Support\Logger;

final class LoggerMkdirPermissionsTest extends TestCase
{
    public function testConstructorCallsMkdirWith0755WhenBaseDirMissing(): void
    {
        FsSpy::reset();

        $base = rtrim(sys_get_temp_dir(), "/\\")
            . DIRECTORY_SEPARATOR
            . 'radix_logger_mkdir_' . bin2hex(random_bytes(4))
            . DIRECTORY_SEPARATOR
            . 'storage'
            . DIRECTORY_SEPARATOR
            . 'logs';

        $this->assertDirectoryDoesNotExist($base);

        new Logger(channel: 'mkperm', baseDir: $base);

        $this->assertDirectoryExists($base);

        $this->assertSame(
            0o755,
            FsSpy::$lastMkdirPermissions,
            sprintf('Logger ska anropa mkdir() med 0755, fick 0%o', FsSpy::$lastMkdirPermissions ?? 0)
        );
        $this->assertSame($base, FsSpy::$lastMkdirPath);
    }
}
