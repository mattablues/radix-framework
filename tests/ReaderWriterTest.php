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
}
