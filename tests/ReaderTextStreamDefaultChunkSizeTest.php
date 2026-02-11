<?php

declare(strict_types=1);

namespace Radix\Tests;

use PHPUnit\Framework\TestCase;
use Radix\File\Reader;

final class ReaderTextStreamDefaultChunkSizeTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . 'radix_reader_textstream_' . bin2hex(random_bytes(4))
            . DIRECTORY_SEPARATOR;

        mkdir($this->tmpDir, 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->tmpDir);
        parent::tearDown();
    }

    public function testTextStreamDefaultChunkSizeIsExactly8192(): void
    {
        $path = $this->tmpDir . 'bytes.bin';

        // 8192 + 8192 + 2 bytes => med default 8192 blir det 3 chunks.
        // Mutant 8193 ger normalt 2 chunks (8193*2 = 16386) => testet dödar mutanten.
        $invalidUtf8 = "\xC3\x28";
        $content = str_repeat('a', 8192) . str_repeat('b', 8192) . $invalidUtf8;
        file_put_contents($path, $content);

        $chunks = [];
        Reader::textStream(
            $path,
            static function (string $chunk) use (&$chunks): void {
                $chunks[] = $chunk;
            },
            // viktigt: använd default chunkSize genom att INTE skicka param #3
            encoding: 'UTF-8'
        );

        $this->assertCount(3, $chunks, 'Default chunkSize=8192 ska ge 3 chunks för 8192+8192+2 bytes.');
        $this->assertSame(8192, strlen($chunks[0]), 'Första chunk ska vara exakt 8192 bytes med default chunkSize.');
        $this->assertSame($content, implode('', $chunks), 'När encoding=UTF-8 ska ingen konvertering ske (bytes ska bevaras).');
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
