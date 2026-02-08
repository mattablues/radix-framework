<?php

declare(strict_types=1);

namespace Radix\Tests;

use GdImage;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Radix\File\Image;
use ReflectionClass;
use RuntimeException;

// Spy för att kunna verifiera att Radix\File\Image::__destruct() verkligen anropar imagedestroy().
final class ImageDestroySpy
{
    /** @var list<int> spl_object_id för de GdImage-objekt som förstörts */
    public static array $calls = [];

    public static function reset(): void
    {
        self::$calls = [];
    }
}

// Namespaced spy för Radix\File\imagedestroy().
// Image.php anropar imagedestroy() utan backslash, så den här fångar anropet.
if (!function_exists('Radix\\File\\imagedestroy')) {
    eval('namespace Radix\\File; function imagedestroy($image): bool {
        if ($image instanceof \\GdImage) {
            \\Radix\\Tests\\ImageDestroySpy::$calls[] = \\spl_object_id($image);
        }
        return \\imagedestroy($image);
    }');
}

class ImageTest extends TestCase
{
    protected string $testImagePath;
    protected string $watermarkImagePath;
    protected string $tmpDir;

    protected function setUp(): void
    {
        // Skapa en unik temp-katalog för detta testfall
        $this->tmpDir = rtrim(sys_get_temp_dir(), '/\\')
            . DIRECTORY_SEPARATOR
            . 'radix_image_test_'
            . uniqid('', true);

        if (!is_dir($this->tmpDir)) {
            @mkdir($this->tmpDir, 0o755, true);
        }

        // Skapa en testbild
        $image = imagecreatetruecolor(800, 600);

        $color = imagecolorallocate($image, 255, 0, 0);
        if ($color === false) {
            $this->fail('Kunde inte allokera färg för testbilden.');
        }

        imagefill($image, 0, 0, $color); // Röd färg

        $this->testImagePath = $this->tmpDir . DIRECTORY_SEPARATOR . 'test_image.jpg';

        $ok = imagejpeg($image, $this->testImagePath);
        imagedestroy($image);

        if ($ok !== true) {
            $this->fail('Kunde inte skriva test_image.jpg till temp-katalogen: ' . $this->testImagePath);
        }

        // Skapa vattenmärkesbild
        $watermark = imagecreatetruecolor(100, 50);

        $wmColor = imagecolorallocate($watermark, 0, 0, 255);
        if ($wmColor === false) {
            $this->fail('Kunde inte allokera färg för vattenmärkesbilden.');
        }

        imagefill($watermark, 0, 0, $wmColor); // Blå färg

        $this->watermarkImagePath = $this->tmpDir . DIRECTORY_SEPARATOR . 'watermark_image.png';

        $ok2 = imagepng($watermark, $this->watermarkImagePath);
        imagedestroy($watermark);

        if ($ok2 !== true) {
            $this->fail('Kunde inte skriva watermark_image.png till temp-katalogen: ' . $this->watermarkImagePath);
        }

        ImageDestroySpy::reset();
    }

    /**
     * Bygg en sökväg under testets tempkatalog.
     */
    private function tmpPath(string $name): string
    {
        return $this->tmpDir . DIRECTORY_SEPARATOR . ltrim($name, '/\\');
    }

    protected function tearDown(): void
    {
        if (isset($this->testImagePath) && file_exists($this->testImagePath)) {
            @unlink($this->testImagePath);
        }

        if (isset($this->watermarkImagePath) && file_exists($this->watermarkImagePath)) {
            @unlink($this->watermarkImagePath);
        }

        // Rensa tempkatalogen sist (inkl. result_*, resized, thumbs, osv)
        if (isset($this->tmpDir) && is_dir($this->tmpDir)) {
            $items = @scandir($this->tmpDir) ?: [];
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                $p = $this->tmpDir . DIRECTORY_SEPARATOR . $item;
                if (is_file($p)) {
                    @unlink($p);
                }
            }
            @rmdir($this->tmpDir);
        }
    }

    public function testRotateImage(): void
    {
        $image = new Image($this->testImagePath);

        $image->rotateImage(90);

        $info = $image->getImageInfo();

        // Eftersom rotationen inte ändrar dimensionerna internt
        $this->assertEquals(800, $info['width']);
        $this->assertEquals(600, $info['height']);
    }

    public function testResizePreservesTransparentBackgroundForPng(): void
    {
        $src = $this->tmpPath('transparent_src.png');

        $img = imagecreatetruecolor(10, 10);
        if ($img === false) {
            $this->fail('Kunde inte skapa GD-bild för transparenstest.');
        }

        // Slå på alpha + fyll med transparent
        imagealphablending($img, false);
        imagesavealpha($img, true);
        $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
        if ($transparent === false) {
            imagedestroy($img);
            $this->fail('Kunde inte allokera transparent färg.');
        }
        imagefill($img, 0, 0, $transparent);

        // Rita en röd pixel så bilden inte är helt tom
        $red = imagecolorallocatealpha($img, 255, 0, 0, 0);
        if ($red === false) {
            imagedestroy($img);
            $this->fail('Kunde inte allokera röd färg.');
        }
        imagesetpixel($img, 5, 5, $red);

        imagepng($img, $src);
        imagedestroy($img);

        try {
            $image = new Image($src);
            $image->resizeImage(20, 20, 'exact');

            $resized = $image->getImageResized();
            $this->assertNotNull($resized);

            $idx = imagecolorat($resized, 0, 0);
            $this->assertIsInt($idx);

            $c = imagecolorsforindex($resized, $idx);
            /** @var array{red:int<0,255>,green:int<0,255>,blue:int<0,255>,alpha:int<0,127>} $c */

            // Alpha måste vara 127 (helt transparent)
            $this->assertSame(
                127,
                $c['alpha'],
                'Hörnet ska vara transparent.'
            );

            // KRITISKT: dödar IncrementInteger-mutanterna i imagecolorallocatealpha (0,0,0 -> 1,0,0 etc)
            $this->assertSame(0, $c['red'], 'Transparent bakgrund ska vara svart (R=0).');
            $this->assertSame(0, $c['green'], 'Transparent bakgrund ska vara svart (G=0).');
            $this->assertSame(0, $c['blue'], 'Transparent bakgrund ska vara svart (B=0).');
        } finally {
            if (file_exists($src)) {
                @unlink($src);
            }
        }
    }

    public function testDestructorDestroysOriginalImageResource(): void
    {
        $img = new class ($this->testImagePath) extends Image {
            public function __destruct()
            {
                // Avsiktligt tom: vi vill styra när parent::__destruct() körs i testet.
            }

            public function destroyNow(): void
            {
                parent::__destruct();
            }

            public function exposeOriginal(): mixed
            {
                return $this->image;
            }
        };

        $original = $img->exposeOriginal();
        $this->assertInstanceOf(GdImage::class, $original);

        $originalId = spl_object_id($original);

        $img->destroyNow();

        // Om imagedestroy($this->image) muteras bort så loggas inte originalets id → testet failar.
        $this->assertContains(
            $originalId,
            ImageDestroySpy::$calls,
            'Destructorn måste anropa imagedestroy() för originalbilden.'
        );
    }

    public function testAddWatermark(): void
    {
        $image = new Image($this->testImagePath);

        $resultPath = $this->tmpPath('result_with_watermark.jpg');
        $image->addWatermark($this->watermarkImagePath, 50, 50);
        $image->saveImage($resultPath);

        $this->assertFileExists($resultPath);
    }

    public function testGetImageInfo(): void
    {
        $image = new Image($this->testImagePath);

        $info = $image->getImageInfo();

        // Kontrollera att dimensionerna matchar originalbildens storlek
        $this->assertEquals(800, $info['width'], 'Originalbredden ska vara 800 pixlar.');
        $this->assertEquals(600, $info['height'], 'Originalhöjden ska vara 600 pixlar.');

        // Kontrollera att inga dimensioner för resized bild finns när ingen ändring har gjorts
        $this->assertNull($info['resizedWidth'], 'Den ändrade bredden ska vara null om ingen ändring gjorts.');
        $this->assertNull($info['resizedHeight'], 'Den ändrade höjden ska vara null om ingen ändring gjorts.');

        // Ändra storlek och verifiera
        $image->resizeImage(400, 300);
        $infoAfterResize = $image->getImageInfo();

        $this->assertEquals(400, $infoAfterResize['resizedWidth'], 'Den ändrade bredden efter resize ska vara 400 pixlar.');
        $this->assertEquals(300, $infoAfterResize['resizedHeight'], 'Den ändrade höjden efter resize ska vara 300 pixlar.');
    }

    public function testConstructorValidImage(): void
    {
        $image = new Image($this->testImagePath);
        $this->assertInstanceOf(Image::class, $image);
    }

    public function testSaveThumbDelegatesToSaveImageWithDerivedPathAndDefaultQuality(): void
    {
        // Subklass som loggar saveImage()-anrop i stället för att skriva till disk
        $image = new class ($this->testImagePath) extends Image {
            public ?string $savedPath = null;
            public ?int $savedQuality = null;

            public function saveImage(string $path, ?int $quality = null): void
            {
                $this->savedPath = $path;
                $this->savedQuality = $quality;
            }
        };

        $basePath = $this->tmpPath('thumb_delegate.jpg');

        // Anropa utan quality-argument → ska använda default 100
        $image->saveThumb($basePath);

        // MethodCallRemoval-mutanter gör att dessa förblir null
        $this->assertNotNull($image->savedPath, 'saveThumb() måste anropa saveImage()');
        $this->assertNotNull($image->savedQuality, 'saveThumb() måste vidarebefordra quality till saveImage()');

        $directory = pathinfo($basePath, PATHINFO_DIRNAME);
        $filename = pathinfo($basePath, PATHINFO_FILENAME);
        $ext      = pathinfo($basePath, PATHINFO_EXTENSION);

        $expectedThumbPath = $directory . '/' . $filename . '.thumb.' . $ext;

        $this->assertSame(
            $expectedThumbPath,
            $image->savedPath,
            'saveThumb() ska härleda sitt filnamn enligt "$directory/$filename.thumb.$ext".'
        );

        // IncrementInteger på default-argumentet (100 → 101) gör att denna assertion faller.
        $this->assertSame(
            100,
            $image->savedQuality,
            'Default-quality för saveThumb() ska vara 100.'
        );
    }

    public function testOpenImageRemainsPublicAndReturnsGdImage(): void
    {
        $image = new Image($this->testImagePath);

        $opened = $image->openImage($this->testImagePath);

        $this->assertInstanceOf(GdImage::class, $opened);
    }

    public function testConstructorInvalidPath(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Bilden "non_existing_image.jpg" kunde inte hittas.');

        new Image('non_existing_image.jpg');
    }

    public function testConstructorUnsupportedFormat(): void
    {
        $unsupportedPath = $this->tmpPath('unsupported_image.bmp');

        // Skapa en riktig BMP-fil
        $bmpHeader = hex2bin('424D460000000000000036000000280000000100000001000000010018000000000010000000C40E0000C40E00000000000000000000');
        file_put_contents($unsupportedPath, $bmpHeader);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Bildformat "image/bmp" stöds inte.');

        try {
            new Image($unsupportedPath);
        } finally {
            @unlink($unsupportedPath);
        }
    }

    public function testResizeImage(): void
    {
        $image = new Image($this->testImagePath);

        $image->resizeImage(400, 300);

        $resized = $image->getImageResized();
        $this->assertNotNull($resized, 'Resized image should not be null.');
        $this->assertEquals(400, imagesx($resized));
        $this->assertEquals(300, imagesy($resized));
    }

    public function testResizeImageCrop(): void
    {
        $image = new Image($this->testImagePath);

        $image->resizeImage(400, 300, 'crop');

        $resized = $image->getImageResized();
        $this->assertNotNull($resized, 'Resized image should not be null.');
        $this->assertEquals(400, imagesx($resized));
        $this->assertEquals(300, imagesy($resized));
    }

    public function testSaveImage(): void
    {
        $image = new Image($this->testImagePath);
        $image->resizeImage(400, 300);
        $outputPath = $this->tmpPath('resized_image.jpg');

        $image->saveImage($outputPath);

        $this->assertFileExists($outputPath);
        $this->assertGreaterThan(0, filesize($outputPath));
    }

    public function testSaveImageSupportsUppercaseJpgExtension(): void
    {
        $image = new Image($this->testImagePath);
        $image->resizeImage(400, 300);

        $outputPath = $this->tmpPath('resized_image.JPG');
        $image->saveImage($outputPath);

        $this->assertFileExists($outputPath);
    }

    public function testSaveImageSupportsJpegExtension(): void
    {
        $image = new Image($this->testImagePath);
        $image->resizeImage(400, 300);

        $outputPath = $this->tmpPath('resized_image.jpeg');
        $image->saveImage($outputPath);

        $this->assertFileExists($outputPath);
    }

    public function testSaveThumb(): void
    {
        $image = new Image($this->testImagePath);
        $thumbPath = $this->tmpPath('test_thumb.jpg');

        $image->resizeImage(400, 300); // Se till att resizeImage körs
        $image->saveThumb($thumbPath);

        $expectedThumbPath = $this->tmpPath('test_thumb.thumb.jpg');
        $this->assertFileExists($expectedThumbPath);
        $this->assertGreaterThan(0, filesize($expectedThumbPath));
    }

    public function testSaveImageUnsupportedFormat(): void
    {
        $image = new Image($this->testImagePath);
        $image->resizeImage(400, 400);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Okänt filformat "txt".');

        $image->saveImage($this->tmpPath('test_image.txt'));
    }

    public function testCropDoesNotProduceBlackBorders(): void
    {
        $image = new Image($this->testImagePath);

        // Kör crop-vägen
        $image->resizeImage(400, 300, 'crop');

        $resized = $image->getImageResized();
        $this->assertNotNull($resized, 'Resized image should not be null.');

        // Kontrollera några hörnpixlar – de ska i alla fall inte vara "nästan svarta"
        // p.g.a. felaktig destinationsoffset (-1 eller 1).
        $checkPoints = [
            [0, 0],
            [399, 0],
            [0, 299],
            [399, 299],
        ];

        foreach ($checkPoints as [$x, $y]) {
            $rgbIndex = imagecolorat($resized, $x, $y);
            $this->assertIsInt($rgbIndex);

            $colors = imagecolorsforindex($resized, $rgbIndex);
            /** @var array{red:int<0,255>,green:int<0,255>,blue:int<0,255>,alpha:int<0,127>} $colors */

            $red   = $colors['red'];
            $green = $colors['green'];
            $blue  = $colors['blue'];

            // Ursprungliga bilden är starkt röd; JPEG-komprimering kan ge 254 osv,
            // så vi använder trösklar i stället för exakta värden.
            $this->assertGreaterThanOrEqual(200, $red);
            $this->assertLessThanOrEqual(50, $green);
            $this->assertLessThanOrEqual(50, $blue);
        }
    }

    public function testCropIsVerticallyCenteredOnContent(): void
    {
        // Deterministiskt: testa crop() direkt utan resize/interpolation.
        $image = new Image($this->testImagePath);

        $ref = new ReflectionClass($image);

        $imageResizedProp = $ref->getProperty('imageResized');
        $imageResizedProp->setAccessible(true);

        // 5x5 med horisontella band: blå (0-1), grön (2), röd (3-4)
        $optimalWidth = 5;
        $optimalHeight = 5;

        $canvas = imagecreatetruecolor($optimalWidth, $optimalHeight);
        if ($canvas === false) {
            $this->fail('Kunde inte skapa canvas för vertikal centreringstest.');
        }

        $blue = imagecolorallocate($canvas, 0, 0, 255);
        $green = imagecolorallocate($canvas, 0, 255, 0);
        $red = imagecolorallocate($canvas, 255, 0, 0);

        if ($blue === false || $green === false || $red === false) {
            $this->fail('Kunde inte allokera färger för vertikal centreringstest.');
        }

        imagefilledrectangle($canvas, 0, 0, 4, 1, $blue);
        imagefilledrectangle($canvas, 0, 2, 4, 2, $green);
        imagefilledrectangle($canvas, 0, 3, 4, 4, $red);

        $imageResizedProp->setValue($image, $canvas);

        $crop = $ref->getMethod('crop');
        $crop->setAccessible(true);

        // Croppa bara i höjdled: optimalHeight=5 -> newHeight=3 => startY = round((5-3)/2)=1
        $newWidth = 5;
        $newHeight = 3;
        $crop->invoke($image, $optimalWidth, $optimalHeight, $newWidth, $newHeight);

        $out = $image->getImageResized();
        $this->assertNotNull($out);

        // Med korrekt centrering hamnar mittenraden i output på källans gröna rad.
        $idx = imagecolorat($out, 2, 1);
        $this->assertIsInt($idx);

        $colors = imagecolorsforindex($out, $idx);
        /** @var array{red:int<0,255>,green:int<0,255>,blue:int<0,255>,alpha:int<0,127>} $colors */

        $this->assertGreaterThan(
            $colors['red'],
            $colors['green'],
            'Mittenpixeln ska vara grönare än röd (centrering).'
        );
        $this->assertGreaterThan(
            $colors['blue'],
            $colors['green'],
            'Mittenpixeln ska vara grönare än blå (centrering).'
        );
    }

    public function testResizeImageExactUsesRequestedDimensions(): void
    {
        $image = new Image($this->testImagePath);

        $image->resizeImage(123, 45, 'exact');

        $resized = $image->getImageResized();
        $this->assertNotNull($resized);
        $this->assertSame(123, imagesx($resized));
        $this->assertSame(45, imagesy($resized));
    }

    public function testResizeImagePortraitKeepsNewHeightAndScalesWidth(): void
    {
        $image = new Image($this->testImagePath);

        // Originalbild 800x600, portrait med newHeight=300
        $image->resizeImage(0, 300, 'portrait');

        $resized = $image->getImageResized();
        $this->assertNotNull($resized);

        // Höjden ska vara exakt 300 enligt portrait-regeln
        $this->assertSame(300, imagesy($resized));

        // Bredden ska vara proportionellt skalad: 300 * (height/width) = 300 * (600/800) = 225
        $this->assertSame(225, imagesx($resized));
    }

    public function testCropIsHorizontallyCenteredOnContent(): void
    {
        // Deterministiskt: testa crop() direkt utan resize/interpolation.
        $image = new Image($this->testImagePath);

        $ref = new ReflectionClass($image);

        $imageResizedProp = $ref->getProperty('imageResized');
        $imageResizedProp->setAccessible(true);

        // 5x5 med vertikala band: blå (0-1), grön (2), röd (3-4)
        $optimalWidth = 5;
        $optimalHeight = 5;

        $canvas = imagecreatetruecolor($optimalWidth, $optimalHeight);
        if ($canvas === false) {
            $this->fail('Kunde inte skapa canvas för horisontell centreringstest.');
        }

        $blue = imagecolorallocate($canvas, 0, 0, 255);
        $green = imagecolorallocate($canvas, 0, 255, 0);
        $red = imagecolorallocate($canvas, 255, 0, 0);

        if ($blue === false || $green === false || $red === false) {
            $this->fail('Kunde inte allokera färger för horisontell centreringstest.');
        }

        imagefilledrectangle($canvas, 0, 0, 1, 4, $blue);
        imagefilledrectangle($canvas, 2, 0, 2, 4, $green);
        imagefilledrectangle($canvas, 3, 0, 4, 4, $red);

        $imageResizedProp->setValue($image, $canvas);

        $crop = $ref->getMethod('crop');
        $crop->setAccessible(true);

        // Croppa bara i breddled: optimalWidth=5 -> newWidth=3 => startX = round((5-3)/2)=1
        $newWidth = 3;
        $newHeight = 5;
        $crop->invoke($image, $optimalWidth, $optimalHeight, $newWidth, $newHeight);

        $out = $image->getImageResized();
        $this->assertNotNull($out);

        // Med korrekt centrering hamnar mittkolumnen i output på källans gröna kolumn.
        $idx = imagecolorat($out, 1, 2);
        $this->assertIsInt($idx);

        $colors = imagecolorsforindex($out, $idx);
        /** @var array{red:int<0,255>,green:int<0,255>,blue:int<0,255>,alpha:int<0,127>} $colors */

        $this->assertGreaterThan(
            $colors['red'],
            $colors['green'],
            'Mittenpixeln ska vara grönare än röd (centrering).'
        );
        $this->assertGreaterThan(
            $colors['blue'],
            $colors['green'],
            'Mittenpixeln ska vara grönare än blå (centrering).'
        );
    }

    public function testGetOptimalCropDimensionsAreExact(): void
    {
        $image = new Image($this->testImagePath);

        $ref = new ReflectionClass(Image::class);
        $method = $ref->getMethod('getOptimalCrop');
        $method->setAccessible(true);

        // Fall 1: höjd begränsar (heightRatio är min)
        // width=800, height=600, newWidth=200, newHeight=600
        // widthRatio = 800 / 200 = 4
        // heightRatio = 600 / 600 = 1  => optimalRatio = 1
        /** @var array{optimalWidth:int,optimalHeight:int} $dims1 */
        $dims1 = $method->invoke($image, 200, 600);

        $this->assertSame(800, $dims1['optimalWidth'], 'optimalWidth ska vara 800 när höjd begränsar.');
        $this->assertSame(600, $dims1['optimalHeight'], 'optimalHeight ska vara 600 när höjd begränsar.');

        // Denna input dödar Division‑mutanten som ändrar heightRatio till * newHeight,
        // eftersom optimalRatio då blir 4 i stället för 1 → helt andra dimensioner.

        // Fall 2: båda ratio = 2 (båda dimensioner begränsar lika mycket)
        // width=800, height=600, newWidth=400, newHeight=300
        // widthRatio = 800 / 400 = 2
        // heightRatio = 600 / 300 = 2  => optimalRatio = 2
        /** @var array{optimalWidth:int,optimalHeight:int} $dims2 */
        $dims2 = $method->invoke($image, 400, 300);

        $this->assertSame(400, $dims2['optimalWidth'], 'optimalWidth ska vara 400 för 400x300‑crop.');
        $this->assertSame(300, $dims2['optimalHeight'], 'optimalHeight ska vara 300 för 400x300‑crop.');

        // Fall 3: höjd är mycket begränsande
        // width=800, height=600, newWidth=800, newHeight=100
        /** @var array{optimalWidth:int,optimalHeight:int} $dims3 */
        $dims3 = $method->invoke($image, 800, 100);

        $this->assertSame(800, $dims3['optimalWidth'], 'optimalWidth ska vara 800 när newWidth matchar full bredd.');
        $this->assertSame(600, $dims3['optimalHeight'], 'optimalHeight ska vara 600 när höjd skalar upp.');
    }

    public function testGetSizeByAutoLandscapeUsesRoundedScaledHeightLowFraction(): void
    {
        $image = new Image($this->testImagePath);

        $ref = new ReflectionClass(Image::class);
        $widthProp = $ref->getProperty('width');
        $heightProp = $ref->getProperty('height');
        $widthProp->setAccessible(true);
        $heightProp->setAccessible(true);

        // Ställ in dimensioner så att width > height och skalfaktorn ger 2.25
        // width = 4, height = 3, newWidth = 3:
        // scaledHeight = newWidth * (height / width) = 3 * (3/4) = 2.25
        $widthProp->setValue($image, 4);
        $heightProp->setValue($image, 3);

        $method = $ref->getMethod('getSizeByAuto');
        $method->setAccessible(true);

        /** @var array{optimalWidth:int,optimalHeight:int} $dims */
        $dims = $method->invoke($image, 3, 10);

        // round(2.25) = 2.
        // ceil-mutanten ger 3; ReturnRemoval-mutanten ger optimalHeight = newHeight (10).
        $this->assertSame(3, $dims['optimalWidth']);
        $this->assertSame(2, $dims['optimalHeight']);
    }

    public function testGetSizeByAutoLandscapeUsesRoundedScaledHeightHighFraction(): void
    {
        $image = new Image($this->testImagePath);

        $ref = new ReflectionClass(Image::class);
        $widthProp = $ref->getProperty('width');
        $heightProp = $ref->getProperty('height');
        $widthProp->setAccessible(true);
        $heightProp->setAccessible(true);

        // Samma width/height men annan newWidth:
        // width = 4, height = 3, newWidth = 5:
        // scaledHeight = 5 * (3/4) = 3.75
        $widthProp->setValue($image, 4);
        $heightProp->setValue($image, 3);

        $method = $ref->getMethod('getSizeByAuto');
        $method->setAccessible(true);

        /** @var array{optimalWidth:int,optimalHeight:int} $dims */
        $dims = $method->invoke($image, 5, 10);

        // round(3.75) = 4.
        $this->assertSame(5, $dims['optimalWidth']);
        $this->assertSame(4, $dims['optimalHeight']);
    }

    public function testAddWatermarkUsesResizedImageWhenBothOriginalAndResizedExist(): void
    {
        // Special-subklass som sätter olika storlekar på original och resized
        $image = new class extends Image {
            public function __construct()
            {
                // Skapa ett litet original: 2x2
                $orig = imagecreatetruecolor(2, 2);
                if ($orig === false) {
                    throw new RuntimeException('Kunde inte skapa originalbild i test-subklass.');
                }

                // Skapa en större "resized": 10x10
                $resized = imagecreatetruecolor(10, 10);
                if ($resized === false) {
                    throw new RuntimeException('Kunde inte skapa resized-bild i test-subklass.');
                }

                $blue  = imagecolorallocate($resized, 0, 0, 255);
                if ($blue === false) {
                    throw new RuntimeException('Kunde inte allokera färg i resized-bild.');
                }
                imagefill($resized, 0, 0, $blue);

                // Initiera parent-egenskaper manuellt
                $this->image        = $orig;
                $this->width        = imagesx($orig);
                $this->height       = imagesy($orig);
                $this->imageResized = $resized;
            }

            // Ignorera sökvägen; skapa ett litet watermark i stället
            public function openImage(string $filePath): GdImage
            {
                $wm = imagecreatetruecolor(2, 2);
                if ($wm === false) {
                    throw new RuntimeException('Kunde inte skapa watermark i test-subklass.');
                }
                $green = imagecolorallocate($wm, 0, 255, 0);
                if ($green === false) {
                    throw new RuntimeException('Kunde inte allokera färg i watermark.');
                }
                imagefill($wm, 0, 0, $green);
                return $wm;
            }
        };

        // Anropa med watermark placerat i nedre högra hörnet av 10x10-bilden.
        // Original (2x2) är FÖR LITET för (x=9, y=9, w=2, h=2) och imagecopy() ska misslyckas.
        // Resized (10x10) är PRECIS lagom stor för att imagecopy() ska lyckas.
        try {
            $image->addWatermark('ignored-path.png', 9, 9);
        } catch (RuntimeException $e) {
            $this->fail(
                'addWatermark() ska använda imageResized när den finns. '
                . 'Mutanten som väljer $this->image först orsakar RuntimeException: ' . $e->getMessage()
            );
        }

        // Om vi vill kan vi även säkerställa att resized-bilden fortfarande finns.
        $resized = $image->getImageResized();
        $this->assertNotNull($resized);
        $this->assertSame(10, imagesx($resized));
        $this->assertSame(10, imagesy($resized));
    }

    public function testResizeImageTreatsUppercaseOptionSameAsLowercase(): void
    {
        $image = new Image($this->testImagePath);

        // Kör först med "crop"
        $image->resizeImage(400, 300, 'crop');
        $lower = $image->getImageResized();
        $this->assertNotNull($lower);
        $this->assertSame(400, imagesx($lower));
        $this->assertSame(300, imagesy($lower));

        // Skapa ny instans och kör med "CROP"
        $image2 = new Image($this->testImagePath);
        $image2->resizeImage(400, 300, 'CROP');
        $upper = $image2->getImageResized();
        $this->assertNotNull($upper);
        $this->assertSame(400, imagesx($upper));
        $this->assertSame(300, imagesy($upper));
    }

    public function testSaveImageUsesDefaultQualityWhenNull(): void
    {
        $image = new Image($this->testImagePath);
        $image->resizeImage(400, 300);

        // Sätt defaultQuality till ett unikt värde
        $ref = new ReflectionClass(Image::class);
        $prop = $ref->getProperty('defaultQuality');
        $prop->setAccessible(true);
        $prop->setValue($image, 37);

        $out = $this->tmpPath('quality_default.jpg');
        $image->saveImage($out, null);
        $this->assertFileExists($out);
        // Vi testar bara att anropet fungerar; mutanten och originalet beter sig lika när quality=null
    }

    public function testSaveImageHonorsExplicitQualityArgument(): void
    {
        $image = new Image($this->testImagePath);
        $image->resizeImage(400, 300);

        $outLow = $this->tmpPath('quality_10.jpg');
        $outHigh = $this->tmpPath('quality_90.jpg');

        $image->saveImage($outLow, 10);
        $image->saveImage($outHigh, 90);

        $this->assertFileExists($outLow);
        $this->assertFileExists($outHigh);

        $sizeLow = filesize($outLow);
        $sizeHigh = filesize($outHigh);

        $this->assertIsInt($sizeLow);
        $this->assertIsInt($sizeHigh);
        $this->assertGreaterThan(0, $sizeLow);
        $this->assertGreaterThan(0, $sizeHigh);

        // Normalt ska quality=10 ge mindre fil än quality=90
        $this->assertLessThan(
            $sizeHigh,
            $sizeLow,
            'Låg JPEG-quality ska ge mindre fil än hög quality (dödar AssignCoalesce-mutanten)'
        );
    }

    public function testResizeImageThrowsWhenOptimalWidthIsZero(): void
    {
        // Subklass som fejk:ar getDimensions så att optimalWidth=0, optimalHeight>0
        $image = new class ($this->testImagePath) extends Image {
            protected function getDimensions(int $newWidth, int $newHeight, string $option): array
            {
                return ['optimalWidth' => 0, 'optimalHeight' => 100];
            }
        };

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Ogiltiga bilddimensioner: 0 x 100');

        $image->resizeImage(400, 300, 'auto');
    }

    public function testGetSizeByAutoSquareUsesSquareBranch(): void
    {
        $image = new Image($this->testImagePath);

        $ref = new ReflectionClass(Image::class);
        $widthProp = $ref->getProperty('width');
        $heightProp = $ref->getProperty('height');
        $widthProp->setAccessible(true);
        $heightProp->setAccessible(true);

        // Gör bilden "kvadratisk" i interna dimensioner
        $widthProp->setValue($image, 500);
        $heightProp->setValue($image, 500);

        $method = $ref->getMethod('getSizeByAuto');
        $method->setAccessible(true);

        /** @var array{optimalWidth:int,optimalHeight:int} $dims */
        $dims = $method->invoke($image, 400, 300);

        // För en kvadratisk bild ska "square"-grenen: optimalWidth=newWidth, optimalHeight=newHeight
        $this->assertSame(400, $dims['optimalWidth'], 'Square-bilden ska använda newWidth för optimalWidth.');
        $this->assertSame(300, $dims['optimalHeight'], 'Square-bilden ska använda newHeight för optimalHeight.');
    }

    public function testResizeImageThrowsOnUnknownOption(): void
    {
        $image = new Image($this->testImagePath);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Okänt alternativ "foo".');

        $image->resizeImage(400, 300, 'foo');
    }

    public function testResizeImageThrowsWhenOptimalHeightIsZero(): void
    {
        $image = new class ($this->testImagePath) extends Image {
            protected function getDimensions(int $newWidth, int $newHeight, string $option): array
            {
                return ['optimalWidth' => 100, 'optimalHeight' => 0];
            }
        };

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Ogiltiga bilddimensioner: 100 x 0');

        $image->resizeImage(400, 300, 'auto');
    }

    public function testConstructorSupportedGifFormat(): void
    {
        // Skapa en enkel GIF-bild för att testa GIF-stödet
        $gifPath = $this->tmpPath('test_image.gif');

        $img = imagecreatetruecolor(10, 10);
        $color = imagecolorallocate($img, 0, 255, 0);
        if ($color === false) {
            $this->fail('Kunde inte allokera färg för GIF-testbilden.');
        }
        imagefill($img, 0, 0, $color);
        imagegif($img, $gifPath);
        imagedestroy($img);

        try {
            $image = new Image($gifPath);
            $this->assertInstanceOf(Image::class, $image);
        } finally {
            if (file_exists($gifPath)) {
                unlink($gifPath);
            }
        }
    }

    public function testConstructorSupportedWebpFormatWhenAvailable(): void
    {
        if (!function_exists('imagewebp') || !function_exists('imagecreatefromwebp')) {
            $this->markTestSkipped('WEBP stöds inte av denna PHP-installation.');
        }

        $webpPath = $this->tmpPath('test_image.webp');

        $img = imagecreatetruecolor(10, 10);
        $color = imagecolorallocate($img, 0, 0, 255);
        if ($color === false) {
            $this->fail('Kunde inte allokera färg för WEBP-testbilden.');
        }
        imagefill($img, 0, 0, $color);
        imagewebp($img, $webpPath);
        imagedestroy($img);

        try {
            $image = new Image($webpPath);
            $this->assertInstanceOf(Image::class, $image);
        } finally {
            if (file_exists($webpPath)) {
                unlink($webpPath);
            }
        }
    }

    public function testResizeImageLandscapeScalesHeightWithFixedWidth(): void
    {
        $image = new Image($this->testImagePath); // 800x600

        // landscape med newWidth=400
        $image->resizeImage(400, 999, 'landscape'); // newHeight ignoreras i landscape

        $resized = $image->getImageResized();
        $this->assertNotNull($resized);

        // Bredden ska vara exakt 400
        $this->assertSame(400, imagesx($resized));

        // Höjden ska skalas proportionellt: 400 * (600/800) = 300
        $this->assertSame(300, imagesy($resized));
    }

    public function testRotateImageUsesResizedImageWhenAvailable(): void
    {
        $image = new Image($this->testImagePath);

        // Först ändra storlek – skapar imageResized (400x300) bredvid originalet (800x600)
        $image->resizeImage(400, 300);

        // Rotera med vinkel 0 – dimensionerna ska fortfarande följa den RESIZADE bilden
        $image->rotateImage(0);

        $rotated = $image->getImageResized();
        $this->assertNotNull($rotated);

        // Coalesce-mutanten som använder $this->image före $this->imageResized ger 800x600 här
        $this->assertSame(400, imagesx($rotated));
        $this->assertSame(300, imagesy($rotated));
    }

    public function testRotateImageUsesBlackBackgroundForNewAreas(): void
    {
        $image = new Image($this->testImagePath);

        // Rotera med 45° så nya hörn skapas och fylls med bakgrundsfärg
        $image->rotateImage(45);

        $rotated = $image->getImageResized();
        $this->assertNotNull($rotated);

        // Sampla ett hörn – bakgrundsfärgen ska vara exakt svart (#000000) när bgColor=0
        $this->assertPixelColor($rotated, 0, 0, 0, 0, 0);
    }

    public function testAddWatermarkUsesExactSourceAndDestinationOffsets(): void
    {
        $image = new Image($this->testImagePath); // röd bakgrund

        // Först resize:a så att imageResized används som basbild
        $image->resizeImage(400, 300);

        // Skapa ett litet mönstrat watermark så att både destination- och source-offsets syns
        $wm = imagecreatetruecolor(2, 2);
        $blue  = imagecolorallocate($wm, 0, 0, 255);
        $red   = imagecolorallocate($wm, 255, 0, 0);
        $green = imagecolorallocate($wm, 0, 255, 0);
        $white = imagecolorallocate($wm, 255, 255, 255);

        if ($blue === false || $red === false || $green === false || $white === false) {
            $this->fail('Kunde inte allokera färger för watermark-mönstret.');
        }

        imagesetpixel($wm, 0, 0, $blue);
        imagesetpixel($wm, 1, 0, $red);
        imagesetpixel($wm, 0, 1, $green);
        imagesetpixel($wm, 1, 1, $white);

        $wmPath = $this->tmpPath('watermark_pattern.png');
        imagepng($wm, $wmPath);
        imagedestroy($wm);

        try {
            // Anropa UTAN x/y → default = (0,0)
            $image->addWatermark($wmPath);

            $resized = $image->getImageResized();
            $this->assertNotNull($resized);

            // Kontrollera att mönstret exakt överlagras i hörnet:
            // (0,0) => blå, (1,0) => röd, (0,1) => grön, (1,1) => vit.
            // Coalesce-mutanten som väljer $this->image före $this->imageResized
            // gör att dessa pixlar *inte* får mönstret → testet faller.
            $this->assertPixelColor($resized, 0, 0, 0, 0, 255);   // blå
            $this->assertPixelColor($resized, 1, 0, 255, 0, 0);   // röd
            $this->assertPixelColor($resized, 0, 1, 0, 255, 0);   // grön
            $this->assertPixelColor($resized, 1, 1, 255, 255, 255);   // vit
        } finally {
            if (file_exists($wmPath)) {
                @unlink($wmPath);
            }
        }
    }

    /**
     * Hjälpmetod för att asserta en pixel-färg exakt.
     */
    private function assertPixelColor(GdImage $img, int $x, int $y, int $r, int $g, int $b): void
    {
        $index = imagecolorat($img, $x, $y);
        $this->assertIsInt($index, "Kunde inte läsa pixel vid ($x, $y)");

        $colors = imagecolorsforindex($img, $index);
        /** @var array{red:int<0,255>,green:int<0,255>,blue:int<0,255>,alpha:int<0,127>} $colors */

        $this->assertSame($r, $colors['red'], "R-fel vid ($x, $y)");
        $this->assertSame($g, $colors['green'], "G-fel vid ($x, $y)");
        $this->assertSame($b, $colors['blue'], "B-fel vid ($x, $y)");
    }
    public function testResizeImageCropThrowsWhenOneDimensionIsInvalid(): void
    {
        $image = new Image($this->testImagePath);

        $ref = new ReflectionClass($image);

        // Sätt en giltig resized-bild så crop() inte stannar på "Ingen ändrad bild att beskära."
        $prop = $ref->getProperty('imageResized');
        $prop->setAccessible(true);

        $canvas = imagecreatetruecolor(5, 5);
        if ($canvas === false) {
            $this->fail('Kunde inte skapa canvas för crop-dimensionstest.');
        }
        $prop->setValue($image, $canvas);

        $crop = $ref->getMethod('crop');
        $crop->setAccessible(true);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Ogiltiga beskärningsdimensioner');

        // Bredd ogiltig men höjd giltig -> ska kasta.
        // Dödar LogicalOr-mutanten: (<=0 || <=0) -> (<=0 && <=0)
        $crop->invoke($image, 5, 5, 0, 4);
    }

    public function testCropStartXUsesRoundHalfUpAndDividesByTwo(): void
    {
        $image = new Image($this->testImagePath);

        $ref = new ReflectionClass($image);

        $prop = $ref->getProperty('imageResized');
        $prop->setAccessible(true);

        $canvas = imagecreatetruecolor(5, 5);
        $blue  = imagecolorallocate($canvas, 0, 0, 255);
        $green = imagecolorallocate($canvas, 0, 255, 0);
        $red   = imagecolorallocate($canvas, 255, 0, 0);

        if ($blue === false || $green === false || $red === false) {
            $this->fail('Kunde inte allokera färger för cropStartX-testet.');
        }

        // Kolumner: 0-1 blå, 2 grön, 3-4 röd
        imagefilledrectangle($canvas, 0, 0, 1, 4, $blue);
        imagefilledrectangle($canvas, 2, 0, 2, 4, $green);
        imagefilledrectangle($canvas, 3, 0, 4, 4, $red);

        $prop->setValue($image, $canvas);

        $crop = $ref->getMethod('crop');
        $crop->setAccessible(true);

        // diffX = 1 => (diffX/2)=0.5:
        // round => 1 (korrekt), floor => 0 (mutant), /3 => 0.333 -> round => 0 (mutant)
        $crop->invoke($image, 5, 5, 4, 4);

        $out = $image->getImageResized();
        $this->assertNotNull($out);

        // Sampla en punkt som med korrekt cropStartX hamnar i gröna kolumnen:
        // source-x = cropStartX + 1 = 2 (grön)
        $rgbIndex = imagecolorat($out, 1, 1);
        $this->assertIsInt($rgbIndex);

        $colors = imagecolorsforindex($out, $rgbIndex);
        /** @var array{red:int<0,255>,green:int<0,255>,blue:int<0,255>,alpha:int<0,127>} $colors */

        $this->assertGreaterThan($colors['red'], $colors['green'], 'Mitten ska vara grön (cropStartX centrering).');
        $this->assertGreaterThan($colors['blue'], $colors['green'], 'Mitten ska vara grön (cropStartX centrering).');
    }

    public function testCropStartYUsesRoundHalfUpAndDividesByTwo(): void
    {
        $image = new Image($this->testImagePath);

        $ref = new ReflectionClass($image);

        $prop = $ref->getProperty('imageResized');
        $prop->setAccessible(true);

        $canvas = imagecreatetruecolor(5, 5);
        $blue  = imagecolorallocate($canvas, 0, 0, 255);
        $green = imagecolorallocate($canvas, 0, 255, 0);
        $red   = imagecolorallocate($canvas, 255, 0, 0);

        if ($blue === false || $green === false || $red === false) {
            $this->fail('Kunde inte allokera färger för cropStartY-testet.');
        }

        // Rader: 0-1 blå, 2 grön, 3-4 röd
        imagefilledrectangle($canvas, 0, 0, 4, 1, $blue);
        imagefilledrectangle($canvas, 0, 2, 4, 2, $green);
        imagefilledrectangle($canvas, 0, 3, 4, 4, $red);

        $prop->setValue($image, $canvas);

        $crop = $ref->getMethod('crop');
        $crop->setAccessible(true);

        // diffY = 1 => 0.5 -> round=1 (korrekt), floor=0 (mutant), /3 -> 0 (mutant)
        $crop->invoke($image, 5, 5, 4, 4);

        $out = $image->getImageResized();
        $this->assertNotNull($out);

        // Sampla en punkt som med korrekt cropStartY hamnar i gröna raden:
        // source-y = cropStartY + 1 = 2 (grön)
        $rgbIndex = imagecolorat($out, 1, 1);
        $this->assertIsInt($rgbIndex);

        $colors = imagecolorsforindex($out, $rgbIndex);
        /** @var array{red:int<0,255>,green:int<0,255>,blue:int<0,255>,alpha:int<0,127>} $colors */

        $this->assertGreaterThan($colors['red'], $colors['green'], 'Mitten ska vara grön (cropStartY centrering).');
        $this->assertGreaterThan($colors['blue'], $colors['green'], 'Mitten ska vara grön (cropStartY centrering).');
    }

    public function testDestructorDestroysResizedImageResource(): void
    {
        $img = new class ($this->testImagePath) extends Image {
            public function __destruct()
            {
                // Avsiktligt tom: vi vill styra när parent::__destruct() körs i testet.
            }

            public function destroyNow(): void
            {
                parent::__destruct();
            }

            public function exposeResized(): mixed
            {
                return $this->imageResized;
            }
        };

        $img->resizeImage(10, 10, 'exact');

        $resized = $img->exposeResized();
        $this->assertInstanceOf(GdImage::class, $resized);

        $resizedId = spl_object_id($resized);

        $img->destroyNow();

        // Dödar FunctionCallRemoval-mutanten på imagedestroy($this->imageResized)
        $this->assertContains(
            $resizedId,
            ImageDestroySpy::$calls,
            'Destructorn måste anropa imagedestroy() för resized-bilden när den finns.'
        );
    }

    public function testGetOptimalCropUsesRoundForOptimalHeightFloorWouldDiffer(): void
    {
        $image = new Image($this->testImagePath);

        $ref = new ReflectionClass(Image::class);

        $widthProp = $ref->getProperty('width');
        $heightProp = $ref->getProperty('height');
        $widthProp->setAccessible(true);
        $heightProp->setAccessible(true);

        // Välj tal så att height/optimalRatio = 2.857... (round=3, floor=2)
        // width=7, height=5, newWidth=4, newHeight=2:
        // widthRatio=7/4=1.75, heightRatio=5/2=2.5 => optimalRatio=1.75
        // optimalHeight = round(5/1.75)=round(2.857)=3
        $widthProp->setValue($image, 7);
        $heightProp->setValue($image, 5);

        $m = $ref->getMethod('getOptimalCrop');
        $m->setAccessible(true);

        /** @var array{optimalWidth:int,optimalHeight:int} $dims */
        $dims = $m->invoke($image, 4, 2);

        $this->assertSame(4, $dims['optimalWidth']);
        $this->assertSame(
            3,
            $dims['optimalHeight'],
            'optimalHeight ska vara round(height/ratio). floor-mutanten ger 2.'
        );
    }

    public function testGetOptimalCropUsesRoundForOptimalHeightCeilWouldDiffer(): void
    {
        $image = new Image($this->testImagePath);

        $ref = new ReflectionClass(Image::class);

        $widthProp = $ref->getProperty('width');
        $heightProp = $ref->getProperty('height');
        $widthProp->setAccessible(true);
        $heightProp->setAccessible(true);

        // Välj tal så att height/optimalRatio = 4.2 (round=4, ceil=5)
        // width=10, height=7, newWidth=6, newHeight=2:
        // widthRatio=10/6=1.666..., heightRatio=7/2=3.5 => optimalRatio=1.666...
        // optimalHeight = round(7/1.666...)=round(4.2)=4
        $widthProp->setValue($image, 10);
        $heightProp->setValue($image, 7);

        $m = $ref->getMethod('getOptimalCrop');
        $m->setAccessible(true);

        /** @var array{optimalWidth:int,optimalHeight:int} $dims */
        $dims = $m->invoke($image, 6, 2);

        $this->assertSame(6, $dims['optimalWidth']);
        $this->assertSame(
            4,
            $dims['optimalHeight'],
            'optimalHeight ska vara round(height/ratio). ceil-mutanten ger 5.'
        );
    }

    public function testCropThrowsWhenNewHeightIsZero(): void
    {
        $image = new Image($this->testImagePath);

        $ref = new ReflectionClass($image);

        // Sätt en giltig resized så crop() inte fastnar på "Ingen ändrad bild..."
        $resizedProp = $ref->getProperty('imageResized');
        $resizedProp->setAccessible(true);

        $canvas = imagecreatetruecolor(5, 5);
        if ($canvas === false) {
            $this->fail('Kunde inte skapa canvas för crop(.,.,.,0)-test.');
        }
        $resizedProp->setValue($image, $canvas);

        $crop = $ref->getMethod('crop');
        $crop->setAccessible(true);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Ogiltiga beskärningsdimensioner');

        // Dödar LessThanOrEqualTo-mutanten: newHeight==0 måste kasta
        $crop->invoke($image, 5, 5, 4, 0);
    }

    public function testCropStartUsesRoundNotCeilWhenNewIsLargerThanOptimalByOne(): void
    {
        $image = new Image($this->testImagePath);

        $ref = new ReflectionClass($image);

        $resizedProp = $ref->getProperty('imageResized');
        $resizedProp->setAccessible(true);

        // Källa 4x4, med en tydlig grön pixel i (0,0)
        $src = imagecreatetruecolor(4, 4);
        if ($src === false) {
            $this->fail('Kunde inte skapa källbild för cropStart round-vs-ceil test.');
        }

        $green = imagecolorallocate($src, 0, 255, 0);
        $black = imagecolorallocate($src, 0, 0, 0);
        if ($green === false || $black === false) {
            $this->fail('Kunde inte allokera färger för cropStart round-vs-ceil test.');
        }

        imagefilledrectangle($src, 0, 0, 3, 3, $black);
        imagesetpixel($src, 0, 0, $green);

        $resizedProp->setValue($image, $src);

        $crop = $ref->getMethod('crop');
        $crop->setAccessible(true);

        // optimal=4, new=5 => (optimal-new)/2 = -0.5:
        // round(-0.5)=-1 (original) -> vänsterkant får "utanför-källa" => svart
        // ceil(-0.5)=0 (mutant) -> vänsterkant kommer från källa (0,0) => grön pixel kan hamna i hörnet
        $crop->invoke($image, 4, 4, 5, 5);

        $out = $image->getImageResized();
        $this->assertNotNull($out);

        $idx = imagecolorat($out, 0, 0);
        $this->assertIsInt($idx);

        $c = imagecolorsforindex($out, $idx);
        /** @var array{red:int<0,255>,green:int<0,255>,blue:int<0,255>,alpha:int<0,127>} $c */

        // Original ska ge svart i (0,0) p.g.a. negativ offset från round(-0.5)=-1.
        // Ceil-mutanten (0) skulle istället kunna plocka grönt från källans (0,0).
        $this->assertSame(0, $c['red']);
        $this->assertSame(0, $c['green']);
        $this->assertSame(0, $c['blue']);
    }

    public function testGetOptimalCropIsProtected(): void
    {
        $ref = new ReflectionClass(\Radix\File\Image::class);

        $m = $ref->getMethod('getOptimalCrop');

        // Dödar ProtectedVisibility-mutanten (protected -> private)
        $this->assertTrue(
            $m->isProtected(),
            'getOptimalCrop() ska vara protected (används via getDimensions()), inte private.'
        );
    }

    public function testGetOptimalCropUsesRoundForOptimalWidthFloorAndCeilWouldDiffer(): void
    {
        $image = new \Radix\File\Image($this->testImagePath);

        $ref = new ReflectionClass(\Radix\File\Image::class);

        $widthProp = $ref->getProperty('width');
        $heightProp = $ref->getProperty('height');
        $widthProp->setAccessible(true);
        $heightProp->setAccessible(true);

        $m = $ref->getMethod('getOptimalCrop');
        $m->setAccessible(true);

        // Fall A: width/ratio = 2.857... (round=3, floor=2)
        // width=5, height=7, newWidth=2, newHeight=4:
        // widthRatio=5/2=2.5, heightRatio=7/4=1.75 => ratio=1.75
        // optimalWidth = round(5/1.75)=round(2.857)=3
        $widthProp->setValue($image, 5);
        $heightProp->setValue($image, 7);

        /** @var array{optimalWidth:int,optimalHeight:int} $dimsA */
        $dimsA = $m->invoke($image, 2, 4);

        $this->assertSame(
            3,
            $dimsA['optimalWidth'],
            'optimalWidth ska vara round(width/ratio). floor-mutanten ger 2.'
        );

        // Fall B: width/ratio = 4.2 (round=4, ceil=5)
        // width=7, height=10, newWidth=2, newHeight=6:
        // widthRatio=7/2=3.5, heightRatio=10/6=1.666... => ratio=1.666...
        // optimalWidth = round(7/1.666...)=round(4.2)=4
        $widthProp->setValue($image, 7);
        $heightProp->setValue($image, 10);

        /** @var array{optimalWidth:int,optimalHeight:int} $dimsB */
        $dimsB = $m->invoke($image, 2, 6);

        $this->assertSame(
            4,
            $dimsB['optimalWidth'],
            'optimalWidth ska vara round(width/ratio). ceil-mutanten ger 5.'
        );
    }

    public function testCropStartXAndYUseRoundNotCeilWhenNegativeHalfIsolatedPerAxis(): void
    {
        $image = new \Radix\File\Image($this->testImagePath);

        $ref = new ReflectionClass($image);

        $resizedProp = $ref->getProperty('imageResized');
        $resizedProp->setAccessible(true);

        // Källa 4x4, med en grön pixel i (0,0) och resten svart
        $src = imagecreatetruecolor(4, 4);
        if ($src === false) {
            $this->fail('Kunde inte skapa källbild för round-vs-ceil axis-test.');
        }

        $green = imagecolorallocate($src, 0, 255, 0);
        $black = imagecolorallocate($src, 0, 0, 0);
        if ($green === false || $black === false) {
            $this->fail('Kunde inte allokera färger för round-vs-ceil axis-test.');
        }

        imagefilledrectangle($src, 0, 0, 3, 3, $black);
        imagesetpixel($src, 0, 0, $green);

        $crop = $ref->getMethod('crop');
        $crop->setAccessible(true);

        // --- Isolera X: diffX = 4-5 = -1 => -0.5, diffY = 4-4 = 0
        $resizedProp->setValue($image, $src);
        $crop->invoke($image, 4, 4, 5, 4);

        $outX = $image->getImageResized();
        $this->assertNotNull($outX);

        $idxX = imagecolorat($outX, 0, 0);
        $this->assertIsInt($idxX);

        $cX = imagecolorsforindex($outX, $idxX);
        /** @var array{red:int<0,255>,green:int<0,255>,blue:int<0,255>,alpha:int<0,127>} $cX */

        // round(-0.5) = -1 => utanför källa => svart i (0,0)
        // ceil(-0.5)  = 0  => kan plocka grönt från (0,0) => inte svart
        $this->assertSame(0, $cX['red']);
        $this->assertSame(0, $cX['green']);
        $this->assertSame(0, $cX['blue']);

        // --- Isolera Y: diffX = 0, diffY = 4-5 = -1 => -0.5
        $resizedProp->setValue($image, $src);
        $crop->invoke($image, 4, 4, 4, 5);

        $outY = $image->getImageResized();
        $this->assertNotNull($outY);

        $idxY = imagecolorat($outY, 0, 0);
        $this->assertIsInt($idxY);

        $cY = imagecolorsforindex($outY, $idxY);
        /** @var array{red:int<0,255>,green:int<0,255>,blue:int<0,255>,alpha:int<0,127>} $cY */

        $this->assertSame(0, $cY['red']);
        $this->assertSame(0, $cY['green']);
        $this->assertSame(0, $cY['blue']);
    }

    public function testResizeImageCropProducesExactRequestedDimensions(): void
    {
        // Skapa en 600x400 testbild (ratio spelar roll för optimalWidth/Height)
        $src = $this->tmpPath('crop_exact_dims_src.png');

        $img = imagecreatetruecolor(600, 400);
        if ($img === false) {
            $this->fail('Kunde inte skapa testbild för crop-dimensionstest.');
        }

        $red = imagecolorallocate($img, 255, 0, 0);
        if ($red === false) {
            imagedestroy($img);
            $this->fail('Kunde inte allokera färg för crop-dimensionstest.');
        }

        imagefill($img, 0, 0, $red);
        imagepng($img, $src);
        imagedestroy($img);

        try {
            $image = new Image($src);

            // Om crop()-anropet tas bort blir resized 300x200 (optimal), inte 200x200.
            $image->resizeImage(200, 200, 'crop');

            $resized = $image->getImageResized();
            $this->assertNotNull($resized);

            $this->assertSame(
                200,
                imagesx($resized),
                'crop-läget måste resultera i exakt newWidth. Utan crop() blir det optimalWidth.'
            );
            $this->assertSame(
                200,
                imagesy($resized),
                'crop-läget måste resultera i exakt newHeight. Utan crop() blir det optimalHeight.'
            );
        } finally {
            if (file_exists($src)) {
                @unlink($src);
            }
        }
    }

    public function testWebpWithoutCreateFromWebpThrowsInvalidArgumentException(): void
    {
        if (function_exists('imagecreatefromwebp')) {
            $this->markTestSkipped('imagecreatefromwebp() finns, WEBP-throw-branch testas inte i denna miljö.');
        }

        $path = $this->tmpPath('no_webp_support.webp');

        // Minimal 1x1 WEBP (base64). Syfte: få mime_content_type() att säga image/webp.
        $webpBase64 = 'UklGRiIAAABXRUJQVlA4IBgAAAAwAQCdASoBAAEAAwA0JaQAA3AA/vuUAAA=';
        $bytes = base64_decode($webpBase64, true);
        if (!is_string($bytes) || $bytes === '') {
            $this->fail('Kunde inte skapa WEBP-bytes för testet.');
        }

        file_put_contents($path, $bytes);

        try {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('WEBP-bilder stöds inte på denna server.');

            // Konstruktor anropar openImage() och ska kasta InvalidArgumentException här.
            // Mutanten (throw -> new InvalidArgumentException(...)) leder istället typiskt till TypeError,
            // och då failar detta test -> mutanten dör.
            new Image($path);
        } finally {
            if (file_exists($path)) {
                @unlink($path);
            }
        }
    }

    public function testGetSizeByRatioIsProtectedAndUsesRound(): void
    {
        $ref = new ReflectionClass(Image::class);

        $m = $ref->getMethod('getSizeByRatio');

        // Dödar ProtectedVisibility-mutanten (protected -> private)
        $this->assertTrue(
            $m->isProtected(),
            'getSizeByRatio() ska vara protected (inte private).'
        );

        $m->setAccessible(true);

        $image = new Image($this->testImagePath);

        // Fall A: 7.5 -> round=8, floor=7
        /** @var int $a */
        $a = $m->invoke($image, 5, 3, 2);
        $this->assertSame(
            8,
            $a,
            'getSizeByRatio() ska använda round(). floor-mutanten ger 7 för 7.5.'
        );

        // Fall B: 7.2 -> round=7, ceil=8
        /** @var int $b */
        $b = $m->invoke($image, 12, 3, 5);
        $this->assertSame(
            7,
            $b,
            'getSizeByRatio() ska använda round(). ceil-mutanten ger 8 för 7.2.'
        );
    }

    public function testGetSizeByAutoIsProtected(): void
    {
        $ref = new ReflectionClass(Image::class);

        $m = $ref->getMethod('getSizeByAuto');

        // Dödar ProtectedVisibility-mutanten (protected -> private)
        $this->assertTrue(
            $m->isProtected(),
            'getSizeByAuto() ska vara protected (inte private).'
        );
    }

    public function testResizePngKeepsAlphaWhenSaved(): void
    {
        $src = $this->tmpPath('alpha_src.png');
        $out = $this->tmpPath('alpha_out.png');

        $img = imagecreatetruecolor(10, 10);
        if ($img === false) {
            $this->fail('Kunde inte skapa GD-bild för alpha-save-test.');
        }

        imagealphablending($img, false);
        imagesavealpha($img, true);

        $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
        if ($transparent === false) {
            imagedestroy($img);
            $this->fail('Kunde inte allokera transparent färg för alpha-save-test.');
        }

        imagefill($img, 0, 0, $transparent);

        // Rita en opak pixel så bilden inte är “tråkig”
        $red = imagecolorallocatealpha($img, 255, 0, 0, 0);
        if ($red === false) {
            imagedestroy($img);
            $this->fail('Kunde inte allokera röd färg för alpha-save-test.');
        }
        imagesetpixel($img, 5, 5, $red);

        imagepng($img, $src);
        imagedestroy($img);

        try {
            $image = new Image($src);
            $image->resizeImage(20, 20, 'exact');
            $image->saveImage($out);

            $this->assertFileExists($out);

            $reloaded = imagecreatefrompng($out);
            if ($reloaded === false) {
                $this->fail('Kunde inte läsa tillbaka PNG i alpha-save-test.');
            }

            try {
                $idx = imagecolorat($reloaded, 0, 0);
                $this->assertIsInt($idx);

                $c = imagecolorsforindex($reloaded, $idx);
                /** @var array{red:int<0,255>,green:int<0,255>,blue:int<0,255>,alpha:int<0,127>} $c */

                // KRITISKT: utan imagesavealpha(..., true) kan alpha försvinna vid save
                $this->assertSame(
                    127,
                    $c['alpha'],
                    'Sparad PNG ska behålla transparent alpha i hörnet.'
                );
            } finally {
                imagedestroy($reloaded);
            }
        } finally {
            if (file_exists($src)) {
                @unlink($src);
            }
            if (file_exists($out)) {
                @unlink($out);
            }
        }
    }

    public function testPngCompressionLevelMappingIsDeterministicAndClamped(): void
    {
        $image = new Image($this->testImagePath);

        $ref = new ReflectionClass(Image::class);
        $m = $ref->getMethod('pngCompressionLevel');
        $m->setAccessible(true);

        // Extremvärden
        $this->assertSame(0, $m->invoke($image, 100), 'quality=100 ska ge compression=0.');
        $this->assertSame(9, $m->invoke($image, 0), 'quality=0 ska ge compression=9.');

        // Halvor för att döda floor/ceil/round-mutanter:
        // (50/100)*9 = 4.5 -> round=5, floor=4
        $this->assertSame(4, $m->invoke($image, 50), 'quality=50 ska ge compression=4 (round(4.5)=5 => 9-5=4).');

        // (49/100)*9 = 4.41 -> round=4, ceil=5
        $this->assertSame(5, $m->invoke($image, 49), 'quality=49 ska ge compression=5 (round(4.41)=4 => 9-4=5).');
    }

    public function testPngCompressionLevelRejectsOutOfRangeQuality(): void
    {
        $image = new Image($this->testImagePath);

        $ref = new ReflectionClass(Image::class);
        $m = $ref->getMethod('pngCompressionLevel');
        $m->setAccessible(true);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Kvalitet måste vara mellan 0 och 100.');

        $m->invoke($image, 101);
    }

    public function testPngCompressionLevelIsProtected(): void
    {
        $ref = new ReflectionClass(Image::class);
        $m = $ref->getMethod('pngCompressionLevel');

        // Dödar ProtectedVisibility-mutanten (protected -> private)
        $this->assertTrue(
            $m->isProtected(),
            'pngCompressionLevel() ska vara protected (inte private).'
        );
    }

    public function testPngCompressionLevelClampsToZeroAndNineExactly(): void
    {
        $image = new Image($this->testImagePath);

        $ref = new ReflectionClass(Image::class);
        $m = $ref->getMethod('pngCompressionLevel');
        $m->setAccessible(true);

        $this->assertSame(0, $m->invoke($image, 100), 'quality=100 ska ge PNG compression level 0.');
        $this->assertSame(9, $m->invoke($image, 0), 'quality=0 ska ge PNG compression level 9.');

        // KRITISKT: dödar mutanten (/100 -> /99)
        // round((94/100)*9)=8 => 9-8=1, men /99 ger round(...)=9 => 9-9=0.
        $this->assertSame(
            1,
            $m->invoke($image, 94),
            'quality=94 ska ge PNG compression level 1 (testar att divisionen är /100 och inte /99).'
        );

        // Extra: se till att vi aldrig går under 0 eller över 9
        $this->assertGreaterThanOrEqual(0, $m->invoke($image, 100));
        $this->assertLessThanOrEqual(9, $m->invoke($image, 0));
    }
}
