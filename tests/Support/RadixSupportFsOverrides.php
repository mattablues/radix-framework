<?php

declare(strict_types=1);

namespace Radix\Support;

if (!class_exists(__NAMESPACE__ . '\\FsSpy')) {
    final class FsSpy
    {
        public static int $mkdirCallCount = 0;
        public static ?string $lastMkdirPath = null;
        public static ?int $lastMkdirPermissions = null;

        public static function reset(): void
        {
            self::$mkdirCallCount = 0;
            self::$lastMkdirPath = null;
            self::$lastMkdirPermissions = null;
        }
    }
}

/**
 * @param class-string $spyClass
 */
function _fsSpyIncrement(string $spyClass, string $property): void
{
    if (!property_exists($spyClass, $property)) {
        return;
    }

    /** @var mixed $current */
    $current = $spyClass::${$property};

    $spyClass::${$property} = is_int($current) ? ($current + 1) : 1;
}

/**
 * @param class-string $spyClass
 */
function _fsSpySetString(string $spyClass, string $property, string $value): void
{
    if (!property_exists($spyClass, $property)) {
        return;
    }

    $spyClass::${$property} = $value;
}

if (!function_exists(__NAMESPACE__ . '\\mkdir')) {
    function mkdir(string $directory, int $permissions = 0o777, bool $recursive = false, mixed $context = null): bool
    {
        FsSpy::$mkdirCallCount++;
        FsSpy::$lastMkdirPath = $directory;
        FsSpy::$lastMkdirPermissions = $permissions;

        // Stöd för FileCacheTest: om FileCacheSpy finns, uppdatera även den
        if (class_exists(__NAMESPACE__ . '\\FileCacheSpy')) {
            /** @var class-string $spyClass */
            $spyClass = __NAMESPACE__ . '\\FileCacheSpy';

            _fsSpyIncrement($spyClass, 'mkdirCallCount');
            _fsSpySetString($spyClass, 'lastMkdirPath', $directory);

            if (property_exists($spyClass, 'lastMkdirPermissions')) {
                $spyClass::$lastMkdirPermissions = $permissions;
            }
        }

        /** @var resource|null $context */
        return \mkdir($directory, $permissions, $recursive, $context);
    }
}

if (!function_exists(__NAMESPACE__ . '\\file_get_contents')) {
    function file_get_contents(
        string $filename,
        bool $use_include_path = false,
        mixed $context = null,
        int $offset = 0,
        ?int $length = null
    ): string|false {
        // Stöd för GeoLocatorTest: om en HTTP-spy finns, spara anropad URL.
        if (class_exists(__NAMESPACE__ . '\\GeoLocatorHttpSpy')) {
            /** @var class-string $spyClass */
            $spyClass = __NAMESPACE__ . '\\GeoLocatorHttpSpy';

            if (property_exists($spyClass, 'lastFilename')) {
                $spyClass::$lastFilename = $filename;
            }

            if (property_exists($spyClass, 'lastUseIncludePath')) {
                $spyClass::$lastUseIncludePath = $use_include_path;
            }

            if (property_exists($spyClass, 'lastContextOptions') && is_resource($context)) {
                $options = stream_context_get_options($context);

                /** @var array<string,mixed> $options */
                $spyClass::$lastContextOptions = $options;
            }

            /** @var mixed $useFake */
            $useFake = property_exists($spyClass, 'useFake') ? $spyClass::$useFake : false;

            if ($useFake === true) {
                /** @var mixed $fakeBody */
                $fakeBody = property_exists($spyClass, 'fakeBody') ? $spyClass::$fakeBody : null;

                return is_string($fakeBody) ? $fakeBody : '';
            }
        }

        // Stöd för FileCacheTest: räkna anrop om FileCacheSpy finns
        if (class_exists(__NAMESPACE__ . '\\FileCacheSpy')) {
            /** @var class-string $spyClass */
            $spyClass = __NAMESPACE__ . '\\FileCacheSpy';

            _fsSpyIncrement($spyClass, 'fileGetContentsCallCount');
            _fsSpySetString($spyClass, 'lastFileGetContentsPath', $filename);
        }

        // Normalisera $length för att matcha file_get_contents()-signaturen (>= 0 eller null)
        if ($length !== null && $length < 0) {
            $length = null;
        }

        // Fall back till riktiga funktionen
        if ($length === null) {
            /** @var resource|null $context */
            return \file_get_contents($filename, $use_include_path, $context, $offset);
        }

        /** @var resource|null $context */
        return \file_get_contents($filename, $use_include_path, $context, $offset, $length);
    }
}
