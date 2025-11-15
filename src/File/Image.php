<?php

declare(strict_types=1);

namespace Radix\File;

use GdImage;
use InvalidArgumentException;
use RuntimeException;
use UnexpectedValueException;

class Image
{
    protected GdImage $image;
    protected int $width;
    protected int $height;
    protected ?GdImage $imageResized = null;
    protected int $defaultQuality = 100;

    public function __construct(string $file)
    {
        // Kontrollera att GD-biblioteket är installerat
        if (!extension_loaded('gd')) {
            throw new UnexpectedValueException('PHP GD är inte installerad. Installera det för att hantera bilder.');
        }

        // Kontrollera om filen finns
        if (!file_exists($file)) {
            throw new InvalidArgumentException("Bilden \"$file\" kunde inte hittas.");
        }

        // Försök öppna bilden
        $this->image = $this->openImage($file);

        // Sätt bredd och höjd för bilden
        $this->width = imagesx($this->image);
        $this->height = imagesy($this->image);
    }
    
    public function getImageResized(): ?GdImage
    {
        return $this->imageResized;
    }

    public function resizeImage(int $newWidth, int $newHeight, string $option = 'auto'): void
    {
        $dimensions = $this->getDimensions($newWidth, $newHeight, strtolower($option));
        $optimalWidth = $dimensions['optimalWidth'];
        $optimalHeight = $dimensions['optimalHeight'];

        if ($optimalWidth <= 0 || $optimalHeight <= 0) {
            throw new InvalidArgumentException(
                sprintf('Ogiltiga bilddimensioner: %d x %d', $optimalWidth, $optimalHeight)
            );
        }

        $this->imageResized = imagecreatetruecolor($optimalWidth, $optimalHeight);

        $transparent = imagecolorallocatealpha($this->imageResized, 0, 0, 0, 127);
        if ($transparent === false) {
            throw new RuntimeException('Kunde inte allokera transparent färg för den ändrade bilden.');
        }

        imagefill($this->imageResized, 0, 0, $transparent);
        imagesavealpha($this->imageResized, true);

        if (!imagecopyresampled(
            $this->imageResized,
            $this->image,
            0,
            0,
            0,
            0,
            $optimalWidth,
            $optimalHeight,
            $this->width,
            $this->height
        )) {
            throw new RuntimeException('Misslyckades med att ändra storlek på bilden.');
        }

        if ($option === 'crop') {
            $this->crop($optimalWidth, $optimalHeight, $newWidth, $newHeight);
        }
    }

    public function setDefaultQuality(int $quality): void
    {
        if ($quality < 0 || $quality > 100) {
            throw new InvalidArgumentException('Kvalitet måste vara mellan 0 och 100.');
        }
        $this->defaultQuality = $quality;
    }

    public function saveImage(string $path, ?int $quality = null): void
    {
        $quality = $quality ?? $this->defaultQuality;

        if (!$this->imageResized instanceof \GdImage) {
            throw new RuntimeException('Ingen ändrad bild att spara. Anropa resizeImage() innan saveImage().');
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        switch ($ext) {
            case 'jpg':
            case 'jpeg':
                if (!imagejpeg($this->imageResized, $path, $quality)) {
                    throw new RuntimeException("Misslyckades med att spara som JPEG till \"$path\".");
                }
                break;

            case 'gif':
                if (!imagegif($this->imageResized, $path)) {
                    throw new RuntimeException("Misslyckades med att spara som GIF till \"$path\".");
                }
                break;

            case 'png':
                // Säkerställ att $invertScaleQuality är en heltal
                $scaleQuality = round(($quality / 100) * 9);
                $invertScaleQuality = max(0, min(9, (int)(9 - $scaleQuality)));

                if (!imagepng($this->imageResized, $path, $invertScaleQuality)) {
                    throw new RuntimeException("Misslyckades med att spara som PNG till \"$path\".");
                }
                break;

            case 'webp':
                if (!imagewebp($this->imageResized, $path, $quality)) {
                    throw new RuntimeException("Misslyckades med att spara som WEBP till \"$path\".");
                }
                break;

            default:
                throw new InvalidArgumentException("Okänt filformat \"$ext\".");
        }
    }

    public function rotateImage(float $angle, int $bgColor = 0): void
    {
        $rotatedImage = imagerotate($this->imageResized ?? $this->image, $angle, $bgColor);
        if (!$rotatedImage) {
            throw new RuntimeException('Misslyckades med att rotera bilden.');
        }

        $this->imageResized = $rotatedImage;
    }

    public function addWatermark(string $watermarkPath, int $x = 0, int $y = 0): void
    {
        $watermark = $this->openImage($watermarkPath);
        $wmWidth = imagesx($watermark);
        $wmHeight = imagesy($watermark);

        $baseImage = $this->imageResized ?? $this->image;

        if (!imagecopy($baseImage, $watermark, $x, $y, 0, 0, $wmWidth, $wmHeight)) {
            throw new RuntimeException('Misslyckades med att applicera vattenmärket.');
        }

        $this->imageResized = $baseImage;
    }

    /**
     * @return array{
     *     width: int,
     *     height: int,
     *     resizedWidth: int|null,
     *     resizedHeight: int|null
     * }
     */
    public function getImageInfo(): array
    {
        return [
            'width' => $this->width,
            'height' => $this->height,
            'resizedWidth' => $this->imageResized ? imagesx($this->imageResized) : null,
            'resizedHeight' => $this->imageResized ? imagesy($this->imageResized) : null,
        ];
    }

    public function applyGrayscale(): void
    {
        $baseImage = $this->imageResized ?? $this->image;

        if (!imagefilter($baseImage, IMG_FILTER_GRAYSCALE)) {
            throw new RuntimeException('Misslyckades med att konvertera bilden till gråskala.');
        }

        $this->imageResized = $baseImage;
    }

    public function saveThumb(string $path, int $quality = 100): void
    {
        $directory = pathinfo($path, PATHINFO_DIRNAME);
        $filename = pathinfo($path, PATHINFO_FILENAME);
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        $thumbPath = "$directory/$filename.thumb.$ext";

        $this->saveImage($thumbPath, $quality);
    }

    public function openImage(string $filePath): GdImage
    {
        // Kontrollera filens MIME-typ
        $mimeType = mime_content_type($filePath);

        $img = match ($mimeType) {
            'image/jpeg' => imagecreatefromjpeg($filePath),
            'image/png' => imagecreatefrompng($filePath),
            'image/gif' => imagecreatefromgif($filePath),
            'image/webp' => function_exists('imagecreatefromwebp')
                ? imagecreatefromwebp($filePath)
                : throw new InvalidArgumentException('WEBP-bilder stöds inte på denna server.'),
            default => throw new InvalidArgumentException("Bildformat \"$mimeType\" stöds inte."),
        };

        if ($img === false) {
            throw new RuntimeException('Kunde inte öppna bilden.');
        }

        return $img;
    }

    /**
     * Beräkna mål-dimensioner baserat på valt alternativ.
     *
     * @return array{
     *     optimalWidth: int,
     *     optimalHeight: int
     * }
     */
    protected function getDimensions(int $newWidth, int $newHeight, string $option): array
    {
        return match ($option) {
            'exact' => ['optimalWidth' => $newWidth, 'optimalHeight' => $newHeight],
            'portrait' => [
                'optimalWidth' => $this->getSizeByRatio($newHeight, $this->height, $this->width),
                'optimalHeight' => $newHeight,
            ],
            'landscape' => [
                'optimalWidth' => $newWidth,
                'optimalHeight' => $this->getSizeByRatio($newWidth, $this->width, $this->height),
            ],
            'auto' => $this->getSizeByAuto($newWidth, $newHeight),
            'crop' => $this->getOptimalCrop($newWidth, $newHeight),
            default => throw new InvalidArgumentException("Okänt alternativ \"$option\"."),
        };
    }

    protected function getSizeByRatio(int $targetDimension, int $primaryDimension, int $secondaryDimension): int
    {
        return (int)round($targetDimension * ($primaryDimension / $secondaryDimension));
    }

    /**
     * Beräkna optimal storlek med bibehållen aspect ratio.
     *
     * @return array{
     *     optimalWidth: int,
     *     optimalHeight: int
     * }
     */
    protected function getSizeByAuto(int $newWidth, int $newHeight): array
    {
        if ($this->width > $this->height) {
            $scaledHeight = (int)round($newWidth * ($this->height / $this->width));
            return [
                'optimalWidth' => $newWidth,
                'optimalHeight' => $scaledHeight,
            ];
        }

        if ($this->width < $this->height) {
            $scaledWidth = (int)round($newHeight * ($this->width / $this->height));
            return [
                'optimalWidth' => $scaledWidth,
                'optimalHeight' => $newHeight,
            ];
        }

        return [
            'optimalWidth' => $newWidth,
            'optimalHeight' => $newHeight,
        ];
    }

    /**
     * Beräkna optimala beskärningsdimensioner.
     *
     * @return array{
     *     optimalWidth: int,
     *     optimalHeight: int
     * }
     */
    protected function getOptimalCrop(int $newWidth, int $newHeight): array
    {
        $widthRatio = $this->width / $newWidth;
        $heightRatio = $this->height / $newHeight;
        $optimalRatio = min($widthRatio, $heightRatio);

        return [
            'optimalWidth' => (int)round($this->width / $optimalRatio),
            'optimalHeight' => (int)round($this->height / $optimalRatio),
        ];
    }

    private function crop(int $optimalWidth, int $optimalHeight, int $newWidth, int $newHeight): void
    {
        if ($newWidth <= 0 || $newHeight <= 0) {
            throw new InvalidArgumentException(
                sprintf('Ogiltiga beskärningsdimensioner: %d x %d', $newWidth, $newHeight)
            );
        }

        $cropStartX = (int)round(($optimalWidth - $newWidth) / 2);
        $cropStartY = (int)round(($optimalHeight - $newHeight) / 2);

        $crop = imagecreatetruecolor($newWidth, $newHeight);
        if (!$crop instanceof \GdImage) {
            throw new RuntimeException('Kunde inte skapa canvas för beskärd bild.');
        }

        if (!$this->imageResized instanceof \GdImage) {
            throw new RuntimeException('Ingen ändrad bild att beskära.');
        }

        if (!imagecopyresampled(
            $crop,
            $this->imageResized,
            0,
            0,
            $cropStartX,
            $cropStartY,
            $newWidth,
            $newHeight,
            $newWidth,
            $newHeight
        )) {
            throw new RuntimeException('Misslyckades med att beskära bilden.');
        }

        $this->imageResized = $crop;
    }

    public function __destruct()
    {
        if ($this->imageResized !== null) {
            imagedestroy($this->imageResized);
        }
        imagedestroy($this->image);
    }
}