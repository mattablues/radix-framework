<?php

declare(strict_types=1);


use PHPUnit\Framework\TestCase;
use Radix\File\Reader;
use Radix\File\Writer;

final class WriterIconvSpy
{
    /** @var list<array{from:string,to:string,input:string}> */
    public static array $calls = [];

    public static function reset(): void
    {
        self::$calls = [];
    }
}

/**
 * Namespaced iconv-spy för Radix\File\iconv().
 * Writer anropar iconv() utan backslash, så den här fångar anropen.
 */
if (!function_exists('Radix\\File\\iconv')) {
    eval('namespace Radix\\File; function iconv($from_encoding, $to_encoding, $string) {
        // Gör mutationerna (cast bort / felaktig is_string-branch) observerbara:
        // iconv() ska alltid få en string som input från vår kod.
        if (!is_string($string)) {
            throw new \\TypeError("Radix\\\\File\\\\iconv expects string as 3rd argument");
        }

        \\WriterIconvSpy::$calls[] = [
            "from" => (string) $from_encoding,
            "to" => (string) $to_encoding,
            "input" => (string) $string,
        ];
        return \\iconv($from_encoding, $to_encoding, $string);
    }');
}

// Spy för Radix\File\mkdir()
final class WriterMkdirSpy
{
    public static ?int $lastPermissions = null;
    public static ?string $lastPath = null;

    /** @var list<array{path:string, permissions:int, recursive:bool}> */
    public static array $calls = [];

    /** @var list<string> */
    public static array $failPaths = [];

    /** @var list<string> */
    public static array $racePaths = [];

    public static function reset(): void
    {
        self::$lastPermissions = null;
        self::$lastPath = null;
        self::$calls = [];
        self::$failPaths = [];
        self::$racePaths = [];
    }
}

if (!function_exists('Radix\\File\\mkdir')) {
    eval('namespace Radix\\File; function mkdir($directory, $permissions = 0777, $recursive = false, $context = null) {
        \\WriterMkdirSpy::$lastPermissions = $permissions;
        \\WriterMkdirSpy::$lastPath = $directory;
        \\WriterMkdirSpy::$calls[] = [
            "path" => (string) $directory,
            "permissions" => (int) $permissions,
            "recursive" => (bool) $recursive,
        ];

        $uploadSpy = "\\\\Radix\\\\Tests\\\\UploadMkdirSpy";

        if (class_exists($uploadSpy)) {
            $uploadSpy::$lastPermissions = (int) $permissions;
            $uploadSpy::$calls[] = [
                "path" => (string) $directory,
                "permissions" => (int) $permissions,
                "recursive" => (bool) $recursive,
            ];

            if (
                property_exists($uploadSpy, "liePaths")
                && in_array($directory, $uploadSpy::$liePaths, true)
            ) {
                return true;
            }

            if (in_array($directory, $uploadSpy::$racePaths, true)) {
                @\\mkdir($directory, $permissions, $recursive, $context);
                return false;
            }

            if (in_array($directory, $uploadSpy::$failPaths, true)) {
                return false;
            }
        }

        if (in_array($directory, \\WriterMkdirSpy::$racePaths, true)) {
            @\\mkdir($directory, $permissions, $recursive, $context);
            return false;
        }

        if (in_array($directory, \\WriterMkdirSpy::$failPaths, true)) {
            return false;
        }

        /** @var resource|null $context */
        return \\mkdir($directory, $permissions, $recursive, $context);
    }');
}

final class ReaderFcloseSpy
{
    public static int $calls = 0;

    public static function reset(): void
    {
        self::$calls = 0;
    }
}

final class WriterFcloseSpy
{
    public static int $calls = 0;

    public static function reset(): void
    {
        self::$calls = 0;
    }
}

/**
 * Spy för Radix\File\flock() så att vi kan verifiera att LOCK_UN verkligen anropas.
 */
final class WriterFlockSpy
{
    /** @var list<array{operation:int}> */
    public static array $calls = [];

    public static function reset(): void
    {
        self::$calls = [];
    }
}

if (!function_exists('Radix\\File\\flock')) {
    eval('namespace Radix\\File; function flock($stream, int $operation, &$wouldblock = null): bool {
        if (class_exists("\\\\WriterFlockSpy", false)) {
            \\WriterFlockSpy::$calls[] = ["operation" => $operation];
        }
        return \\flock($stream, $operation, $wouldblock);
    }');
}

final class FcloseSpy
{
    public static int $calls = 0;

    public static function reset(): void
    {
        self::$calls = 0;
    }
}

/**
 * Namespaced fclose-spy för Radix\File\fclose().
 * Reader/Writer anropar fclose() utan backslash, så den här fångar anropet.
 */
if (!function_exists('Radix\\File\\fclose')) {
    eval('namespace Radix\\File; function fclose($stream): bool {
        // Gemensam räknare (används av ditt Writer::csv-test)
        if (class_exists("\\\\FcloseSpy", false)) {
            \\FcloseSpy::$calls++;
        }

        // Behåll ev. separata räknare om du vill
        if (class_exists("\\ReaderFcloseSpy", false)) {
            \\ReaderFcloseSpy::$calls++;
        }
        if (class_exists("\\WriterFcloseSpy", false)) {
            \\WriterFcloseSpy::$calls++;
        }

        return \\fclose($stream);
    }');
}

final class ReaderWriterTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'radix_file_' . bin2hex(random_bytes(4)) . DIRECTORY_SEPARATOR;
        mkdir($this->tmpDir, 0o775, true);

        WriterMkdirSpy::reset();
        WriterIconvSpy::reset();
        WriterFcloseSpy::reset();
        ReaderFcloseSpy::reset();
        WriterFlockSpy::reset();
        FcloseSpy::reset();
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->tmpDir);
        parent::tearDown();
    }

    public function testNdjsonStreamClosesFileHandleWhenCallbackThrows(): void
    {
        $path = $this->tmpDir . 'boom.ndjson';

        try {
            Writer::ndjsonStream($path, function (callable $write): void {
                $write(['i' => 1]);
                throw new RuntimeException('boom');
            });

            $this->fail('ndjsonStream ska kasta när callbacken kastar.');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        // Om fclose() inte körs (UnwrapFinally-mutanten), kan unlink misslyckas på Windows.
        $deleted = @unlink($path);
        $this->assertTrue($deleted, 'Filen ska gå att ta bort direkt efter exception (handtaget måste vara stängt).');
        $this->assertFileDoesNotExist($path);
    }

    public function testEnsureParentDirDoesNotCallMkdirWhenDirectoryAlreadyExists(): void
    {
        $path = $this->tmpDir . 'already_exists.txt';

        $this->assertDirectoryExists($this->tmpDir);

        WriterMkdirSpy::reset();
        Writer::text($path, 'x');

        $this->assertSame([], WriterMkdirSpy::$calls, 'mkdir() ska inte anropas när parent-dir redan finns.');
        $this->assertFileExists($path);
    }

    public function testNdjsonStreamWithUtf8TargetEncodingDoesNotInvokeIconv(): void
    {
        $path = $this->tmpDir . 'ndjson_utf8_no_iconv.ndjson';

        WriterIconvSpy::reset();

        Writer::ndjsonStream(
            $path,
            function (callable $write): void {
                $write(['v' => 'Åsa']); // giltig UTF-8
            },
            targetEncoding: 'UTF-8',
            pretty: false
        );

        $this->assertSame([], WriterIconvSpy::$calls, 'iconv() ska inte anropas när targetEncoding är UTF-8 (ndjsonStream).');

        $raw = (string) file_get_contents($path);
        $this->assertStringContainsString('Åsa', $raw);
    }

    public function testEnsureParentDirUsesExpectedPermissionsWhenCreatingDirectories(): void
    {
        $nestedDir = $this->tmpDir . 'perm' . DIRECTORY_SEPARATOR . 'deep' . DIRECTORY_SEPARATOR . 'sub';
        $path = $nestedDir . DIRECTORY_SEPARATOR . 'file.txt';

        $this->assertDirectoryDoesNotExist($nestedDir);

        WriterMkdirSpy::reset();
        Writer::text($path, 'hello');

        $this->assertNotEmpty(WriterMkdirSpy::$calls, 'mkdir() ska anropas när parent-dir saknas.');
        $this->assertSame(0o775, WriterMkdirSpy::$lastPermissions, 'ensureParentDir() ska anropa mkdir() med permissions 0o775.');
        $this->assertFileExists($path);
    }

    public function testEnsureParentDirDoesNotThrowOnRaceWhenMkdirReturnsFalseButDirectoryExists(): void
    {
        $nestedDir = $this->tmpDir . 'race' . DIRECTORY_SEPARATOR . 'deep';
        $path = $nestedDir . DIRECTORY_SEPARATOR . 'file.txt';

        $this->assertDirectoryDoesNotExist($nestedDir);

        // Simulera race: mkdir() returnerar false men katalogen skapas ändå.
        WriterMkdirSpy::reset();
        WriterMkdirSpy::$racePaths = [$nestedDir];

        // Ska INTE kasta (original: !mkdir && !is_dir => false eftersom dir finns).
        Writer::text($path, 'hello');

        $this->assertDirectoryExists($nestedDir);
        $this->assertFileExists($path);
    }

    public function testXmlWriteConvertsToIso88591WhenTargetEncodingIsNotUtf8(): void
    {
        $path = $this->tmpDir . 'latin1.xml';

        Writer::xml(
            $path,
            ['name' => 'Åsa'],
            rootName: 'root',
            targetEncoding: 'ISO-8859-1'
        );

        $raw = (string) file_get_contents($path);

        // ISO-8859-1: 'Å' = 0xC5 (single byte)
        $this->assertStringContainsString("\xC5", $raw, 'XML ska innehålla ISO-8859-1-byten för Å när targetEncoding=ISO-8859-1.');
        // UTF-8 för 'Å' är 0xC3 0x85
        $this->assertStringNotContainsString("\xC3\x85", $raw, 'XML ska inte innehålla UTF-8-bytesekvensen för Å efter konvertering till ISO-8859-1.');
    }

    public function testXmlCreatesMissingParentDirectories(): void
    {
        $nestedDir = $this->tmpDir . 'xml' . DIRECTORY_SEPARATOR . 'deep' . DIRECTORY_SEPARATOR . 'sub';
        $path = $nestedDir . DIRECTORY_SEPARATOR . 'data.xml';

        $this->assertDirectoryDoesNotExist($nestedDir);

        Writer::xml($path, ['name' => 'Anna'], rootName: 'root');

        $this->assertDirectoryExists($nestedDir);
        $this->assertFileExists($path);
    }

    public function testXmlAcceptsObjectDataAndSerializesProperties(): void
    {
        $path = $this->tmpDir . 'obj.xml';

        $obj = new stdClass();
        $obj->name = 'Anna';

        // Ska inte kasta TypeError
        Writer::xml($path, $obj, rootName: 'root');

        $raw = (string) file_get_contents($path);
        $this->assertStringContainsString('<name>Anna</name>', $raw);
    }

    public function testXmlTargetEncodingAsciiUsesTranslitSoConversionDoesNotFail(): void
    {
        $path = $this->tmpDir . 'ascii.xml';

        // Detta ska fungera tack vare //TRANSLIT (mutanten utan TRANSLIT ska kasta).
        Writer::xml(
            $path,
            ['name' => 'Å'],
            rootName: 'root',
            targetEncoding: 'US-ASCII'
        );

        $raw = (string) file_get_contents($path);

        // Efter konvertering till ASCII ska det inte finnas UTF-8-bytesekvens för 'Å' kvar.
        $this->assertStringNotContainsString("\xC3\x85", $raw);
        $this->assertNotSame('', $raw);
    }

    public function testXmlWriteConvertsToUtf7ToMakeStrcasecmpMinusOneObservable(): void
    {
        $path = $this->tmpDir . 'utf7.xml';

        Writer::xml(
            $path,
            ['name' => 'Å'],
            rootName: 'root',
            targetEncoding: 'UTF-7'
        );

        $raw = (string) file_get_contents($path);

        $this->assertStringContainsString(
            '+ADw-',
            $raw,
            'XML ska vara UTF-7-kodat (t.ex. "<" -> "+ADw-") när targetEncoding=UTF-7.'
        );
        $this->assertStringNotContainsString(
            '<',
            $raw,
            'UTF-7-kodat XML ska normalt inte innehålla råa "<" tecken.'
        );
    }

    public function testXmlWriteNormalizesNumericKeysToItemTag(): void
    {
        $path = $this->tmpDir . 'numeric_keys.xml';

        Writer::xml(
            $path,
            [0 => 'zero'],
            rootName: 'root'
        );

        $raw = (string) file_get_contents($path);

        $this->assertStringContainsString('<item>zero</item>', $raw, 'Numeriska nycklar ska normaliseras till <item>.');
    }

    public function testCsvStreamCreatesMissingParentDirectories(): void
    {
        $nestedDir = $this->tmpDir . 'stream' . DIRECTORY_SEPARATOR . 'deep' . DIRECTORY_SEPARATOR . 'sub';
        $path = $nestedDir . DIRECTORY_SEPARATOR . 'data.csv';

        $this->assertDirectoryDoesNotExist($nestedDir);

        try {
            Writer::csvStream($path, function (callable $write): void {
                $write([1, 'Alice']);
            }, ['id', 'name'], ',');
        } catch (RuntimeException $e) {
            $this->fail(
                'csvStream() ska inte kasta här. Fick: ' . $e->getMessage()
            );
        }

        $this->assertDirectoryExists($nestedDir);
        $this->assertFileExists($path);
    }

    public function testTextWriteWithUtf8TargetEncodingDoesNotAlterBytes(): void
    {
        $path = $this->tmpDir . 'text_utf8_target_bytes.txt';

        // Sträng med ogiltig UTF-8-byte i början (ska behållas)
        $invalid = "\x80" . "foo";

        // targetEncoding = 'UTF-8' ska INTE trigga iconv-konverteringen i Writer::text()
        Writer::text($path, $invalid, 'UTF-8');

        $raw = (string) file_get_contents($path);

        // Om mutanten vänder villkoret så iconv körs, kan byten försvinna/ändras.
        $this->assertSame($invalid, $raw, 'Bytesen ska inte ha ändrats när targetEncoding är UTF-8 (Writer::text).');
    }

    public function testCsvStreamNormalizesNonScalarValuesToJsonInFile(): void
    {
        $path = $this->tmpDir . 'stream_nested.csv';

        Writer::csvStream($path, function (callable $write): void {
            $write([1, ['x' => 1, 'y' => 2]]);
        }, ['id', 'meta'], ',');

        $raw = (string) file_get_contents($path);

        $this->assertStringContainsString(
            '"{""x"":1,""y"":2}"',
            $raw,
            'Icke-skalära värden ska serialiseras till JSON och skrivas som citerad sträng i CSV (även i csvStream).'
        );

        $this->assertStringNotContainsString(
            'Array',
            $raw,
            'PHP:s standard "Array"-sträng får inte skrivas ut för icke-skalära värden (csvStream).'
        );
    }

    public function testCsvStreamWithUtf8TargetEncodingDoesNotAlterBytes(): void
    {
        $path = $this->tmpDir . 'stream_utf8_target_bytes.csv';

        // Sträng med ogiltig UTF-8-byte i början
        $invalid = "\x80" . "foo";

        // targetEncoding = 'UTF-8' ska *inte* trigga konverteringen (villkoret ska vara false)
        Writer::csvStream(
            $path,
            function (callable $write) use ($invalid): void {
                $write([1, $invalid]);
            },
            ['id', 'val'],
            ',',
            'UTF-8'
        );

        $raw = (string) file_get_contents($path);

        // Om mutanten vänder villkoret så konvertering körs, kan byten försvinna/ändras.
        $this->assertStringContainsString($invalid, $raw, 'Bytesen ska inte ha ändrats när targetEncoding är UTF-8 (csvStream).');
    }

    public function testJsonReadWrite(): void
    {
        $path = $this->tmpDir . 'data.json';
        $data = ['a' => 1, 'b' => ['x' => 'y'], 'utf' => 'ÅÄÖ'];
        Writer::json($path, $data, pretty: true);
        $this->assertFileExists($path);

        $read = Reader::json($path, assoc: true);
        $this->assertSame($data, $read);
    }

    public function testCsvDelimiterAutodetectRespectsTenLineLimit(): void
    {
        $path = $this->tmpDir . 'limit_10.csv';

        $lines = [];

        // Header + 9 rader med exakt EN ';' och inga kommatecken
        $lines[] = "id;n\n";
        for ($i = 1; $i <= 9; $i++) {
            $lines[] = $i . ';A' . $i . "\n";
        }

        // Rad 11 från filens början: MASSOR av kommatecken, men ingen ';'
        // (rad-ordning: 1 header + 9 semikolonrader = 10 rader, sedan denna = rad 11)
        $lines[] = str_repeat('x,', 200) . "end\n";

        file_put_contents($path, implode('', $lines));

        $rows = Reader::csv($path, delimiter: null, hasHeader: true);

        // Om detectDelimiter läser fler än 10 rader (mutanten <=),
        // kommer ',' vinna och headern mappas fel → dessa asserter faller.
        $this->assertGreaterThanOrEqual(2, \count($rows));

        $this->assertSame(['id' => 1, 'n' => 'A1'], $rows[0]);
        $this->assertSame(['id' => 2, 'n' => 'A2'], $rows[1]);
    }

    public function testCsvCreatesMissingParentDirectories(): void
    {
        $nestedDir = $this->tmpDir . 'deep' . DIRECTORY_SEPARATOR . 'sub';
        $path = $nestedDir . DIRECTORY_SEPARATOR . 'data.csv';

        $this->assertDirectoryDoesNotExist($nestedDir);

        $rows = [
            ['id' => 1, 'name' => 'Alice'],
            ['id' => 2, 'name' => 'Bob'],
        ];

        // Korrekt implementation (med ensureParentDir) ska skapa katalogerna
        // och skriva filen utan undantag.
        Writer::csv($path, $rows, headers: ['id', 'name'], delimiter: ',');

        $this->assertDirectoryExists($nestedDir);
        $this->assertFileExists($path);

        // Dubbelkolla att innehållet går att läsa tillbaka
        $read = Reader::csv($path, delimiter: ',', hasHeader: true);
        $this->assertSame($rows, $read);
    }

    public function testCsvNormalizesNonScalarValuesToJsonInFile(): void
    {
        $path = $this->tmpDir . 'nested_raw.csv';

        $rows = [
            ['id' => 1, 'meta' => ['x' => 1, 'y' => 2]],
        ];

        Writer::csv($path, $rows, headers: ['id', 'meta'], delimiter: ',');

        $raw = (string) file_get_contents($path);

        // fputcsv citerar fältet och escapear dubbla citationstecken, så
        // vi förväntar oss exakt den här formen.
        $this->assertStringContainsString(
            '"{""x"":1,""y"":2}"',
            $raw,
            'Icke-skalära värden ska serialiseras till JSON och skrivas som citerad sträng i CSV.'
        );

        // Och vi vill uttryckligen INTE se PHPs default-stringifiering av array
        $this->assertStringNotContainsString(
            'Array',
            $raw,
            'PHP:s standard "Array"-sträng får inte skrivas ut för icke-skalära värden.'
        );
    }

    public function testCsvWithUtf8TargetEncodingDoesNotAlterBytes(): void
    {
        $path = $this->tmpDir . 'utf8_target_bytes.csv';

        // Sträng med ogiltig UTF-8-byte i början
        $invalid = "\x80" . "foo";
        $rows = [
            ['id' => 1, 'val' => $invalid],
        ];

        // targetEncoding = 'UTF-8' ska *inte* trigga konverteringen
        Writer::csv($path, $rows, headers: ['id', 'val'], delimiter: ',', targetEncoding: 'UTF-8');

        $raw = (string) file_get_contents($path);

        // Säkerställ att den ogiltiga byte-sekvensen finns kvar i filen.
        // Om mutanten vänder villkoret så iconv körs, försvinner eller ändras den här byten.
        $this->assertStringContainsString($invalid, $raw, 'Bytesen ska inte ha ändrats när targetEncoding är UTF-8.');
    }

    public function testTextStreamRejectsNonPositiveChunkSize(): void
    {
        $path = $this->tmpDir . 'dummy.txt';
        file_put_contents($path, 'hello');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('chunkSize must be a positive integer');

        Reader::textStream($path, function (string $chunk): void {
            // ska aldrig nå hit
        }, 0);
    }

    public function testCsvReadTrimsCellWhitespace(): void
    {
        $path = $this->tmpDir . 'trim.csv';

        // Skriv manuellt en enkel CSV med extra mellanslag runt värden
        $content = "id, name , city \n"
            . "1,  Alice  ,  Stockholm  \n"
            . "2,  Bob  ,  Göteborg  \n";

        file_put_contents($path, $content);

        $rows = Reader::csv($path, delimiter: ',', hasHeader: true);

        $this->assertSame(
            [
                ['id' => 1, 'name' => 'Alice', 'city' => 'Stockholm'],
                ['id' => 2, 'name' => 'Bob', 'city' => 'Göteborg'],
            ],
            $rows
        );
    }

    public function testXmlWriteAndReadAssocWithMultipleTopLevelKeys(): void
    {
        $path = $this->tmpDir . 'data-multi.xml';
        $data = [
            'user' => [
                'id' => 1,
                'name' => 'Anna',
            ],
            'meta' => [
                'version' => '1.0',
                'env' => 'test',
            ],
        ];

        Writer::xml($path, $data, rootName: 'root');

        $arr = Reader::xml($path, assoc: true);

        $this->assertSame(
            [
                'user' => [
                    'id' => '1',
                    'name' => 'Anna',
                ],
                'meta' => [
                    'version' => '1.0',
                    'env' => 'test',
                ],
            ],
            $arr
        );
    }

    public function testValidateRowsSkipsInvalidRowsWhenOnErrorIsSkip(): void
    {
        $rows = [
            ['id' => '1', 'active' => 'yes'],
            ['active' => 'yes'], // saknar id => ska skippas
        ];

        $schema = [
            'required' => ['id', 'active'],
            'types' => ['id' => 'int', 'active' => 'bool'],
        ];

        $out = Writer::validateRows($rows, $schema, onError: 'skip');

        $this->assertSame([['id' => 1, 'active' => true]], $out);
    }

    public function testCsvDelimiterAutodetectWithManyLines(): void
    {
        $path = $this->tmpDir . 'multi_lines.csv';

        // Bygg en fil där:
        // - Första 9 data-raderna använder ';' (korrekt delimiter)
        // - Rad 10 har massor av ',' (för att trigga Assignment-mutanten)
        // - Raderna efteråt har ännu fler ',' (för att trigga lines--‑mutanten)
        $lines = [];

        // Header + 9 rader med ';'
        $lines[] = "id;n\n";
        for ($i = 1; $i <= 9; $i++) {
            $lines[] = $i . ';A' . $i . "\n";
        }

        // Rad 10: många kommatecken, men fortfarande semikolon som riktig separator
        $lines[] = "10;A10,extra,commas,here\n";

        // Massor av rader med kommatecken efter de 10 första raderna
        for ($i = 11; $i <= 40; $i++) {
            $lines[] = "x{$i},y{$i},z{$i}\n";
        }

        file_put_contents($path, implode('', $lines));

        $rows = Reader::csv($path, delimiter: null, hasHeader: true);

        // Kontrollera i alla fall de första få raderna efter headern.
        // Med korrekt autodetektering (';') ska de mappas snyggt till ['id' => ..., 'n' => ...].
        // Om detectDelimiter väljer ',' (mutanter 43 eller 44) blir headern "id;n"
        // och raderna får felaktiga nycklar/värden, så dessa asserter failar.
        $this->assertGreaterThanOrEqual(3, count($rows));

        $this->assertSame(
            ['id' => 1, 'n' => 'A1'],
            $rows[0],
        );
        $this->assertSame(
            ['id' => 2, 'n' => 'A2'],
            $rows[1],
        );
        $this->assertSame(
            ['id' => 9, 'n' => 'A9'],
            $rows[8],
        );
    }

    public function testCsvReadWriteWithHeaders(): void
    {
        $path = $this->tmpDir . 'data.csv';
        $rows = [
            ['id' => 1, 'name' => 'Alice', 'city' => 'Stockholm'],
            ['id' => 2, 'name' => 'Bob', 'city' => 'Göteborg'],
        ];
        Writer::csv($path, $rows, headers: null, delimiter: ',');
        $this->assertFileExists($path);

        $read = Reader::csv($path, delimiter: ',', hasHeader: true);
        $this->assertSame($rows, $read);
    }

    public function testTsvStreamReadWrite(): void
    {
        $path = $this->tmpDir . 'data.tsv';
        $headers = ['id', 'val'];

        // Låt Writer skriva header-raden via $headers-argumentet
        Writer::csvStream($path, function (callable $write): void {
            for ($i = 1; $i <= 3; $i++) {
                $write([$i, "v{$i}"]);
            }
        }, $headers, "\t");

        $collected = [];
        Reader::csvStream($path, function (array $row) use (&$collected): void {
            $collected[] = $row;
        }, "\t", hasHeader: true);

        $this->assertSame(
            [
                ['id' => 1, 'val' => 'v1'],
                ['id' => 2, 'val' => 'v2'],
                ['id' => 3, 'val' => 'v3'],
            ],
            $collected
        );
    }

    public function testCsvUsesEnsureParentDir(): void
    {
        // Skapa en FIL där katalogen borde vara
        $fileAsDir = $this->tmpDir . 'foo';
        file_put_contents($fileAsDir, 'x');

        $path = $fileAsDir . DIRECTORY_SEPARATOR . 'data.csv';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Kunde inte skapa katalog: ' . $fileAsDir);

        Writer::csv($path, [
            ['id' => 1, 'name' => 'Alice'],
        ]);
    }

    public function testCsvSerializesNestedArraysToJsonStrings(): void
    {
        $path = $this->tmpDir . 'nested.csv';

        $rows = [
            ['id' => 1, 'meta' => ['x' => 1, 'y' => 2]],
        ];

        Writer::csv($path, $rows, headers: null, delimiter: ',');

        $read = Reader::csv($path, delimiter: ',', hasHeader: true);

        $this->assertSame(
            [
                ['id' => 1, 'meta' => '{"x":1,"y":2}'],
            ],
            $read
        );
    }

    public function testCsvStreamSkipsEmptyLinesAndContinues(): void
    {
        $path = $this->tmpDir . 'with_empty_line.csv';

        // Header + rad 1 + TOM rad + rad 2
        $content = "id,name\n"
            . "1,Alice\n"
            . "\n"
            . "2,Bob\n";

        file_put_contents($path, $content);

        $rows = Reader::csv($path, delimiter: ',', hasHeader: true);

        $this->assertSame(
            [
                ['id' => 1, 'name' => 'Alice'],
                ['id' => 2, 'name' => 'Bob'],
            ],
            $rows
        );
    }

    public function testCsvDelimiterAutodetect(): void
    {
        $path = $this->tmpDir . 'semi.csv';
        $rows = [
            ['id' => 1, 'n' => 'A'],
            ['id' => 2, 'n' => 'B'],
        ];
        Writer::csv($path, $rows, headers: ['id', 'n'], delimiter: ';');
        $read = Reader::csv($path, delimiter: null, hasHeader: true);
        $this->assertSame(
            [
                ['id' => 1, 'n' => 'A'],
                ['id' => 2, 'n' => 'B'],
            ],
            $read
        );
    }

    public function testCsvDelimiterAutodetectIgnoresNoiseAfterFirstTenLines(): void
    {
        $path = $this->tmpDir . 'semi_noise_after_10.csv';

        $lines = [];

        // Header + 10 rader med ';' som korrekt delimiter
        $lines[] = "id;n\n";
        for ($i = 1; $i <= 10; $i++) {
            $lines[] = $i . ';A' . $i . "\n";
        }

        // Rad 11: massor av kommatecken (ska INTE påverka korrekt implementation)
        $lines[] = "garbage,with,many,commas,that,should,not,affect,delimiter\n";

        // Några fler rader med kommatecken bara för att förstärka "bruset"
        for ($i = 12; $i <= 30; $i++) {
            $lines[] = "x{$i},y{$i},z{$i}\n";
        }

        file_put_contents($path, implode('', $lines));

        $rows = Reader::csv($path, delimiter: null, hasHeader: true);

        // Om detectDelimiter läser fler än 10 rader (mutanten lines <= 10)
        // riskerar den att välja ',' som delimiter, och då blir mappningen fel.
        $this->assertGreaterThanOrEqual(3, count($rows));

        $this->assertSame(['id' => 1, 'n' => 'A1'], $rows[0]);
        $this->assertSame(['id' => 2, 'n' => 'A2'], $rows[1]);
        $this->assertSame(['id' => 10, 'n' => 'A10'], $rows[9]);
    }

    public function testNdjsonStreamReadWrite(): void
    {
        $path = $this->tmpDir . 'data.ndjson';
        $items = [
            ['i' => 1, 't' => 'a'],
            ['i' => 2, 't' => 'b'],
        ];
        Writer::ndjsonStream($path, function (callable $write) use ($items): void {
            foreach ($items as $it) {
                $write($it);
            }
        });

        $collected = [];
        Reader::ndjsonStream($path, function ($item) use (&$collected): void {
            $collected[] = $item;
        }, assoc: true);

        $this->assertSame($items, $collected);
    }

    public function testEncodingConversionIsoToUtf8AndBack(): void
    {
        $path = $this->tmpDir . 'latin1.tsv';
        $rows = [
            ['id', 'name'],
            [1, 'Åsa'],
            [2, 'Björn'],
        ];
        // Skriv som ISO-8859-1 TSV
        Writer::csv($path, [ ['id','name'], ['1','Åsa'], ['2','Björn'] ], delimiter: "\t", targetEncoding: 'ISO-8859-1');

        // Läs som UTF-8 med explicit källa, men behåll strängar
        $read = Reader::csv($path, delimiter: "\t", hasHeader: true, encoding: 'ISO-8859-1', castNumeric: false);
        $this->assertSame(
            [
                ['id' => '1', 'name' => 'Åsa'],
                ['id' => '2', 'name' => 'Björn'],
            ],
            $read
        );
    }

    public function testTargetEncodingUtf8DoesNotStripInvalidBytes(): void
    {
        $path = $this->tmpDir . 'utf8_invalid.csv';

        // Bygg en sträng med ogiltiga UTF-8-byte (t.ex. 0x80) som ska behållas
        $invalid = "\x80" . "foo";
        $rows = [
            ['id' => 1, 'val' => $invalid],
        ];

        // Skriv med targetEncoding = 'UTF-8' – korrekt implementation ska INTE
        // gå igenom konverteringsloopen och därmed inte köra iconv på $invalid.
        Writer::csv($path, $rows, headers: ['id', 'val'], delimiter: ',', targetEncoding: 'UTF-8');

        $raw = (string) file_get_contents($path);
        // Säkerställ att den ogiltiga byten finns kvar i filen
        $this->assertStringContainsString($invalid, $raw, 'Ogiltiga UTF-8-byte ska inte ha filtrerats bort när targetEncoding är UTF-8.');
    }

    public function testTextStreamAndWrite(): void
    {
        $path = $this->tmpDir . 'big.txt';
        $content = str_repeat("radix\n", 1000);
        Writer::text($path, $content);

        $buf = '';
        Reader::textStream($path, function (string $chunk) use (&$buf): void {
            $buf .= $chunk;
        }, 4096);

        $this->assertSame($content, $buf);
    }

    public function testTextWriterCreatesMissingParentDirectories(): void
    {
        $nestedDir = $this->tmpDir . 'nested' . DIRECTORY_SEPARATOR . 'sub';
        $path = $nestedDir . DIRECTORY_SEPARATOR . 'file.txt';

        // För säkerhets skull: katalogen ska inte finnas innan
        $this->assertDirectoryDoesNotExist($nestedDir);

        // Korrekt implementation ska skapa katalogerna via ensureParentDir()
        // och lyckas skriva filen utan undantag.
        Writer::text($path, 'hello world');

        $this->assertDirectoryExists($nestedDir);
        $this->assertFileExists($path);
        $this->assertSame('hello world', file_get_contents($path));
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

    public function testXmlWriteAndReadAssoc(): void
    {
        $path = $this->tmpDir . 'data.xml';
        $data = [
            'user' => [
                'id' => 1,
                'name' => 'Anna',
                'active' => true,
            ],
        ];

        // Skriv XML
        Writer::xml($path, $data, rootName: 'root');
        $this->assertFileExists($path);

        // Läs som assoc-array
        $arr = Reader::xml($path, assoc: true);
        $this->assertSame(
            ['user' => ['id' => '1', 'name' => 'Anna', 'active' => 'true']],
            $arr
        );
    }

    public function testXmlWriteAndReadSimpleXml(): void
    {
        $path = $this->tmpDir . 'data2.xml';
        $data = [
            'items' => [
                'item' => [
                    ['id' => 1, 'label' => 'A'],
                    ['id' => 2, 'label' => 'B'],
                ],
            ],
        ];

        Writer::xml($path, $data, rootName: 'root');
        $xml = Reader::xml($path, assoc: false);

        $this->assertInstanceOf(SimpleXMLElement::class, $xml);
        $this->assertSame('1', (string) $xml->items->item->item[0]->id);
        $this->assertSame('B', (string) $xml->items->item->item[1]->label);
    }

    public function testNdjsonStreamReleasesFileLockWhenCallbackThrows(): void
    {
        $path = $this->tmpDir . 'boom_lock.ndjson';

        try {
            Writer::ndjsonStream($path, function (callable $write): void {
                $write(['i' => 1]);
                throw new RuntimeException('boom');
            });

            $this->fail('ndjsonStream ska kasta när callbacken kastar.');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $h = fopen($path, 'rb');
        $this->assertNotFalse($h);

        try {
            $locked = flock($h, LOCK_EX | LOCK_NB);
            $this->assertTrue($locked, 'Fil-låset ska vara släppt efter exception (finally måste ha körts).');
        } finally {
            flock($h, LOCK_UN);
            fclose($h);
        }
    }

    public function testNdjsonStreamWhenPrettyFalseDoesNotHexEncodeTags(): void
    {
        $path = $this->tmpDir . 'no_pretty_hex.ndjson';

        Writer::ndjsonStream($path, function (callable $write): void {
            $write(['s' => '<tag>']);
        }, targetEncoding: null, pretty: false);

        $raw = (string) file_get_contents($path);

        $this->assertStringContainsString('<tag>', $raw, 'När pretty=false ska < inte hex-encodas (JSON_HEX_TAG får inte vara på).');
        $this->assertStringNotContainsString('\u003C', $raw, 'Mutanten (pretty false => 1) skulle hex-encoda < till \\u003C.');
    }

    public function testNdjsonStreamJsonEncodeThrowsOnInvalidValue(): void
    {
        $path = $this->tmpDir . 'throw_on_error.ndjson';

        $this->expectException(JsonException::class);

        Writer::ndjsonStream($path, function (callable $write): void {
            // INF kan inte JSON-encodas → ska kasta när JSON_THROW_ON_ERROR är aktivt.
            $write(['bad' => INF]);
        });
    }

    public function testNdjsonStreamWithUtf8TargetEncodingDoesNotAlterBytes(): void
    {
        // NDJSON går via json_encode() med JSON_THROW_ON_ERROR, så ogiltig UTF-8 kan inte testas här.
        // Istället verifierar vi att iconv() inte anropas när targetEncoding är UTF-8.
        $path = $this->tmpDir . 'ndjson_utf8_no_iconv.ndjson';

        WriterIconvSpy::reset();

        Writer::ndjsonStream(
            $path,
            function (callable $write): void {
                $write(['v' => 'Åsa']); // giltig UTF-8
            },
            targetEncoding: 'UTF-8',
            pretty: false
        );

        $this->assertSame(
            [],
            WriterIconvSpy::$calls,
            'iconv() ska inte anropas när targetEncoding är UTF-8 (ndjsonStream).'
        );

        $raw = (string) file_get_contents($path);
        $this->assertStringContainsString('Åsa', $raw);
    }

    public function testNdjsonStreamConvertsToIso88591WhenTargetEncodingIsNotUtf8(): void
    {
        $path = $this->tmpDir . 'ndjson_latin1.ndjson';

        Writer::ndjsonStream(
            $path,
            function (callable $write): void {
                $write(['name' => 'Åsa']);
            },
            targetEncoding: 'ISO-8859-1',
            pretty: false
        );

        $raw = (string) file_get_contents($path);

        // ISO-8859-1: 'Å' = 0xC5 (single byte)
        $this->assertStringContainsString("\xC5", $raw, 'NDJSON ska innehålla ISO-8859-1-byten för Å när targetEncoding=ISO-8859-1.');
        // UTF-8 för 'Å' är 0xC3 0x85
        $this->assertStringNotContainsString("\xC3\x85", $raw, 'NDJSON ska inte innehålla UTF-8-bytesekvensen för Å efter konvertering till ISO-8859-1.');
    }

    public function testJsonToCsvWritesReadableCsv(): void
    {
        $path = $this->tmpDir . 'from_json_to_csv.csv';

        $rows = [
            ['id' => 1, 'name' => 'Alice'],
            ['id' => 2, 'name' => 'Bob'],
        ];

        Writer::jsonToCsv($path, $rows, headers: ['id', 'name'], delimiter: ',');

        $read = Reader::csv($path, delimiter: ',', hasHeader: true);
        $this->assertSame($rows, $read);
    }

    public function testValidateRowsAppliesRequiredDefaultsTrimNullableAndTypes(): void
    {
        $rows = [
            ['id' => ' 1 ', 'active' => 'yes', 'note' => '  hello  '],
            ['id' => '2', 'active' => 'no', 'note' => ''],
        ];

        $schema = [
            'required' => ['id', 'active'],
            'defaults' => ['score' => 10],
            'trim' => true,
            'nullable' => ['note'],
            'types' => [
                'id' => 'int',
                'active' => 'bool',
                'note' => 'string',
                'score' => 'int',
            ],
        ];

        $out = Writer::validateRows($rows, $schema, onError: 'throw');

        $this->assertSame(
            [
                ['id' => 1, 'active' => true, 'note' => 'hello', 'score' => 10],
                ['id' => 2, 'active' => false, 'note' => null, 'score' => 10],
            ],
            $out
        );
    }

    public function testValidateRowsTrimStringZeroIsFalseSoWhitespaceIsPreserved(): void
    {
        $rows = [
            ['note' => '  hello  '],
        ];

        $schema = [
            'required' => ['note'],
            'trim' => '0', // viktigt: (bool) '0' === false
            'types' => [
                'note' => 'string',
            ],
        ];

        $out = Writer::validateRows($rows, $schema, onError: 'throw');

        // Om CastBool-mutanten lever blir $trim = '0' (truthy) och trim() körs => 'hello'
        $this->assertSame([['note' => '  hello  ']], $out);
    }

    public function testValidateRowsNullableAcceptsNonScalarNamesByJsonEncodingAndAllowsEmptyStringForRequiredField(): void
    {
        $key = '{"x":1}';

        $rows = [
            [$key => ''], // finns men är tom sträng
        ];

        $schema = [
            'required' => [$key],
            'nullable' => [
                ['x' => 1], // icke-skalär => ska gå via json_encode() => '{"x":1}'
            ],
        ];

        // Korrekt: required uppfyllt och '' tillåts eftersom fältet är nullable.
        // Mutant #17/#18 ger 'Array' istället för '{"x":1}' i nullable-listan => kastar.
        // Mutant #20 vänder logiken => kastar när fältet *är* nullable.
        $out = Writer::validateRows($rows, $schema, onError: 'throw');

        $this->assertSame($rows, $out);
    }

    public function testCsvStreamThrowsWhenFileMissing(): void
    {
        $missing = $this->tmpDir . 'does-not-exist.csv';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Filen finns inte: ' . $missing);

        \Radix\File\Reader::csvStream(
            $missing,
            static function (array $row): void {
                // no-op
            }
        );
    }

    public function testTextStreamThrowsWhenFileMissing(): void
    {
        $missing = $this->tmpDir . 'does-not-exist.txt';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Filen finns inte: ' . $missing);

        \Radix\File\Reader::textStream(
            $missing,
            static function (string $chunk): void {
                // no-op
            }
        );
    }

    public function testTextStreamDefaultChunkSizeIs8192AndUtf8DoesNotConvert(): void
    {
        $path = $this->tmpDir . 'bytes.bin';

        // Bygg content:
        // - exakt 8192 'a' så vi kan verifiera första chunk-storleken när default används
        // - plus en ogiltig UTF-8-sekvens som *skulle* ändras om iconv() körs i onödan
        $invalidUtf8 = "\xC3\x28"; // invalid 2-byte sequence (C3 must be followed by 80..BF)
        $content = str_repeat('a', 8192) . str_repeat('b', 8192) . $invalidUtf8;

        \Radix\File\Writer::text($path, $content);

        $chunks = [];
        \Radix\File\Reader::textStream(
            $path,
            static function (string $chunk) use (&$chunks): void {
                $chunks[] = $chunk;
            },
            // viktigt: använd default chunkSize genom att INTE skicka param #3
            // men vi vill testa encoding-beteendet => skicka encoding som fjärde param
            encoding: 'UTF-8'
        );

        $this->assertCount(3, $chunks, 'Default chunkSize=8192 ska ge 3 chunks för 8192+8192+2 bytes.');
        $this->assertSame(8192, strlen($chunks[0]), 'Första chunk ska vara exakt 8192 bytes med default chunkSize.');
        $this->assertSame($content, implode('', $chunks), 'När encoding=UTF-8 ska ingen konvertering ske (bytes ska bevaras).');
    }

    public function testNormalizeCsvCellCastsScalarToStringSoTrimNeverTypeErrors(): void
    {
        $rm = new \ReflectionMethod(\Radix\File\Reader::class, 'normalizeCsvCell');
        $rm->setAccessible(true);

        // Om mutanten tar bort (string)-casten blir trim($s) en TypeError när $v är int/bool.
        $resultInt = $rm->invoke(null, 123, null, false);
        $this->assertSame('123', $resultInt);

        $resultBool = $rm->invoke(null, true, null, false);
        $this->assertSame('1', $resultBool);
    }

    public function testNdjsonStreamDefaultAssocIsTrue(): void
    {
        $path = $this->tmpDir . 'default_assoc.ndjson';

        file_put_contents($path, "{\"a\":1}\n{\"b\":2}\n");

        $items = [];
        Reader::ndjsonStream($path, function ($item) use (&$items): void {
            $items[] = $item;
        });

        $this->assertCount(2, $items);
        $this->assertIsArray($items[0], 'Default assoc ska vara true, alltså array.');
        $this->assertSame(['a' => 1], $items[0]);
        $this->assertSame(['b' => 2], $items[1]);
    }

    public function testNdjsonStreamThrowsWhenFileMissing(): void
    {
        $missing = $this->tmpDir . 'does-not-exist.ndjson';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Filen finns inte: ' . $missing);

        Reader::ndjsonStream($missing, static function ($item): void {
            // no-op
        });
    }

    public function testEnsureFileReadableMissingFileErrorMessageContainsOriginalPathWhenRealpathFails(): void
    {
        $missing = $this->tmpDir . 'no_such_file_' . bin2hex(random_bytes(4)) . '.txt';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Filen finns inte: ' . $missing);

        // Triggar ensureFileReadable() direkt
        Reader::text($missing);
    }

    public function testNdjsonStreamAllowsJsonDepth511(): void
    {
        $path = $this->tmpDir . 'depth_511.ndjson';

        $depth = 511;
        $nested = '0';
        for ($i = 0; $i < $depth; $i++) {
            $nested = '[' . $nested . ']';
        }

        file_put_contents($path, $nested . "\n");

        $items = [];
        Reader::ndjsonStream(
            $path,
            static function ($item) use (&$items): void {
                $items[] = $item;
            },
            encoding: 'UTF-8',
            assoc: true
        );

        $this->assertCount(1, $items, 'En rad ska decodas utan JsonException vid 511 nästlingar.');
        $this->assertIsArray($items[0]);
    }

    public function testNdjsonStreamRejectsJsonDepth512WithJsonException(): void
    {
        $path = $this->tmpDir . 'depth_512.ndjson';

        $depth = 512;
        $nested = '0';
        for ($i = 0; $i < $depth; $i++) {
            $nested = '[' . $nested . ']';
        }

        file_put_contents($path, $nested . "\n");

        $this->expectException(\JsonException::class);

        // Original: depth=512 => ska kasta för 512-nästlingar i din miljö.
        // Mutant #17 (513) skulle INTE kasta => testet failar och dödar mutanten.
        Reader::ndjsonStream(
            $path,
            static function ($item): void {
                // no-op
            },
            encoding: 'UTF-8',
            assoc: true
        );
    }

    public function testNdjsonStreamRejectsJsonDepth513WithJsonException(): void
    {
        $path = $this->tmpDir . 'depth_513.ndjson';

        $depth = 513;
        $nested = '0';
        for ($i = 0; $i < $depth; $i++) {
            $nested = '[' . $nested . ']';
        }

        file_put_contents($path, $nested . "\n");

        $this->expectException(\JsonException::class);

        // Korrekt kod: depth=512 => ska kasta för 513-nästlingar.
        // Mutant #17 (513) skulle INTE kasta => testet failar och dödar mutanten.
        Reader::ndjsonStream(
            $path,
            static function ($item): void {
                // no-op
            },
            encoding: 'UTF-8',
            assoc: true
        );
    }

    public function testCsvDelimiterAutodetectTreatsEmptyStringPreferredDelimiterAsNull(): void
    {
        $path = $this->tmpDir . 'preferred_empty_autodetect.csv';

        // Semikolon är korrekt delimiter här
        file_put_contents($path, "id;n\n1;A1\n2;A2\n");

        // KRITISKT: preferred delimiter = '' ska INTE returneras direkt,
        // utan ska trigga autodetektering (förväntat ';').
        $rows = Reader::csv($path, delimiter: '', hasHeader: true);

        $this->assertSame(['id' => 1, 'n' => 'A1'], $rows[0]);
        $this->assertSame(['id' => 2, 'n' => 'A2'], $rows[1]);
    }

    public function testNdjsonStreamClosesFileHandleWhenCallbackThrowsAndDoesNotCallIconvForUtf8(): void
    {
        $path = $this->tmpDir . 'ndjson_reader_finally.ndjson';

        // Innehåller:
        // - en giltig rad
        // - en tom rad som bara är "\n" (ska skippas pga rtrim + === '')
        // - en giltig rad till (ska aldrig nås pga exception i callbacken)
        file_put_contents($path, "{\"i\":1}\n\n{\"i\":2}\n");

        ReaderFcloseSpy::reset();
        WriterIconvSpy::reset();

        try {
            Reader::ndjsonStream(
                $path,
                static function ($item): void {
                    throw new RuntimeException('boom');
                },
                encoding: 'UTF-8'
            );
            $this->fail('ndjsonStream ska kasta när callbacken kastar.');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame(
            1,
            ReaderFcloseSpy::$calls,
            'fclose() måste anropas exakt en gång även när callbacken kastar (finally måste köras).'
        );

        $this->assertSame(
            [],
            WriterIconvSpy::$calls,
            'iconv() ska inte anropas när encoding är UTF-8 (Reader::ndjsonStream).'
        );
    }

    public function testNdjsonStreamWithNonUtf8EncodingInvokesIconvAndDecodesToUtf8(): void
    {
        $path = $this->tmpDir . 'utf7.ndjson';

        // JSON i UTF-7 som representerar {"name":"Å"}.
        // "Å" (U+00C5) i UTF-7 blir +AMU-
        file_put_contents($path, '{"name":"+AMU-"}' . "\n");

        WriterIconvSpy::reset();

        $items = [];
        Reader::ndjsonStream(
            $path,
            function ($item) use (&$items): void {
                $items[] = $item;
            },
            encoding: 'UTF-7',
            assoc: true
        );

        $this->assertCount(1, $items);
        $this->assertSame('Å', $items[0]['name'], 'Reader ska iconv-konvertera från UTF-7 till UTF-8 innan json_decode.');

        $this->assertNotEmpty(WriterIconvSpy::$calls, 'iconv() måste anropas när encoding inte är UTF-8.');
        $this->assertSame('UTF-7', WriterIconvSpy::$calls[0]['from']);
        $this->assertSame('UTF-8//IGNORE', WriterIconvSpy::$calls[0]['to']);
        $this->assertSame('{"name":"+AMU-"}', WriterIconvSpy::$calls[0]['input']);
    }

    public function testCsvPreferredDelimiterOverridesAutodetect(): void
    {
        $path = $this->tmpDir . 'preferred_overrides.csv';

        // Filen är semikolon-separerad
        file_put_contents($path, "id;n\n1;A1\n2;A2\n");

        // Men vi skickar explicit delimiter="," och kräver att den respekteras.
        // Korrekt beteende: hela headern blir en enda kolumn "id;n"
        // (d.v.s. INTE autodetektera ';').
        $rows = Reader::csv($path, delimiter: ',', hasHeader: true);

        $this->assertSame(
            [
                ['id;n' => '1;A1'],
                ['id;n' => '2;A2'],
            ],
            $rows,
            'När delimiter anges explicit måste detectDelimiter() returnera den (dödar ReturnRemoval-mutanten).'
        );
    }

    public function testCsvDelimiterAutodetectCanChooseCommaWhenCommaIsDominant(): void
    {
        $path = $this->tmpDir . 'autodetect_comma.csv';

        // Bygg en fil där ',' tydligt dominerar men där det också finns lite ';'
        // så att en mutant som saknar ',' i candidates får ett "alternativ" att välja.
        $lines = [];
        $lines[] = "id,name\n";
        for ($i = 1; $i <= 9; $i++) {
            $lines[] = $i . ",Alice{$i};noise\n"; // 1 komma + 1 semikolon per rad
        }
        // Extra rad med många kommatecken för att säkra att ',' vinner stort
        $lines[] = "10," . str_repeat("x,", 50) . "end\n";

        file_put_contents($path, implode('', $lines));

        $rows = Reader::csv($path, delimiter: null, hasHeader: true);

        // Med korrekt autodetektering (',') får vi nycklarna 'id' och 'name'
        $this->assertSame(1, $rows[0]['id']);
        $this->assertSame('Alice1;noise', $rows[0]['name']);

        // Om ',' saknas i candidates (ArrayItemRemoval) blir delimiter fel
        // och headern blir typ "id,name" som EN kolumn => då saknas 'id'/'name' och asserterna faller.
        $this->assertArrayHasKey('id', $rows[0]);
        $this->assertArrayHasKey('name', $rows[0]);
    }

    public function testXmlToArrayAllowsDepth511ButRejectsDepth512(): void
    {
        $makeXml = static function (int $depth): string {
            $open = str_repeat('<n>', $depth);
            $close = str_repeat('</n>', $depth);
            return '<?xml version="1.0" encoding="UTF-8"?><root>' . $open . 'x' . $close . '</root>';
        };

        // 511 ska fungera
        $p511 = $this->tmpDir . 'xml_depth_511.xml';
        file_put_contents($p511, $makeXml(511));

        $arr511 = Reader::xml($p511, assoc: true);
        $this->assertIsArray($arr511, 'assoc=true ska ge array.');
        $this->assertArrayHasKey('n', $arr511, 'Den nästlade strukturen ska finnas i arrayen (indikerar att xmlToArray() lyckades).');

        // 512 ska kasta (i din runtime) p.g.a. json_decode depth
        $p512 = $this->tmpDir . 'xml_depth_512.xml';
        file_put_contents($p512, $makeXml(512));

        $this->expectException(\JsonException::class);
        Reader::xml($p512, assoc: true);
    }

    public function testCsvReadWithEncodingUtf8DoesNotRunIconvAndDoesNotStripInvalidUtf8Bytes(): void
    {
        $path = $this->tmpDir . 'invalid_utf8_cell.csv';

        // Ogiltig UTF-8-sekvens: C3 måste följas av 80..BF, men här kommer 28.
        $invalid = "\xC3\x28";

        // CSV-rad med invalid bytes i ett fält
        file_put_contents($path, "id,val\n1," . $invalid . "\n");

        $rows = Reader::csv($path, delimiter: ',', hasHeader: true, encoding: 'UTF-8', castNumeric: false);

        $this->assertSame('1', $rows[0]['id']);
        $this->assertSame($invalid, $rows[0]['val'], 'När encoding=UTF-8 ska ingen iconv-konvertering ske och bytes ska bevaras (dödar !== -1-mutanten).');
    }

    public function testWriterJsonDefaultPrettyIsTrueAndProducesPrettyPrintedOutput(): void
    {
        $path = $this->tmpDir . 'default_pretty.json';

        // Anropa utan pretty-argument => default ska vara true
        Writer::json($path, ['a' => 1, 'b' => ['c' => 2]]);

        $raw = (string) file_get_contents($path);

        // Pretty print ger typiskt newlines + indentering.
        $this->assertStringContainsString("\n", $raw, 'Default pretty=true ska ge radbrytningar.');
        $this->assertStringContainsString('  "b"', $raw, 'Default pretty=true ska ge indentering (två mellanslag i JSON_PRETTY_PRINT).');
    }

    public function testWriterJsonDefaultFlagsDoNotHexEncodeTags(): void
    {
        $path = $this->tmpDir . 'default_flags.json';

        Writer::json($path, ['s' => '<tag>']);

        $raw = (string) file_get_contents($path);

        // Om flags muteras till -1 så aktiveras JSON_HEX_TAG och '<' blir \u003C
        $this->assertStringContainsString('<tag>', $raw, 'Default flags=0 ska inte hex-encoda < (JSON_HEX_TAG får inte vara aktivt av misstag).');
        $this->assertStringNotContainsString('\u003C', $raw, 'Om JSON_HEX_TAG råkar vara på skulle < bli \\u003C.');
    }

    public function testCsvDelimiterAutodetectAccumulatesCountsAcrossLinesNotJustLastLine(): void
    {
        $path = $this->tmpDir . 'detect_delimiter_accumulate.csv';

        $lines = [];
        $lines[] = "id;n\n";

        // 9 rader: 21 semikolon per rad => 189 semikolon (+1 i header = 190)
        for ($i = 1; $i <= 9; $i++) {
            $lines[] = $i . ';' . str_repeat('a;', 20) . "end\n";
        }

        // Rad 10: många komman, men färre än 190 totalt
        $lines[] = "10," . str_repeat("x,", 100) . "end\n"; // ca 101 komman

        file_put_contents($path, implode('', $lines));

        $rows = Reader::csv($path, delimiter: null, hasHeader: true);

        // Korrekt delimiter=';' => nycklarna blir 'id' och 'n'
        $this->assertArrayHasKey('id', $rows[0]);
        $this->assertArrayHasKey('n', $rows[0]);

        // Mutanten väljer ',' => headern blir en kolumn "id;n" och 'id' saknas
        $this->assertSame(1, $rows[0]['id']);
    }

    public function testWriterCsvWritesBooleanTrueAsOneNotStringTrue(): void
    {
        $path = $this->tmpDir . 'writer_bool.csv';

        Writer::csv(
            $path,
            [
                ['id' => 1, 'active' => true],
            ],
            headers: ['id', 'active'],
            delimiter: ',',
            targetEncoding: null
        );

        $raw = (string) file_get_contents($path);

        // Korrekt: true går via scalar-branch och fputcsv skriver normalt "1"
        $this->assertStringNotContainsString('true', strtolower($raw), 'Bool true ska inte serialiseras som texten "true" i CSV.');
        $this->assertStringContainsString("1,1", str_replace(["\r\n", "\n", "\r"], "\n", trim($raw)), 'Bool true ska serialiseras som 1 i CSV-raden.');
    }

    public function testWriterCsvWritesInfFloatAsInfAndDoesNotSilentlyBlankIt(): void
    {
        $path = $this->tmpDir . 'writer_inf.csv';

        Writer::csv(
            $path,
            [
                ['v' => INF],
            ],
            headers: ['v'],
            delimiter: ',',
            targetEncoding: null
        );

        $raw = (string) file_get_contents($path);

        // Korrekt: INF går via float-branch och skrivs ut; mutanten hamnar i json_encode(INF) => false => ''.
        $this->assertStringContainsString('INF', $raw, 'INF ska inte bli tom sträng i CSV (indikerar felaktig json_encode-väg).');
    }

    public function testWriterCsvWithNonUtf8TargetEncodingCastsNonStringsBeforeIconv(): void
    {
        $path = $this->tmpDir . 'writer_iconv_cast.csv';

        // Detta ska inte kasta TypeError. Om mutanten vänder villkoret,
        // skickas bool direkt in i iconv() och då brukar det bli TypeError.
        Writer::csv(
            $path,
            [
                ['v' => true],
            ],
            headers: ['v'],
            delimiter: ',',
            targetEncoding: 'ISO-8859-1'
        );

        $this->assertFileExists($path);

        $raw = (string) file_get_contents($path);
        $this->assertNotSame('', $raw);
    }

    public function testWriterJsonPrettyStillDoesNotEscapeSlashesOrUnicodeAndEndsWithNewline(): void
    {
        $path = $this->tmpDir . 'writer_json_pretty_flags.json';

        Writer::json($path, ['url' => 'http://example.com/åäö'], pretty: true);

        $raw = (string) file_get_contents($path);

        // Mutant #20: skulle börja med "\n"
        $this->assertNotSame("\n", $raw[0] ?? '', 'JSON-filen får inte börja med en newline.');

        // Mutant #20: vi kräver även trailing newline
        $this->assertStringEndsWith(PHP_EOL, $raw, 'Writer::json() ska avsluta med en newline.');

        // Mutant #19 och #17/#18: om opts tappas escapes slashes/unicode
        $this->assertStringNotContainsString('\/', $raw, 'Slashes ska inte escap:as (JSON_UNESCAPED_SLASHES ska vara aktivt).');

        $lower = strtolower($raw);
        $this->assertStringNotContainsString('\u00e5', $lower, 'Unicode ska inte escap:as (JSON_UNESCAPED_UNICODE ska vara aktivt).');
        $this->assertStringNotContainsString('\u00e4', $lower, 'Unicode ska inte escap:as (JSON_UNESCAPED_UNICODE ska vara aktivt).');
        $this->assertStringNotContainsString('\u00f6', $lower, 'Unicode ska inte escap:as (JSON_UNESCAPED_UNICODE ska vara aktivt).');

        // Pretty ska fortfarande synas (så testet verkligen går via pretty-grenen)
        $this->assertStringContainsString("\n", $raw);
        $this->assertStringContainsString('  "url"', $raw);
    }

    public function testWriterJsonNonPrettyStillDoesNotEscapeSlashesOrUnicodeAndEndsWithNewline(): void
    {
        $path = $this->tmpDir . 'writer_json_compact_flags.json';

        Writer::json($path, ['url' => 'http://example.com/åäö'], pretty: false);

        $raw = (string) file_get_contents($path);

        // Trailing newline (mutant #20)
        $this->assertStringEndsWith(PHP_EOL, $raw);

        // Flags måste gälla även utan pretty (mutant #17/#18)
        $this->assertStringNotContainsString('\/', $raw);

        $lower = strtolower($raw);
        $this->assertStringNotContainsString('\u00e5', $lower);
        $this->assertStringNotContainsString('\u00e4', $lower);
        $this->assertStringNotContainsString('\u00f6', $lower);

        // Och vi verifierar att det faktiskt är "compact-ish" (ingen pretty-indentering)
        $this->assertStringNotContainsString("\n  ", $raw);
    }

    public function testWriterCsvAsciiTargetEncodingUsesTranslitSoConversionDoesNotFail(): void
    {
        $path = $this->tmpDir . 'writer_ascii_translit.csv';

        // Ska fungera tack vare //TRANSLIT.
        Writer::csv(
            $path,
            [
                ['name' => 'Å'],
            ],
            headers: ['name'],
            delimiter: ',',
            targetEncoding: 'US-ASCII'
        );

        $this->assertFileExists($path);

        $raw = (string) file_get_contents($path);
        $this->assertNotSame('', $raw);

        // UTF-8 bytes för 'Å' får inte finnas kvar i ASCII-output
        $this->assertStringNotContainsString("\xC3\x85", $raw);
    }

    public function testWriterCsvShouldCollectHeadersOnlyWhenAssocAndHeadersEmpty(): void
    {
        $rm = new \ReflectionMethod(\Radix\File\Writer::class, 'shouldCollectHeaders');
        $rm->setAccessible(true);

        // Om mutanten ändrar && -> || så blir detta true och testet failar (dödar mutanten).
        $this->assertFalse($rm->invoke(null, false, []), 'List-rader (isAssoc=false) ska aldrig trigga header-collect.');

        $this->assertTrue($rm->invoke(null, true, []), 'Assoc-rader med tom headers ska trigga header-collect.');
        $this->assertFalse($rm->invoke(null, true, ['id']), 'Assoc-rader med explicit headers ska inte trigga header-collect.');
    }

    public function testWriterCsvClosesFileHandleWhenWriteThrows(): void
    {
        $path = $this->tmpDir . 'writer_csv_boom.csv';

        FcloseSpy::reset();

        try {
            Writer::csv(
                $path,
                [
                    ['name' => 'x'],
                ],
                headers: ['name'],
                delimiter: ',',
                targetEncoding: 'THIS-ENCODING-DOES-NOT-EXIST'
            );

            $this->fail('Writer::csv ska kasta när iconv misslyckas.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Kunde inte konvertera cell', $e->getMessage());
        }

        $this->assertSame(
            1,
            FcloseSpy::$calls,
            'fclose() måste anropas exakt en gång även när Writer::csv kastar.'
        );
    }

    public function testWriterCsvDoesNotWriteHeaderRowForListRowsEvenWhenHeadersProvided(): void
    {
        $path = $this->tmpDir . 'writer_csv_no_header_for_list.csv';

        // List-rader (inte assoc)
        Writer::csv(
            $path,
            [
                [1, 'Alice'],
                [2, 'Bob'],
            ],
            headers: ['id', 'name'],
            delimiter: ','
        );

        $raw = (string) file_get_contents($path);
        $this->assertNotSame('', $raw);

        // Mutanten (||) skulle skriva "id,name" som första rad.
        $firstLine = strtok(str_replace("\r\n", "\n", $raw), "\n");
        $this->assertNotSame('id,name', $firstLine);
    }

    public function testWriterCsvScalarPredicateTreatsBoolIntFloatAndStringAsScalar(): void
    {
        $rm = new \ReflectionMethod(\Radix\File\Writer::class, 'isCsvScalarValue');
        $rm->setAccessible(true);

        // Dessa måste vara true. LogicalOr-mutanter gör minst en av dessa false.
        $this->assertTrue($rm->invoke(null, true));
        $this->assertTrue($rm->invoke(null, 1));
        $this->assertTrue($rm->invoke(null, 1.5));
        $this->assertTrue($rm->invoke(null, 'x'));

        // Och något icke-scalar ska vara false.
        $this->assertFalse($rm->invoke(null, ['x' => 1]));
    }

    public function testDetectDelimiterAccumulatesAcrossFirstTenLinesSoLastLineDoesNotOverride(): void
    {
        $path = $this->tmpDir . 'detect_delimiter_assignment_kill.csv';

        $lines = [];

        // 1) Header med ';' så vi kan läsa assoc sen
        $lines[] = "id;n\n";

        // 2) 8 rader med många ';' och INGA komman
        for ($i = 1; $i <= 8; $i++) {
            $lines[] = $i . ';' . str_repeat('a;', 30) . "end\n"; // många semikolon
        }

        // 3) Rad 10 (inom första 10 raderna): många komman, NOLL semikolon
        // Korrekt implementation summerar => ';' vinner ändå pga raderna ovan.
        // Mutanten (=) tittar i praktiken bara på denna => ',' vinner.
        $lines[] = 'x,' . str_repeat('y,', 120) . "end\n";

        file_put_contents($path, implode('', $lines));

        $rows = Reader::csv($path, delimiter: null, hasHeader: true);

        $this->assertGreaterThanOrEqual(1, count($rows));

        // Korrekt delimiter=';' => nycklarna 'id' och 'n' finns.
        // Mutanten väljer ',' => headern blir en kolumn "id;n" och 'id' saknas.
        $this->assertArrayHasKey('id', $rows[0]);
        $this->assertArrayHasKey('n', $rows[0]);
        $this->assertSame(1, $rows[0]['id']);
    }

    public function testWriterCsvStreamClosesFileHandleWhenCallbackThrows(): void
    {
        $path = $this->tmpDir . 'writer_csv_stream_boom.csv';

        FcloseSpy::reset();

        try {
            Writer::csvStream(
                $path,
                static function (callable $write): void {
                    $write([1, 'Alice']);
                    throw new RuntimeException('boom');
                },
                headers: ['id', 'name'],
                delimiter: ','
            );

            $this->fail('csvStream ska kasta när callbacken kastar.');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame(
            1,
            FcloseSpy::$calls,
            'fclose() måste anropas exakt en gång även när Writer::csvStream kastar (finally måste köras).'
        );
    }

    public function testWriterCsvStreamWithNullHeadersDoesNotAttemptToWriteHeaderRow(): void
    {
        $path = $this->tmpDir . 'writer_csv_stream_null_headers.csv';

        // Om mutanten (&& -> ||) lever kommer den försöka $writeRow(null) och då får vi TypeError.
        try {
            Writer::csvStream(
                $path,
                static function (callable $write): void {
                    $write([1, 'Alice']);
                },
                headers: null,
                delimiter: ','
            );
        } catch (\TypeError $e) {
            $this->fail('csvStream får inte försöka skriva header när headers=null (annars TypeError). Fick: ' . $e->getMessage());
        }

        $this->assertFileExists($path);
    }

    public function testNdjsonStreamCreatesMissingParentDirectories(): void
    {
        $dir = $this->tmpDir . 'ndjson' . DIRECTORY_SEPARATOR . 'deep' . DIRECTORY_SEPARATOR;
        $path = $dir . 'data.ndjson';

        $this->assertDirectoryDoesNotExist($dir);

        Writer::ndjsonStream($path, static function (callable $write): void {
            $write(['id' => 1]);
        });

        $this->assertDirectoryExists($dir);
        $this->assertFileExists($path);
    }

    public function testNdjsonStreamAsciiTargetEncodingUsesTranslitSoConversionDoesNotFail(): void
    {
        $path = $this->tmpDir . 'ndjson_ascii_translit.ndjson';

        // Utan //TRANSLIT brukar iconv(UTF-8 -> US-ASCII) faila för 'Å' => exception.
        Writer::ndjsonStream(
            $path,
            static function (callable $write): void {
                $write(['name' => 'Å']);
            },
            targetEncoding: 'US-ASCII',
            pretty: false
        );

        $raw = (string) file_get_contents($path);
        $this->assertNotSame('', $raw);

        // UTF-8 bytes för 'Å' får inte finnas kvar i ASCII-output
        $this->assertStringNotContainsString("\xC3\x85", $raw);
    }

    public function testReaderCsvNormalizesRowKeysToStrings(): void
    {
        $path = $this->tmpDir . 'no_header.csv';
        file_put_contents($path, "1,Alice\n2,Bob\n");

        $rows = Reader::csv($path, delimiter: ',', hasHeader: false, encoding: 'UTF-8', castNumeric: false);

        $this->assertCount(2, $rows);

        $keys = array_keys($rows[0]);

        $this->assertSame([0, 1], $keys, 'Sanity: förväntar oss två kolumner.');

        // OBS: PHP castar numeriska strängnycklar ("0", "1") till int-nycklar (0, 1),
        // så vi kan inte (och ska inte) kräva string keys här.
        $this->assertSame([0 => '1', 1 => 'Alice'], $rows[0]);

    }

    public function testCsvStreamDefaultHasHeaderIsFalse(): void
    {
        $path = $this->tmpDir . 'stream_default_no_header.csv';
        file_put_contents($path, "1,Alice\n2,Bob\n");

        $collected = [];
        Reader::csvStream(
            $path,
            static function (array $row) use (&$collected): void {
                $collected[] = $row;
            },
            ',',          // delimiter
            // hasHeader utelämnas med flit: default ska vara false
            encoding: 'UTF-8',
            castNumeric: false
        );

        // Med korrekt default(false) får vi 2 rader.
        // Med mutant default(true) försvinner första raden (blir header).
        $this->assertCount(2, $collected, 'Default hasHeader=false ska inte konsumera första raden som header.');
        $this->assertSame(['1', 'Alice'], $collected[0]);
        $this->assertSame(['2', 'Bob'], $collected[1]);
    }

    public function testTextStreamClosesFileHandleWhenCallbackThrows(): void
    {
        $path = $this->tmpDir . 'text_stream_boom.txt';
        file_put_contents($path, str_repeat('x', 100));

        ReaderFcloseSpy::reset();

        try {
            Reader::textStream(
                $path,
                static function (string $chunk): void {
                    throw new RuntimeException('boom');
                },
                16,
                'UTF-8'
            );
            $this->fail('textStream ska kasta när callbacken kastar.');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame(
            1,
            ReaderFcloseSpy::$calls,
            'fclose() måste anropas exakt en gång även när callbacken kastar (dödar UnwrapFinally-mutanten).'
        );
    }

    public function testNdjsonStreamCallsFcloseAndUnlockWhenCallbackThrows(): void
    {
        $path = $this->tmpDir . 'ndjson_close_unlock_boom.ndjson';

        WriterFcloseSpy::reset();
        WriterFlockSpy::reset();

        try {
            Writer::ndjsonStream($path, static function (callable $write): void {
                $write(['i' => 1]);
                throw new RuntimeException('boom');
            });
            $this->fail('ndjsonStream ska kasta när callbacken kastar.');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame(
            1,
            WriterFcloseSpy::$calls,
            'ndjsonStream måste fclose() även vid exception (dödar UnwrapFinally-mutanten).'
        );

        $unlockCalls = array_values(array_filter(
            WriterFlockSpy::$calls,
            static fn(array $c): bool => ($c['operation'] & LOCK_UN) === LOCK_UN
        ));

        $this->assertNotEmpty(
            $unlockCalls,
            'ndjsonStream måste anropa flock(..., LOCK_UN) i finally (dödar FunctionCallRemoval-mutanten).'
        );
    }

    public function testValidateRowsAllowsNonEmptyValueEvenWhenFieldIsNullableAndRequired(): void
    {
        $rows = [
            ['note' => 'x'],
        ];

        $schema = [
            'required' => ['note'],
            'nullable' => ['note'],
        ];

        // Korrekt: 'note' finns och är inte tom, så den ska passa även om den råkar vara nullable.
        // Mutant #18 gör att icke-tom + nullable triggar fel och kastar.
        $out = Writer::validateRows($rows, $schema, onError: 'throw');

        $this->assertSame($rows, $out);
    }

    public function testValidateRowsSkipDoesNotTypeErrorWhenLaterRequiredKeyIsInvalidBecauseFirstRequiredMissingBreaksEarly(): void
    {
        $rows = [
            ['present' => 'x'], // saknar 'missing' => ska skippas
        ];

        $schema = [
            // Viktigt: andra "required" är ogiltig (null). Den får aldrig utvärderas i skip-läge
            // om första required redan saknas.
            'required' => ['missing', null],
        ];

        // Korrekt (break): miss på 'missing' => skipRow=true => break => row skippas utan TypeError.
        // Mutant #19 (continue) går vidare till null och kan ge TypeError i array_key_exists().
        $out = Writer::validateRows($rows, $schema, onError: 'skip');

        $this->assertSame([], $out, 'Raden ska skippas utan att kasta när onError=skip.');
    }

    public function testValidateRowsSkipContinuesToNextRowAfterInvalidRowSoLaterValidRowsAreKept(): void
    {
        $rows = [
            ['active' => 'yes'],                 // invalid: saknar 'id' => ska skippas
            ['id' => '1', 'active' => 'yes'],    // valid => ska behållas
        ];

        $schema = [
            'required' => ['id', 'active'],
            'types' => [
                'id' => 'int',
                'active' => 'bool',
            ],
        ];

        // Korrekt: första skippas, andra kommer med.
        // Mutant #20 (continue->break) skulle avbryta loopen efter första skippade raden.
        $out = Writer::validateRows($rows, $schema, onError: 'skip');

        $this->assertSame([['id' => 1, 'active' => true]], $out);
    }

    public function testValidateRowsNullableDoesNotStopTypeProcessingForLaterFields(): void
    {
        $rows = [
            ['a' => '', 'b' => '2'],
        ];

        $schema = [
            'nullable' => ['a'],
            'types' => [
                'a' => 'int',   // '' + nullable => ska bli null och sen fortsätta
                'b' => 'int',   // ska fortfarande konverteras till 2
            ],
        ];

        $out = Writer::validateRows($rows, $schema, onError: 'throw');

        $this->assertSame([['a' => null, 'b' => 2]], $out);
    }


    public function testXmlWithUtf8EncodingDoesNotInvokeIconv(): void
    {
        $path = $this->tmpDir . 'xml_utf8_no_iconv.xml';
        file_put_contents($path, '<?xml version="1.0" encoding="UTF-8"?><root><name>Åsa</name></root>');

        WriterIconvSpy::reset();

        $arr = Reader::xml($path, assoc: true, encoding: 'UTF-8');

        $this->assertSame(['name' => 'Åsa'], $arr);
        $this->assertSame(
            [],
            WriterIconvSpy::$calls,
            'iconv() ska inte anropas när encoding är UTF-8 (Reader::xml).'
        );
    }

    public function testXmlParseDoesNotEmitLibxmlWarningsBecauseInternalErrorsAreEnabled(): void
    {
        $path = $this->tmpDir . 'broken.xml';
        file_put_contents($path, '<root><unclosed></root>');

        set_error_handler(static function (int $severity, string $message): never {
            throw new \RuntimeException('PHP-WARNING: ' . $message);
        });

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Kunde inte parsa XML:');

            Reader::xml($path, assoc: true, encoding: null);
        } finally {
            restore_error_handler();
        }
    }

    public function testXmlNoCdataOptionConvertsCdataToPlainTextInAssocArray(): void
    {
        $path = $this->tmpDir . 'cdata.xml';

        file_put_contents(
            $path,
            '<?xml version="1.0" encoding="UTF-8"?><root><n><![CDATA[Hello]]></n></root>'
        );

        $arr = Reader::xml($path, assoc: true);

        // Korrekt med LIBXML_NOCDATA: CDATA blir vanlig text.
        // Mutanten ($options = LIBXML_PARSEHUGE) tappar LIBXML_NOCDATA och brukar ge annan struktur.
        $this->assertSame(['n' => 'Hello'], $arr);
    }

    public function testCsvDefaultHasHeaderIsFalseSoHeaderRowIsReturnedAsData(): void
    {
        $path = $this->tmpDir . 'default_hasheader_false.csv';
        file_put_contents($path, "id,name\n1,Alice\n");

        // OBS: hasHeader utelämnas => default false
        $rows = Reader::csv($path, delimiter: ',', encoding: 'UTF-8', castNumeric: false);

        // Första "raden" är headern och ska därför komma med som data när hasHeader=false.
        $this->assertArrayHasKey(0, $rows);
        $this->assertArrayHasKey('0', $rows[0]);
        $this->assertArrayHasKey('1', $rows[0]);

        $this->assertSame('id', $rows[0]['0'] ?? null);
        $this->assertSame('name', $rows[0]['1'] ?? null);

        // Mutant default=true skulle skippa headern och ge assoc-nycklar => detta failar.
        $this->assertArrayHasKey('0', $rows[0]);
        $this->assertArrayHasKey('1', $rows[0]);
        $this->assertArrayNotHasKey('id', $rows[0]);
    }

    public function testTextStreamDefaultChunkSizeIsExactly8192(): void
    {
        $path = $this->tmpDir . 'textstream_default_8192.bin';

        // Exakt 8192 + 1 bytes => ska ge två chunks där första är exakt 8192.
        $content = str_repeat('a', 8192) . 'Z';
        file_put_contents($path, $content);

        $chunks = [];
        Reader::textStream(
            $path,
            static function (string $chunk) use (&$chunks): void {
                $chunks[] = $chunk;
            }
            // chunkSize utelämnas => default används
        );

        $this->assertCount(2, $chunks, 'Default chunkSize=8192 ska ge 2 chunks för 8193 bytes.');
        $this->assertSame(8192, strlen($chunks[0]), 'Första chunk måste vara exakt 8192 bytes när default chunkSize används.');
        $this->assertSame($content, implode('', $chunks));
    }
}
