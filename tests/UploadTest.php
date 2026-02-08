<?php

declare(strict_types=1);

namespace Radix\Tests;

final class UploadMkdirSpy
{
    public static ?int $lastPermissions = null;

    /** @var list<string> */
    public static array $failPaths = [];

    /** @var list<string> */
    public static array $racePaths = [];

    /** @var list<string> */
    public static array $liePaths = [];

    /** @var list<array{path:string, permissions:int, recursive:bool}> */
    public static array $calls = [];

    public static function reset(): void
    {
        self::$lastPermissions = null;
        self::$failPaths = [];
        self::$racePaths = [];
        self::$liePaths = [];
        self::$calls = [];
    }
}

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Radix\File\Upload;
use Radix\Support\Validator;
use Radix\Tests\Support\TestableValidator;
use RuntimeException;
use WriterMkdirSpy;

class UploadTest extends TestCase
{
    protected string $uploadDirectory;

    protected function setUp(): void
    {
        // Skapa tillfällig uppladdningsmapp UTANFÖR repot (för att undvika skräpfiler i tests/)
        $baseTmp = rtrim(sys_get_temp_dir(), '/\\');
        $this->uploadDirectory = $baseTmp . DIRECTORY_SEPARATOR . 'radix_upload_tests_' . uniqid('', true);

        if (!is_dir($this->uploadDirectory)) {
            mkdir($this->uploadDirectory, 0o755, true);
        }

        // Skapa en mockad bildfil för att simulera en uppladdning
        $image = imagecreatetruecolor(100, 100);

        $color = imagecolorallocate($image, 255, 0, 0);
        if ($color === false) {
            $this->fail('Kunde inte allokera färg för testbilden.');
        }

        imagefill($image, 0, 0, $color); // Röd bild

        $ok = imagejpeg($image, $this->uploadDirectory . DIRECTORY_SEPARATOR . 'test_image.jpg');
        imagedestroy($image);

        if ($ok === false) {
            $this->fail('Kunde inte skriva testbilden till temporär katalog.');
        }
    }

    /**
     * Mocka Radix\File\mkdir för att kunna spåra rättigheter och simulera fel.
     */
    protected function mockUploadMkdir(): void
    {
        UploadMkdirSpy::reset();

        if (!function_exists('Radix\File\mkdir')) {
            eval('namespace Radix\File; function mkdir($directory, $permissions = 0777, $recursive = false, $context = null) {
                \\Radix\\Tests\\UploadMkdirSpy::$lastPermissions = (int) $permissions;
                \\Radix\\Tests\\UploadMkdirSpy::$calls[] = [
                    "path" => (string) $directory,
                    "permissions" => (int) $permissions,
                    "recursive" => (bool) $recursive,
                ];

                if (in_array($directory, \\Radix\\Tests\\UploadMkdirSpy::$racePaths, true)) {
                    // mkdir rapporterar false men katalogen skapas ändå (race)
                    @\\mkdir($directory, $permissions, $recursive, $context);
                    return false;
                }

                if (in_array($directory, \\Radix\\Tests\\UploadMkdirSpy::$failPaths, true)) {
                    return false;
                }

                /** @var resource|null $context */
                return \\mkdir($directory, $permissions, $recursive, $context);
            }');
        }
    }

    protected function tearDown(): void
    {
        $this->deleteDir($this->uploadDirectory);
    }

    private function deleteDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->deleteDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    public function testConstructorThrowsWhenMkdirReturnsTrueButDirectoryIsStillMissing(): void
    {
        $this->mockUploadMkdir();

        $dir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'upload_lie_' . uniqid('', true);

        if (is_dir($dir)) {
            @rmdir($dir);
        }
        $this->assertDirectoryDoesNotExist($dir);

        UploadMkdirSpy::reset();
        UploadMkdirSpy::$liePaths = [$dir];

        $file = [
            'name' => 'dummy.txt',
            'type' => 'text/plain',
            'tmp_name' => '',
            'error' => UPLOAD_ERR_OK,
            'size' => 0,
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Misslyckades med att skapa uppladdningsmappen:');

        new Upload($file, $dir);
    }

    public function testConstructorCreatesUploadDirectoryWith0755PermissionsWhenMissing(): void
    {
        $this->mockUploadMkdir();

        $dir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'upload_perm_' . uniqid('', true);

        if (is_dir($dir)) {
            @rmdir($dir);
        }

        $file = [
            'name' => 'dummy.txt',
            'type' => 'text/plain',
            'tmp_name' => '',
            'error' => UPLOAD_ERR_OK,
            'size' => 0,
        ];

        $upload = new Upload($file, $dir);
        $this->assertInstanceOf(Upload::class, $upload);
        $this->assertDirectoryExists($dir, 'Katalogen ska ha skapats av konstruktorn.');

        // Fallback om Radix\File\mkdir() redan var definierad av andra tester och loggade till WriterMkdirSpy.
        if (UploadMkdirSpy::$lastPermissions === null && class_exists(WriterMkdirSpy::class)) {
            UploadMkdirSpy::$lastPermissions = WriterMkdirSpy::$lastPermissions;
        }

        // Viktigt: dödar Increment/DecrementInteger-mutanten oavsett OS
        $this->assertSame(0o755, UploadMkdirSpy::$lastPermissions, 'mkdir() ska ha anropats med 0755.');

        // På Unix kan vi också verifiera rättigheter och permissions-argumentet
        if (DIRECTORY_SEPARATOR === '/') {
            $perms = fileperms($dir) & 0o777;
            $this->assertSame(0o755, $perms, sprintf('Katalogrättigheter ska vara 0755, fick 0%o', $perms));
            $this->assertSame(0o755, UploadMkdirSpy::$lastPermissions, 'mkdir() ska ha anropats med 0755.');
        }
    }

    public function testConstructorDoesNotThrowWhenMkdirReturnsFalseButDirectoryExists(): void
    {
        $this->mockUploadMkdir();

        $dir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'upload_race_' . uniqid('', true);

        if (is_dir($dir)) {
            @rmdir($dir);
        }

        UploadMkdirSpy::$racePaths = [$dir];

        $file = [
            'name' => 'dummy.txt',
            'type' => 'text/plain',
            'tmp_name' => '',
            'error' => UPLOAD_ERR_OK,
            'size' => 0,
        ];

        // Originalet ska inte kasta här (dir finns efter "race")
        $upload = new Upload($file, $dir);

        $this->assertInstanceOf(Upload::class, $upload);
        $this->assertDirectoryExists($dir);
    }

    public function testConstructorDoesNotCallMkdirWhenUploadDirectoryAlreadyExists(): void
    {
        $this->mockUploadMkdir();

        $dir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'upload_exists_' . uniqid('', true);
        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        UploadMkdirSpy::reset();

        $file = [
            'name' => 'dummy.txt',
            'type' => 'text/plain',
            'tmp_name' => '',
            'error' => UPLOAD_ERR_OK,
            'size' => 0,
        ];

        $upload = new Upload($file, $dir);

        $this->assertInstanceOf(Upload::class, $upload);
        $this->assertSame([], UploadMkdirSpy::$calls, 'mkdir() ska inte anropas när katalogen redan finns.');
    }

    public function testGenerateFileNameUsesBaseNameAndLowercasedExtension(): void
    {
        $file = [
            'name' => 'My IMAGE.JPG',
            'type' => 'image/jpeg',
            'tmp_name' => $this->uploadDirectory . '/test_image.jpg',
            'error' => UPLOAD_ERR_OK,
            'size' => 102400,
        ];

        $upload = new class ($file, $this->uploadDirectory) extends Upload {
            /**
             * @param array<string,mixed> $file
             */
            public function __construct(array $file, string $uploadDirectory)
            {
                parent::__construct($file, $uploadDirectory);
            }

            public function exposedGenerateFileName(): string
            {
                return $this->generateFileName();
            }
        };

        $generated = $upload->exposedGenerateFileName();

        // Ska sluta med .jpg (lowercased extension)
        $this->assertStringEndsWith('.jpg', $generated, 'Filen ska använda lowercased extension.');

        // Hitta sista punkten och separera bas/extension
        $lastDot = strrpos($generated, '.');
        $this->assertNotFalse($lastDot, 'Filen ska innehålla en punkt innan extensionen.');

        $base = substr($generated, 0, $lastDot);
        $ext  = substr($generated, $lastDot + 1);

        $this->assertSame('jpg', $ext, 'Extension ska vara exakt "jpg".');
        $this->assertNotSame('', $base, 'Basdelen av filnamnet får inte vara tom.');
    }

    public function testGenerateFileNameFallsBackWhenNameMissingOrNotString(): void
    {
        // Fall 1: tom sträng
        $fileEmpty = [
            'name' => '',
            'type' => 'image/jpeg',
            'tmp_name' => $this->uploadDirectory . '/test_image.jpg',
            'error' => UPLOAD_ERR_OK,
            'size' => 102400,
        ];

        $uploadEmpty = new class ($fileEmpty, $this->uploadDirectory) extends Upload {
            /**
             * @param array<string,mixed> $file
             */
            public function __construct(array $file, string $uploadDirectory)
            {
                parent::__construct($file, $uploadDirectory);
            }

            public function exposedGenerateFileName(): string
            {
                return $this->generateFileName();
            }
        };

        $nameEmpty = $uploadEmpty->exposedGenerateFileName();

        // Fallback: inget namn => inget bild-extension-suffix
        $this->assertDoesNotMatchRegularExpression(
            '/\.(jpg|jpeg|png|gif|webp)$/i',
            $nameEmpty,
            'Fallback-filen ska inte sluta med bild-extension när name är tom.'
        );

        // Fall 2: icke-sträng name
        $fileNonString = [
            'name' => 123, // ogiltig typ
            'type' => 'image/jpeg',
            'tmp_name' => $this->uploadDirectory . '/test_image.jpg',
            'error' => UPLOAD_ERR_OK,
            'size' => 102400,
        ];

        $uploadNonString = new class ($fileNonString, $this->uploadDirectory) extends Upload {
            /**
             * @param array<string,mixed> $file
             */
            public function __construct(array $file, string $uploadDirectory)
            {
                parent::__construct($file, $uploadDirectory);
            }

            public function exposedGenerateFileName(): string
            {
                return $this->generateFileName();
            }
        };

        $nameNonString = $uploadNonString->exposedGenerateFileName();

        $this->assertDoesNotMatchRegularExpression(
            '/\.(jpg|jpeg|png|gif|webp)$/i',
            $nameNonString,
            'Fallback-filen ska inte sluta med bild-extension när name inte är sträng.'
        );
    }

    public function testNullableFileUpload(): void
    {
        $data = [
            'avatar' => [
                'name' => '',
                'type' => '',
                'tmp_name' => '',
                'error' => UPLOAD_ERR_NO_FILE, // Ingen fil skickades
                'size' => 0,
            ],
        ];

        $rules = [
            'avatar' => 'nullable|file_size:2|file_type:image/jpeg,image/png',
        ];

        $validator = new Validator($data, $rules);

        // Validering ska passera om ingen fil skickades och fältet är nullable
        $this->assertTrue($validator->validate(), 'Valideringen ska passera eftersom avatar är nullable.');
    }

    /**
     * Mocka move_uploaded_file för att kopiera filer istället för att använda native-behaviour under test.
     */
    protected function mockMoveUploadedFile(): void
    {
        if (!function_exists('Radix\File\move_uploaded_file')) {
            eval('namespace Radix\File; function move_uploaded_file($from, $to) {
                if (!file_exists($from)) {
                    return false;
                }
                return copy($from, $to);
            }');
        }
    }

    public function testValidateFileTypeAndSizeIndividually(): void
    {
        $mockFile = [
            'name' => 'example.jpg',
            'type' => 'image/jpeg',
            'tmp_name' => $this->uploadDirectory . '/test_image.jpg',
            'size' => 102400, // 100 KB
            'error' => UPLOAD_ERR_OK, // Mocka som en korrekt uppladdad fil
        ];

        $validator = new TestableValidator($mockFile, []);

        // Testa filtyp individuellt
        $isTypeValid = $validator->testFileType($mockFile, 'image/jpeg,image/png');
        $this->assertTrue($isTypeValid, 'MIME-typ-valideringen ska godkänna image/jpeg.');

        // Testa filstorlek individuellt
        $isSizeValid = $validator->testFileSize($mockFile, '2'); // 2 MB
        $this->assertTrue($isSizeValid, 'Filstorleksvalideringen ska godkänna 100 KB.');
    }

    public function testValidateFileTypeIsCaseInsensitiveForParameter(): void
    {
        $mockFile = [
            'name' => 'example.jpg',
            'type' => 'image/jpeg',
            'tmp_name' => $this->uploadDirectory . '/test_image.jpg',
            'size' => 1024,
            'error' => UPLOAD_ERR_OK,
        ];

        $validator = new TestableValidator($mockFile, []);

        // Parameter i VERSALER och blandat, med extra mellanslag
        $parameter = 'IMAGE/JPEG , IMAGE/PNG';

        $this->assertTrue(
            $validator->testFileType($mockFile, $parameter),
            'MIME-typen ska matchas oavsett versaler/gemener och mellanslag i parametern.'
        );
    }

    public function testValidateFileTypeTrimsAllowedTypes(): void
    {
        $mockFile = [
            'name' => 'example2.jpg',
            'type' => 'image/png',
            'tmp_name' => $this->uploadDirectory . '/test_image2.jpg',
            'size' => 2048,
            'error' => UPLOAD_ERR_OK,
        ];

        $validator = new TestableValidator($mockFile, []);

        // Extra mellanslag runt andra MIME-typen
        $parameter = 'image/jpeg,  image/png  ';

        $this->assertTrue(
            $validator->testFileType($mockFile, $parameter),
            'MIME-typen ska matcha även om värdena i listan har omgivande mellanslag.'
        );
    }

    public function testValidateFileTypeIsCaseInsensitiveForActualMimeType(): void
    {
        $mockFile = [
            'name' => 'example3.jpg',
            'type' => 'IMAGE/JPEG', // versaler i faktisk MIME-typ
            'tmp_name' => $this->uploadDirectory . '/test_image3.jpg',
            'size' => 4096,
            'error' => UPLOAD_ERR_OK,
        ];

        $validator = new TestableValidator($mockFile, []);

        $parameter = 'image/jpeg,image/png'; // parametern i gemener

        $this->assertTrue(
            $validator->testFileType($mockFile, $parameter),
            'MIME-typen ska valideras oavsett versaler/gemener i själva filens MIME-typ.'
        );
    }

    public function testValidateFileTypeHandlesNonArrayValueWithErrorField(): void
    {
        // Skapa ett icke-array-värde som ändå har en "error"-egenskap
        $nonArrayValue = new class {
            /** @var int */
            public int $error = UPLOAD_ERR_NO_FILE;
        };

        $validator = new TestableValidator([], []);

        // Original: is_array($value) === false → går vidare till nästa kontroll och returnerar false.
        // Mutant: försöker använda $value['error'] på ett objekt → TypeError/fatal.
        $this->assertFalse(
            $validator->testFileType($nonArrayValue, 'image/jpeg'),
            'Ett icke-array-värde ska inte orsaka fel utan bara returnera false.'
        );
    }

    public function testValidateFileTypeRejectsEmptyMimeTypeEvenIfEmptyAllowedType(): void
    {
        $mockFile = [
            'name' => 'no-type.bin',
            'type' => '', // tom MIME-typ
            'tmp_name' => $this->uploadDirectory . '/no_type.bin',
            'size' => 512,
            'error' => UPLOAD_ERR_OK,
        ];

        $validator = new TestableValidator($mockFile, []);

        // ",," ger tre tomma strängar som tillåtna typer efter trim.
        $parameter = ',,';

        $this->assertFalse(
            $validator->testFileType($mockFile, $parameter),
            'En fil med tom MIME-typ ska inte godkännas även om listan med tillåtna typer innehåller tomma strängar.'
        );
    }

    public function testValidateFileSizeReturnsFalseWhenSizeKeyIsMissing(): void
    {
        // value är en array men saknar 'size' → originalet ska returnera false
        $invalidFileArray = [
            'name' => 'no-size.jpg',
            'type' => 'image/jpeg',
            // ingen 'size'-nyckel alls
            'error' => UPLOAD_ERR_OK,
        ];

        $validator = new TestableValidator($invalidFileArray, []);

        $this->assertFalse(
            $validator->testFileSize($invalidFileArray, '1'),
            'En fil-array utan size-nyckel ska inte godkännas.'
        );
    }

    public function testValidateFileSizeThrowsOnNonNumericParameter(): void
    {
        $mockFile = [
            'name' => 'example.jpg',
            'type' => 'image/jpeg',
            'tmp_name' => $this->uploadDirectory . '/test_image.jpg',
            'size' => 1024, // 1 KB
            'error' => UPLOAD_ERR_OK,
        ];

        $validator = new TestableValidator($mockFile, []);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Parameter för 'file_size' måste vara en giltig siffra.");

        // Icke-numerisk parameter ska alltid kasta
        $validator->testFileSize($mockFile, 'inte-en-siffra');
    }

    public function testValidateFileSizeCastsParameterToInt(): void
    {
        $oneMb = 1024 * 1024;
        $sizeBetweenOneAndOnePointFiveMb = $oneMb + (int) (0.25 * $oneMb); // ca 1.25 MB

        $mockFile = [
            'name' => 'between-1-and-1-5mb.jpg',
            'type' => 'image/jpeg',
            'tmp_name' => $this->uploadDirectory . '/between_1_and_1_5mb.jpg',
            'size' => $sizeBetweenOneAndOnePointFiveMb,
            'error' => UPLOAD_ERR_OK,
        ];

        $validator = new TestableValidator($mockFile, []);

        // Original: (int) "1.5" = 1 → gräns 1 MB → ska inte godkännas.
        // Mutant utan (int)-kast: 1.5 MB-gräns → samma fil skulle godkännas.
        $this->assertFalse(
            $validator->testFileSize($mockFile, '1.5'),
            'Parameter "1.5" ska tolkas som 1 MB (int-cast), så en fil på ~1.25 MB ska nekas.'
        );
    }

    public function testValidateValidFile(): void
    {
        $data = [
            'avatar' => [
                'name' => 'test_image.jpg',
                'type' => 'image/jpeg',
                'tmp_name' => $this->uploadDirectory . '/test_image.jpg',
                'error' => UPLOAD_ERR_OK,
                'size' => 102400, // 100 KB
            ],
        ];

        $rules = [
            'avatar' => 'nullable|file_size:2|file_type:image/jpeg,image/png',
        ];

        $validator = new Validator($data, $rules);

        // Validering ska passera för en giltig fil
        $this->assertTrue($validator->validate(), 'Filvalideringen ska godkänna en giltig fil.');
    }


    public function testValidateInvalidFileType(): void
    {
        $this->mockMoveUploadedFile();

        $file = [
            'name' => 'test_invalid.pdf',
            'type' => 'application/pdf', // Felaktig MIME-typ
            'tmp_name' => $this->uploadDirectory . '/test_invalid.pdf',
            'error' => UPLOAD_ERR_OK,
            'size' => 102400, // 100 KB
        ];

        $upload = new Upload($file, $this->uploadDirectory);

        $isValid = $upload->validate([
            'avatar' => 'file_type:image/jpeg,image/png',
        ]);

        $this->assertFalse($isValid, 'Filvalideringen ska misslyckas för en ogiltig MIME-typ.');
        $this->assertNotEmpty($upload->getErrors(), 'Felmeddelanden ska genereras.');
    }


    public function testValidateExceededFileSize(): void
    {
        $this->mockMoveUploadedFile();

        $file = [
            'name' => 'test_large_image.jpg',
            'type' => 'image/jpeg',
            'tmp_name' => $this->uploadDirectory . '/test_image.jpg',
            'error' => UPLOAD_ERR_OK,
            'size' => 5120000, // 5 MB
        ];

        $upload = new Upload($file, $this->uploadDirectory);

        $isValid = $upload->validate([
            'avatar' => 'file_size:2', // Max 2 MB
        ]);

        $this->assertFalse($isValid, 'Filvalideringen ska misslyckas för en för stor fil.');
    }

    public function testSaveValidFile(): void
    {
        $this->mockMoveUploadedFile();

        // Mockad giltig upload-fil
        $file = [
            'name' => 'test_image.jpg',
            'type' => 'image/jpeg',
            'tmp_name' => $this->uploadDirectory . '/test_image.jpg',
            'error' => UPLOAD_ERR_OK,
            'size' => 102400, // 100 KB
        ];

        $upload = new Upload($file, $this->uploadDirectory);

        $savedPath = $upload->save();
        $this->assertFileExists($savedPath, 'Filen ska sparas korrekt på målplatsen.');
    }

    public function testSaveUsesTrimmedUploadDirectoryWithSingleDirectorySeparator(): void
    {
        $this->mockMoveUploadedFile();

        // Lägg till trailing slash i uploadDirectory för att testa rtrim-logiken
        $dirWithSlash = rtrim($this->uploadDirectory, '/\\') . '/';

        $file = [
            'name' => 'explicit_name.jpg',
            'type' => 'image/jpeg',
            'tmp_name' => $this->uploadDirectory . '/test_image.jpg',
            'error' => UPLOAD_ERR_OK,
            'size' => 102400,
        ];

        $upload = new Upload($file, $dirWithSlash);

        $savedPath = $upload->save('myfile.jpg');

        // Upload::save använder alltid '/' som separator internt
        $expected = rtrim($dirWithSlash, '/\\') . '/myfile.jpg';

        // UnwrapRtrim-mutanter och ConcatOperandRemoval ger fel sökväg (saknar/duplicerar separator)
        $this->assertSame($expected, $savedPath, 'save() ska använda rtrim(uploadDirectory) + "/" + filnamn.');
        $this->assertFileExists($savedPath);
    }

    public function testErrorHandling(): void
    {
        $this->mockMoveUploadedFile();

        // Mocka en fil utan uppladdning
        $file = [
            'name' => 'test_image.jpg',
            'type' => 'image/jpeg',
            'tmp_name' => $this->uploadDirectory . '/test_image.jpg',
            'error' => UPLOAD_ERR_NO_FILE,
            'size' => 0,
        ];

        $upload = new Upload($file, $this->uploadDirectory);

        $isValid = $upload->validate([
            'type' => 'file_type:image/jpeg,image/png',
        ]);

        $this->assertFalse($isValid, 'Valideringen ska misslyckas om ingen giltig fil laddas upp.');

        $this->assertNotEmpty($upload->getErrors(), 'Felmeddelanden ska genereras.');
    }

    public function testSaveThrowsOnInvalidTmpNameWhenNotString(): void
    {
        $this->mockMoveUploadedFile();

        $file = [
            'name' => 'test_image.jpg',
            'type' => 'image/jpeg',
            'tmp_name' => 123, // ogiltig typ
            'error' => UPLOAD_ERR_OK,
            'size' => 102400,
        ];

        $upload = new Upload($file, $this->uploadDirectory);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Ogiltigt tmp_name för uppladdad fil.');

        $upload->save();
    }

    public function testValidateFileSizeAcceptsFileExactlyAtLimit(): void
    {
        $oneMb = 1024 * 1024;

        $mockFile = [
            'name' => 'exact-limit.jpg',
            'type' => 'image/jpeg',
            'tmp_name' => $this->uploadDirectory . '/exact_limit.jpg',
            'size' => $oneMb, // exakt 1 MB
            'error' => UPLOAD_ERR_OK,
        ];

        $validator = new TestableValidator($mockFile, []);

        $this->assertTrue(
            $validator->testFileSize($mockFile, '1'),
            'En fil på exakt 1 MB ska godkännas när gränsen är 1 MB.'
        );
    }

    public function testValidateFileSizeRejectsFileJustAboveLimit(): void
    {
        $oneMb = 1024 * 1024;
        $sizeJustAboveLimit = $oneMb + 512; // 1 MB + lite extra

        $mockFile = [
            'name' => 'just-above-limit.jpg',
            'type' => 'image/jpeg',
            'tmp_name' => $this->uploadDirectory . '/just_above_limit.jpg',
            'size' => $sizeJustAboveLimit,
            'error' => UPLOAD_ERR_OK,
        ];

        $validator = new TestableValidator($mockFile, []);

        $this->assertFalse(
            $validator->testFileSize($mockFile, '1'),
            'En fil som är precis över 1 MB ska inte godkännas när gränsen är 1 MB.'
        );
    }
}
