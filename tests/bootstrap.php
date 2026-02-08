<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/Support/RadixSupportFsOverrides.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'Dummy\\';

    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $relativePath = str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';

    $file = __DIR__ . DIRECTORY_SEPARATOR . 'Fixtures' . DIRECTORY_SEPARATOR . 'Dummy' . DIRECTORY_SEPARATOR . $relativePath;

    if (is_file($file)) {
        require $file;
    }
});
