<?php

declare(strict_types=1);

$args = array_slice($_SERVER['argv'] ?? [], 1);

$parts = [
    escapeshellarg(PHP_BINARY),
    escapeshellarg(__DIR__ . '/../vendor/bin/php-cs-fixer'),
    'fix',
];

foreach ($args as $arg) {
    $parts[] = escapeshellarg($arg);
}

$cmd = implode(' ', $parts);

passthru($cmd, $exitCode);

exit($exitCode);
