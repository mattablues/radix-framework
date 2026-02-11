<?php

declare(strict_types=1);

namespace Radix\Tests;

use PHPUnit\Framework\TestCase;
use Radix\File\Reader;

final class ReaderXmlEnsureFileReadableTest extends TestCase
{
    public function testXmlThrowsClearErrorWhenFileDoesNotExist(): void
    {
        $missing = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . 'radix_missing_' . bin2hex(random_bytes(6))
            . '.xml';

        $this->assertFileDoesNotExist($missing);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Filen finns inte: ' . $missing);

        Reader::xml($missing, assoc: true);
    }
}
