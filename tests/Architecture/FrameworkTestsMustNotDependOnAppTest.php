<?php

declare(strict_types=1);

namespace Radix\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class FrameworkTestsMustNotDependOnAppTest extends TestCase
{
    private function skipIfDisabled(): void
    {
        $flag = getenv('SKIP_ARCH_TESTS');

        if ($flag === '1' || strtolower((string) $flag) === 'true') {
            self::markTestSkipped('Arkitekturtest tillfälligt avstängt (SKIP_ARCH_TESTS=1).');
        }
    }

    public function testFrameworkTestsDoNotReferenceAppNamespace(): void
    {
        $this->skipIfDisabled();

        $frameworkTests = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'tests';
        self::assertDirectoryExists($frameworkTests, 'Hittar inte framework/tests: ' . $frameworkTests);

        $violations = [];

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($frameworkTests, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        $needle = 'App' . '\\';

        /** @var SplFileInfo $file */
        foreach ($it as $file) {
            if (!$file->isFile()) {
                continue;
            }

            if (strtolower($file->getExtension()) !== 'php') {
                continue;
            }

            $path = $file->getPathname();
            $contents = @file_get_contents($path);
            if ($contents === false) {
                continue;
            }

            if (strpos($contents, $needle) !== false) {
                $violations[] = $path;
            }
        }

        self::assertSame(
            [],
            $violations,
            "framework/tests får inte referera till " . $needle . ". Filer som bryter regeln:\n- " . implode("\n- ", $violations)
        );
    }
}
