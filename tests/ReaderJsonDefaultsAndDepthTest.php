<?php

declare(strict_types=1);

namespace Radix\Tests;

use JsonException;
use PHPUnit\Framework\TestCase;
use Radix\File\Reader;

final class ReaderJsonDefaultsAndDepthTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . 'radix_reader_json_' . bin2hex(random_bytes(4))
            . DIRECTORY_SEPARATOR;

        mkdir($this->tmpDir, 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->tmpDir);
        parent::tearDown();
    }

    public function testJsonDefaultAssocIsTrueAndReturnsArray(): void
    {
        $path = $this->tmpDir . 'data.json';
        file_put_contents($path, '{"a":1}');

        $v = Reader::json($path); // assoc utelämnas => default

        $this->assertIsArray($v, 'Default assoc ska vara true, alltså array.');
        $this->assertSame(['a' => 1], $v);
    }

    public function testJsonAssocFalseReturnsObject(): void
    {
        $path = $this->tmpDir . 'data_obj.json';
        file_put_contents($path, '{"a":1}');

        $v = Reader::json($path, assoc: false);

        $this->assertIsObject($v, 'När assoc=false ska Reader::json returnera object.');
        $this->assertSame(1, $v->a ?? null);
    }

    public function testJsonDepth512AcceptsDepth511ButRejectsDepth512(): void
    {
        $p511 = $this->tmpDir . 'depth_511.json';
        $p512 = $this->tmpDir . 'depth_512.json';

        $makeNested = static function (int $depth): string {
            $nested = '0';
            for ($i = 0; $i < $depth; $i++) {
                $nested = '[' . $nested . ']';
            }
            return $nested;
        };

        // Med depth=512: 511-nästlingar ska fungera.
        // Mutant (511) skulle kasta här => testet dödar DecrementInteger.
        file_put_contents($p511, $makeNested(511));
        $ok = Reader::json($p511, assoc: true);
        $this->assertIsArray($ok);

        // Med depth=512: 512-nästlingar ska kasta i denna runtime.
        // Mutant (513) skulle inte kasta här => testet dödar IncrementInteger.
        file_put_contents($p512, $makeNested(512));

        $this->expectException(JsonException::class);
        Reader::json($p512, assoc: true);
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
