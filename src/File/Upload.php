<?php

declare(strict_types=1);

namespace Radix\File;

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
     * @param array<string,mixed> $file
     */
    public function __construct(array $file, string $uploadDirectory)
    {
        $this->file = $file;
        $this->uploadDirectory = $uploadDirectory;

        // Kontrollera att uppladdningsmappen finns, annars skapa den
        if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true) && !is_dir($uploadDirectory)) {
            throw new RuntimeException("Misslyckades med att skapa uppladdningsmappen: $uploadDirectory");
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
        $fileName = $fileName ?: $this->generateFileName();
        $targetPath = rtrim($this->uploadDirectory, '/') . '/' . $fileName;

        if (!move_uploaded_file($this->file['tmp_name'], $targetPath)) {
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
        $image = new Image($this->file['tmp_name']);
        $processCallback($image);

        $outputFileName = $outputFileName ?: $this->generateFileName();
        $targetPath = rtrim($this->uploadDirectory, '/') . '/' . $outputFileName;

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
        $extension = pathinfo($this->file['name'], PATHINFO_EXTENSION);
        return uniqid('', true) . "." . strtolower($extension);
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