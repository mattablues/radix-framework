<?php

declare(strict_types=1);

namespace Radix\Tests;

use PHPUnit\Framework\TestCase;
use Radix\File\Reader;

final class ReaderTextStreamDefaultChunkSizeCallsFreadWith8192Test extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        ReaderTextStreamFreadSpy::reset();

        $this->tmpDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . 'radix_reader_textstream_freadspy_' . bin2hex(random_bytes(4))
            . DIRECTORY_SEPARATOR;

        mkdir($this->tmpDir, 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->tmpDir);
        parent::tearDown();
    }

    public function testTextStreamDefaultChunkSizeIs8192BecauseFreadIsCalledWith8192(): void
    {
        $path = $this->tmpDir . 'data.bin';

        // Lite data räcker – vi vill bara trigga minst en fread().
        file_put_contents($path, str_repeat('x', 10_000));

        Reader::textStream(
            $path,
            static function (string $chunk): void {
                // no-op
            }
            // chunkSize utelämnas => default används
        );

        $this->assertNotEmpty(ReaderTextStreamFreadSpy::$lengths, 'textStream() ska anropa fread() minst en gång.');
        $this->assertSame(
            8192,
            ReaderTextStreamFreadSpy::$lengths[0],
            'Första fread()-anropet ska begära exakt 8192 bytes när default chunkSize används.'
        );
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

final class ReaderTextStreamFreadSpy
{
    /** @var list<int> */
    public static array $lengths = [];

    public static function reset(): void
    {
        self::$lengths = [];
    }
}

/**
 * Namespaced fread-spy för Radix\File\fread().
 * Reader::textStream anropar fread() utan backslash, så den här fångar anropet.
 */
if (!function_exists('Radix\\File\\fread')) {
    eval('namespace Radix\\File; function fread($handle, $length) {
        \\Radix\\Tests\\ReaderTextStreamFreadSpy::$lengths[] = (int) $length;
        return \\fread($handle, $length);
    }');
}
