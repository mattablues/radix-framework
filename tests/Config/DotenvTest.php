<?php

declare(strict_types=1);

namespace Radix\Config {
    /**
     * Wrapper runt global file() så tester kan styra returvärdet deterministiskt.
     *
     * @param 0|1|2|3|4|5|6|7|16|17|18|19|20|21|22|23 $flags
     * @return array<int,string>|false
     */
    function file(string $filename, int $flags = 0): array|false
    {
        if (\Radix\Tests\Config\DotenvFileSpy::$forcedReturn !== null) {
            return \Radix\Tests\Config\DotenvFileSpy::$forcedReturn;
        }

        // PHPStan: global file() accepterar bara vissa flag-kombinationer (0..7 och 16..23).
        if (!(($flags >= 0 && $flags <= 7) || ($flags >= 16 && $flags <= 23))) {
            throw new \RuntimeException('DotenvTest: invalid flags passed to file().');
        }

        /** @var 0|1|2|3|4|5|6|7|16|17|18|19|20|21|22|23 $flags */
        return \file($filename, $flags);
    }
}

namespace Radix\Tests\Config {

    use PHPUnit\Framework\TestCase;
    use Radix\Config\Dotenv;
    use RuntimeException;

    // Spy för att kunna styra Radix\Config\file() deterministiskt i tester
    final class DotenvFileSpy
    {
        /** @var array<int,string>|false|null */
        public static array|false|null $forcedReturn = null;

        public static function reset(): void
        {
            self::$forcedReturn = null;
        }
    }

    final class DotenvTest extends TestCase
    {
        private ?string $tmpEnvPath = null;

        protected function setUp(): void
        {
            parent::setUp();
            DotenvFileSpy::reset();
        }

        protected function tearDown(): void
        {
            DotenvFileSpy::reset();

            if (is_string($this->tmpEnvPath) && file_exists($this->tmpEnvPath)) {
                @unlink($this->tmpEnvPath);
            }
            $this->tmpEnvPath = null;

            unset($_ENV['APP_ENV'], $_SERVER['APP_ENV']);
            putenv('APP_ENV');

            unset($_ENV['PASSWORD'], $_SERVER['PASSWORD']);
            putenv('PASSWORD');

            unset($_ENV['FOO'], $_SERVER['FOO']);
            putenv('FOO');

            unset($_ENV['BAR'], $_SERVER['BAR']);
            putenv('BAR');

            unset($_ENV['BAZ'], $_SERVER['BAZ']);
            putenv('BAZ');

            unset($_ENV['HASH_START'], $_SERVER['HASH_START']);
            putenv('HASH_START');

            unset($_ENV['SEMI_START'], $_SERVER['SEMI_START']);
            putenv('SEMI_START');

            unset($_ENV['SPACED'], $_SERVER['SPACED']);
            putenv('SPACED');

            unset($_ENV['HASH_VALUE_THEN_COMMENT'], $_SERVER['HASH_VALUE_THEN_COMMENT']);
            putenv('HASH_VALUE_THEN_COMMENT');

            unset($_ENV['ENDS_WITH_COMMENT_MARK'], $_SERVER['ENDS_WITH_COMMENT_MARK']);
            putenv('ENDS_WITH_COMMENT_MARK');

            unset($_ENV['LOG_FILE'], $_SERVER['LOG_FILE']);
            putenv('LOG_FILE');

            unset($_ENV['CACHE_DIR'], $_SERVER['CACHE_DIR']);
            putenv('CACHE_DIR');
        }

        private function writeTempEnv(string $contents): string
        {
            $path = tempnam(sys_get_temp_dir(), 'radix-dotenv-');
            $this->assertIsString($path, 'Kunde inte skapa temporär .env-fil.');

            $ok = file_put_contents($path, $contents);
            if ($ok === false) {
                $this->fail('Kunde inte skriva temporär .env-fil.');
            }

            $this->tmpEnvPath = $path;
            return $path;
        }

        public function testTrimsKeyName(): void
        {
            $path = $this->writeTempEnv("  APP_ENV  =development\n");

            $dotenv = new Dotenv($path, null);
            $dotenv->load();

            $this->assertSame('development', getenv('APP_ENV'));
        }

        public function testDoesNotConvertPathKeysWhenBasePathIsNull(): void
        {
            $path = $this->writeTempEnv("LOG_FILE=logs/app.log\n");

            $dotenv = new Dotenv($path, null);
            $dotenv->load();

            $this->assertSame('logs/app.log', getenv('LOG_FILE'));
        }

        public function testStripInlineCommentDoesNotReadNegativeStringOffsets(): void
        {
            $path = $this->writeTempEnv("FOO=abc\n");

            $oldLevel = error_reporting(E_ALL);

            set_error_handler(
                static function (int $severity, string $message): bool {
                    throw new RuntimeException("PHP warning/notice: {$message}", $severity);
                }
            );

            try {
                $dotenv = new Dotenv($path, null);
                $dotenv->load();
            } finally {
                restore_error_handler();
                error_reporting($oldLevel);
            }

            $this->assertSame('abc', getenv('FOO'));
        }

        public function testLoadThrowsWhenFileReturnsLinesWithNewlinesSoIgnoreNewLinesFlagIsRequired(): void
        {
            $path = $this->writeTempEnv("APP_ENV=development\n");

            DotenvFileSpy::$forcedReturn = ["APP_ENV=development\n"];

            $dotenv = new Dotenv($path, null);

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Dotenv: file() must be called with FILE_IGNORE_NEW_LINES.');

            try {
                $dotenv->load();
            } finally {
                DotenvFileSpy::reset();
            }
        }

        public function testTrimsValueBeforeInlineCommentParsing(): void
        {
            $path = $this->writeTempEnv("FOO=  abc # comment\n");

            $dotenv = new Dotenv($path, null);
            $dotenv->load();

            // Utan trim i stripInlineComment skulle detta typiskt bli "  abc"
            $this->assertSame('abc', getenv('FOO'));
        }

        public function testParsesInlineHashComment(): void
        {
            $path = $this->writeTempEnv("APP_ENV=development # production | development | local | test\n");

            $dotenv = new Dotenv($path, null);
            $dotenv->load();

            $this->assertSame('development', getenv('APP_ENV'));
            $this->assertSame('development', $_ENV['APP_ENV']);
            $this->assertSame('development', $_SERVER['APP_ENV']);
        }

        public function testDoesNotStripHashInsideQuotes(): void
        {
            $path = $this->writeTempEnv("PASSWORD=\"abc#123\" # comment\n");

            $dotenv = new Dotenv($path, null);
            $dotenv->load();

            $this->assertSame('abc#123', getenv('PASSWORD'));
        }

        public function testDoesNotStripHashWithoutLeadingWhitespace(): void
        {
            // Viktigt: '#' utan whitespace ska räknas som del av värdet.
            $path = $this->writeTempEnv("FOO=abc#123\n");

            $dotenv = new Dotenv($path, null);
            $dotenv->load();

            $this->assertSame('abc#123', getenv('FOO'));
        }

        public function testParsesInlineSemicolonComment(): void
        {
            $path = $this->writeTempEnv("BAR=ok ; this is a comment\n");

            $dotenv = new Dotenv($path, null);
            $dotenv->load();

            $this->assertSame('ok', getenv('BAR'));
        }

        public function testDoesNotStripHashInsideDoubleQuotesWhenPrecededByWhitespace(): void
        {
            $path = $this->writeTempEnv("BAR=\"abc #123\" # comment\n");

            $dotenv = new Dotenv($path, null);
            $dotenv->load();

            $this->assertSame('abc #123', getenv('BAR'));
        }

        public function testDoesNotStripHashInsideSingleQuotesWhenPrecededByWhitespace(): void
        {
            $path = $this->writeTempEnv("BAZ='abc #123' # comment\n");

            $dotenv = new Dotenv($path, null);
            $dotenv->load();

            $this->assertSame('abc #123', getenv('BAZ'));
        }

        public function testStripsInlineHashCommentWhenPrecededByWhitespaceOutsideQuotes(): void
        {
            $path = $this->writeTempEnv("FOO=abc # comment\n");

            $dotenv = new Dotenv($path, null);
            $dotenv->load();

            $this->assertSame('abc', getenv('FOO'));
        }

        public function testDoesNotStripHashAtStartOfValue(): void
        {
            $path = $this->writeTempEnv("HASH_START=#bar\n");

            $dotenv = new Dotenv($path, null);
            $dotenv->load();

            $this->assertSame('#bar', getenv('HASH_START'));
        }

        public function testDoesNotStripSemicolonAtStartOfValue(): void
        {
            $path = $this->writeTempEnv("SEMI_START=;bar\n");

            $dotenv = new Dotenv($path, null);
            $dotenv->load();

            $this->assertSame(';bar', getenv('SEMI_START'));
        }

        public function testDoesNotTreatAnyCharAsCommentMarkerEvenIfPrecededByWhitespace(): void
        {
            // Viktigt för att döda mutant som gör comment-villkoret "nästan alltid sant".
            // Här finns whitespace före 'b' och det ska INTE kapa värdet till "a".
            $path = $this->writeTempEnv("SPACED=a b\n");

            $dotenv = new Dotenv($path, null);
            $dotenv->load();

            $this->assertSame('a b', getenv('SPACED'));
        }

        public function testStripsSecondHashWhenFirstHashIsValueStart(): void
        {
            // Första # är del av värdet (index 0), andra # (med whitespace före) är en inline-kommentar.
            $path = $this->writeTempEnv("HASH_VALUE_THEN_COMMENT=#bar #comment\n");

            $dotenv = new Dotenv($path, null);
            $dotenv->load();

            $this->assertSame('#bar', getenv('HASH_VALUE_THEN_COMMENT'));
        }

        public function testStripsCommentMarkerAtEndWhenPrecededByWhitespace(): void
        {
            // Dödar $i+1-mutanten: kommentartecknet är sista tecknet.
            $path = $this->writeTempEnv("ENDS_WITH_COMMENT_MARK=abc #\n");

            $dotenv = new Dotenv($path, null);
            $dotenv->load();

            $this->assertSame('abc', getenv('ENDS_WITH_COMMENT_MARK'));
        }

        public function testConvertsRelativePathToAbsoluteForPathKeysWhenBasePathProvided(): void
        {
            $basePath = rtrim(sys_get_temp_dir(), "/\\");
            $path = $this->writeTempEnv("LOG_FILE=logs/app.log\n");

            $dotenv = new Dotenv($path, $basePath);
            $dotenv->load();

            $expected = $basePath . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'app.log';

            $actual = getenv('LOG_FILE');
            $this->assertIsString($actual);

            $normalize = static function (string $p): string {
                return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $p);
            };

            $this->assertSame($normalize($expected), $normalize($actual));
        }

        public function testDoesNotConvertAbsolutePathForPathKeys(): void
        {
            $basePath = rtrim(sys_get_temp_dir(), "/\\");
            $absolute = $basePath . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'app.log';

            $path = $this->writeTempEnv("LOG_FILE={$absolute}\n");

            $dotenv = new Dotenv($path, $basePath);
            $dotenv->load();

            $this->assertSame($absolute, getenv('LOG_FILE'));
        }

        public function testDoesNotTriggerPhpWarningsWhenValueStartsWithCommentChar(): void
        {
            $path = $this->writeTempEnv("HASH_START=#bar\n");

            set_error_handler(
                static function (int $severity, string $message): bool {
                    throw new RuntimeException("PHP warning/notice: {$message}", $severity);
                }
            );

            try {
                $dotenv = new Dotenv($path, null);
                $dotenv->load();
            } finally {
                restore_error_handler();
            }

            $this->assertSame('#bar', getenv('HASH_START'));
        }

        public function testDoesNotMisparseWhenValueHasTrailingQuoteAfterComment(): void
        {
            // Dödar mutanten som startar loopen på -1 (läser sista tecknet först).
            // Extra trailing quote är "ful input" men händer i verkligheten.
            $path = $this->writeTempEnv("FOO=\"abc\" #comment\"\n");

            $dotenv = new Dotenv($path, null);
            $dotenv->load();

            $this->assertSame('abc', getenv('FOO'));
        }

        public function testDoesNotTreatHashAtStartAsInlineCommentEvenWithTrailingWhitespace(): void
        {
            // Dödar mutanten som gör $i===0-checken felaktig (0 -> -1)
            $path = $this->writeTempEnv("HASH_START=#bar \n");

            $dotenv = new Dotenv($path, null);
            $dotenv->load();

            $this->assertSame('#bar', getenv('HASH_START'));
        }

        public function testMakeAbsolutePathTrimsTrailingSlashFromBasePath(): void
        {
            // Dödar UnwrapRtrim-mutanten genom att ge basePath med trailing separator
            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR;

            $path = $this->writeTempEnv("LOG_FILE=logs/app.log\n");

            $dotenv = new Dotenv($path, $basePath);
            $dotenv->load();

            $actual = getenv('LOG_FILE');
            $this->assertIsString($actual);

            $normalize = static function (string $p): string {
                return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $p);
            };

            $expected = rtrim($basePath, "/\\") . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'app.log';
            $this->assertSame($normalize($expected), $normalize($actual));
        }

        public function testMakeAbsolutePathTrimsLeadingBackslashFromRelativePathOnWindows(): void
        {
            // OBS: Gjort plattformsoberoende – ingen skip i CI längre.
            // Vi matar in en path som börjar med "\" och förväntar oss att makeAbsolutePath()
            // trimmar bort ledande slash/backslash (nu görs det med ltrim($path, "/\\") i Dotenv).
            $basePath = rtrim(sys_get_temp_dir(), "/\\");
            $path = $this->writeTempEnv("LOG_FILE=\\logs\\app.log\n");

            $dotenv = new Dotenv($path, $basePath);
            $dotenv->load();

            $expected = $basePath . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'app.log';

            $actual = getenv('LOG_FILE');
            $this->assertIsString($actual);

            $normalize = static function (string $p): string {
                return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $p);
            };

            $this->assertSame($normalize($expected), $normalize($actual));
        }

        public function testIgnoresCommentLineWithLeadingWhitespace(): void
        {
            // Dödar UnwrapTrim-mutanten på $line = trim($line)
            $path = $this->writeTempEnv(
                "   # comment with leading whitespace\n"
                . "APP_ENV=development\n"
            );

            $dotenv = new Dotenv($path, null);
            $dotenv->load();

            $this->assertSame('development', getenv('APP_ENV'));
        }

        public function testIgnoresPlainCommentLine(): void
        {
            // Dödar LogicalOr-mutanten (|| -> &&) i if (startsWith('#') || empty())
            $path = $this->writeTempEnv(
                "# just a comment\n"
                . "APP_ENV=development\n"
            );

            $dotenv = new Dotenv($path, null);
            $dotenv->load();

            $this->assertSame('development', getenv('APP_ENV'));
        }

        public function testKeepsEqualsSignsInValue(): void
        {
            // Dödar explode(..., 2) -> explode(..., 3)
            $path = $this->writeTempEnv("FOO=a=b\n");

            $dotenv = new Dotenv($path, null);
            $dotenv->load();

            $this->assertSame('a=b', getenv('FOO'));
        }
    }
}
