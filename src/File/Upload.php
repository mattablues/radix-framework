<?php

declare(strict_types=1);

namespace Radix\File;

use finfo;
use Radix\Support\Validator;
use RuntimeException;

class Upload
{
    // Egenskaper för att hantera uppladdningen
    /** @var array<string,mixed> */
    protected array $file;
    /** @var array<string,array<int,string>> */
    protected array $errors = [];
    protected string $uploadDirectory;

    /**
     * @var array<string,string>
     */
    private const array MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    /**
     * @param array<string,mixed> $file
     */
    public function __construct(array $file, string $uploadDirectory)
    {
        $this->file = $file;
        $this->uploadDirectory = $uploadDirectory;

        // Kontrollera att uppladdningsmappen finns, annars skapa den
        if (!is_dir($uploadDirectory)) {
            @mkdir($uploadDirectory, 0o755, true);

            // Oavsett vad mkdir() rapporterade: om katalogen fortfarande inte finns är det fel.
            if (!is_dir($uploadDirectory)) {
                throw new RuntimeException("Misslyckades med att skapa uppladdningsmappen: $uploadDirectory");
            }
        }
    }

    /**
     * Validera uppladdad fil med given regeluppsättning.
     *
     * @param  array<string,string|array<int,string>>  $rules
     * @return bool
     */
    public function validate(array $rules): bool
    {
        $validator = new Validator($this->file, $rules);

        if (!$validator->validate()) {
            $this->errors = $validator->errors();
            return false;
        }

        return true;
    }

    /**
     * Flytta den uppladdade filen till målplatsen.
     *
     * @param string $fileName
     * @return string
     * @throws RuntimeException
     */
    public function save(string $fileName = ''): string
    {
        $this->assertUploadOk();

        $tmpName = $this->getTmpName();

        $fileName = $fileName !== ''
            ? $this->sanitizeFileName($fileName)
            : $this->generateFileNameFromMimeType($tmpName);

        $targetPath = rtrim($this->uploadDirectory, '/\\') . '/' . $fileName;

        if (!move_uploaded_file($tmpName, $targetPath)) {
            throw new RuntimeException("Misslyckades med att flytta uppladdad fil till $targetPath");
        }

        return $targetPath;
    }

    /**
     * Bearbeta filen som en bild (t.ex. ändra storlek, skapa miniatyrbild, rotera).
     *
     * @param callable $processCallback
     * @param string $outputFileName
     * @return string
     * @throws RuntimeException
     */
    public function processImage(callable $processCallback, string $outputFileName = ''): string
    {
        $this->assertUploadOk();

        $tmpName = $this->getTmpName();

        $image = new Image($tmpName);
        $processCallback($image);

        $outputFileName = $outputFileName !== ''
            ? $this->sanitizeFileName($outputFileName)
            : $this->generateFileNameFromMimeType($tmpName);

        $targetPath = rtrim($this->uploadDirectory, '/\\') . '/' . $outputFileName;

        $image->saveImage($targetPath);

        return $targetPath;
    }

    /**
     * Generera ett unikt filnamn.
     *
     * @return string
     */
    protected function generateFileName(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * @throws RuntimeException
     */
    protected function generateFileNameFromMimeType(string $tmpName): string
    {
        $mimeType = $this->detectMimeType($tmpName);
        $extension = self::MIME_EXTENSIONS[$mimeType] ?? null;

        if ($extension === null) {
            throw new RuntimeException("Otillåten filtyp: $mimeType");
        }

        return $this->generateFileName() . '.' . $extension;
    }

    /**
     * @throws RuntimeException
     */
    protected function detectMimeType(string $tmpName): string
    {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($tmpName);

        if (!is_string($mimeType) || $mimeType === '') {
            throw new RuntimeException('Kunde inte identifiera MIME-typ för uppladdad fil.');
        }

        return strtolower($mimeType);
    }

    /**
     * @throws RuntimeException
     */
    protected function sanitizeFileName(string $fileName): string
    {
        if ($fileName !== basename($fileName)) {
            throw new RuntimeException('Ogiltigt filnamn.');
        }

        if (!preg_match('/\A[a-zA-Z0-9._-]+\z/', $fileName)) {
            throw new RuntimeException('Ogiltigt filnamn.');
        }

        if ($fileName === '.' || $fileName === '..') {
            throw new RuntimeException('Ogiltigt filnamn.');
        }

        return $fileName;
    }

    /**
     * @throws RuntimeException
     */
    protected function assertUploadOk(): void
    {
        $error = $this->file['error'] ?? null;

        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Filen är inte en giltig uppladdning.');
        }
    }

    /**
     * @throws RuntimeException
     */
    protected function getTmpName(): string
    {
        $tmpName = $this->file['tmp_name'] ?? null;

        if (!is_string($tmpName) || $tmpName === '') {
            throw new RuntimeException('Ogiltigt tmp_name för uppladdad fil.');
        }

        return $tmpName;
    }

    /**
     * Hämta eventuella valideringsfel.
     *
     * @return array<string,array<int,string>>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
