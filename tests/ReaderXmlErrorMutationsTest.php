<?php

declare(strict_types=1);

namespace Radix\Tests;

use PHPUnit\Framework\TestCase;
use Radix\File\Reader;

final class ReaderXmlErrorMutationsTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . 'radix_reader_xml_err_' . bin2hex(random_bytes(4))
            . DIRECTORY_SEPARATOR;

        mkdir($this->tmpDir, 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->tmpDir);
        parent::tearDown();
    }

    public function testXmlErrorMessageHasPrefixAndContainsTrimmedLibxmlDetails(): void
    {
        $path = $this->tmpDir . 'broken_aaa.xml';

        // "AAA" är med flit för att ge en unik libxml-felrad vi kan leta efter.
        file_put_contents($path, '<root><AAA></root>');

        try {
            Reader::xml($path, assoc: true);
            $this->fail('Reader::xml ska kasta på trasigt XML.');
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();

            // Dödar Concat + ConcatOperandRemoval: prefix måste ligga först och något mer måste finnas.
            $this->assertStringStartsWith('Kunde inte parsa XML: ', $msg);
            $this->assertNotSame('Kunde inte parsa XML: ', $msg);

            // Dödar UnwrapTrim: meddelandet ska inte sluta med whitespace (libxml har ofta \n i message).
            $this->assertSame($msg, rtrim($msg), 'Felmeddelandet ska vara rtrim:at (inga trailing \r/\n/whitespace).');
        }
    }

    public function testXmlClearsLibxmlErrorsBetweenCallsSoPreviousErrorDoesNotLeak(): void
    {
        $p1 = $this->tmpDir . 'broken_aaa.xml';
        $p2 = $this->tmpDir . 'broken_bbb.xml';

        file_put_contents($p1, '<root><AAA></root>');
        file_put_contents($p2, '<root><BBB></root>');

        $m1 = null;
        try {
            Reader::xml($p1, assoc: true);
            $this->fail('Första Reader::xml ska kasta.');
        } catch (\RuntimeException $e) {
            $m1 = $e->getMessage();
        }

        $m2 = null;
        try {
            Reader::xml($p2, assoc: true);
            $this->fail('Andra Reader::xml ska kasta.');
        } catch (\RuntimeException $e) {
            $m2 = $e->getMessage();
        }

        // Dödar FunctionCallRemoval (libxml_clear_errors bort):
        // utan clear_errors kan "AAA"-felet ligga kvar och dyka upp i andra exception.
        $this->assertStringNotContainsString('AAA', $m2, 'Libxml-fel från första parse får inte läcka in i nästa exception.');
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
