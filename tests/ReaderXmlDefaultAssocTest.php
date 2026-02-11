<?php

declare(strict_types=1);

namespace Radix\Tests;

use PHPUnit\Framework\TestCase;
use Radix\File\Reader;

final class ReaderXmlDefaultAssocTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . 'radix_reader_xml_default_' . bin2hex(random_bytes(4))
            . DIRECTORY_SEPARATOR;

        mkdir($this->tmpDir, 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->tmpDir);
        parent::tearDown();
    }

    public function testXmlDefaultAssocIsTrueAndReturnsArray(): void
    {
        $path = $this->tmpDir . 'data.xml';
        file_put_contents($path, '<?xml version="1.0" encoding="UTF-8"?><root><a>1</a></root>');

        $v = Reader::xml($path); // assoc utelämnas => default

        $this->assertIsArray($v, 'Default assoc ska vara true för Reader::xml(), alltså array.');
        $this->assertSame(['a' => '1'], $v);
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
