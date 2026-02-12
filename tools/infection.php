<?php

declare(strict_types=1);

putenv('APP_ENV=development');

// pcov | xdebug
$mode = $argv[1] ?? '';
if ($mode !== 'pcov' && $mode !== 'xdebug') {
    fwrite(STDERR, "Usage: php tools/infection.php pcov|xdebug\n");
    exit(1);
}

$phpArgs = [];
if ($mode === 'pcov') {
    $phpArgs[] = '-d';
    $phpArgs[] = 'pcov.enabled=1';
    $phpArgs[] = '-d';
    $phpArgs[] = 'pcov.directory=src';
} else {
    putenv('XDEBUG_MODE=coverage');
}

$projectRoot = dirname(__DIR__);

// Rensa Infection-cache
$infectionCache = $projectRoot . DIRECTORY_SEPARATOR . '.infection';
if (is_dir($infectionCache)) {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($infectionCache, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $file) {
        if ($file->isDir()) {
            @rmdir($file->getPathname());
        } else {
            @unlink($file->getPathname());
        }
    }
    @rmdir($infectionCache);
}

// Rensa gamla rapporter
@unlink($projectRoot . DIRECTORY_SEPARATOR . 'infection-report.html');
@unlink($projectRoot . DIRECTORY_SEPARATOR . 'infection-report.txt');
@unlink($projectRoot . DIRECTORY_SEPARATOR . 'infection-summary.json');

$infectionBin = $projectRoot . '/vendor/bin/infection';

$cmdParts = array_merge(
    [PHP_BINARY],
    $phpArgs,
    [$infectionBin],
    [
        '--configuration=infection.json.dist',
        '--threads=1',
        '--logger-html=infection-report.html',
        '--logger-text=infection-report.txt',
        '--logger-summary-json=infection-summary.json',
        '--log-verbosity=all',
        '--no-interaction',
        '--no-progress',
    ]
);

$cmd = implode(' ', array_map('escapeshellarg', $cmdParts));
passthru($cmd, $exitCode);
exit((int) $exitCode);
