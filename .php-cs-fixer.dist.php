<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = Finder::create()
    ->in([
        __DIR__ . '/src',
        __DIR__ . '/tests',
        // lägg till fler mappar om/när du vill (config, routes, support, osv)
    ])
    ->name('*.php')
    ->ignoreDotFiles(true)
    ->ignoreVCS(true);

$config = (new Config())
    ->setRiskyAllowed(true)
    ->setRules([
        // Modern standard (efterföljare till PSR-12)
        '@PER-CS' => true,

        // Anpassa efter faktisk PHP-version i projektet
        '@PHP83Migration' => true,

        // Några explicita regler vi vill försäkra oss om
        'array_syntax' => ['syntax' => 'short'],
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'no_unused_imports' => true,
        'global_namespace_import' => [
            'import_classes'   => true,
            'import_constants' => true,
            'import_functions' => true,
        ],
    ])
    ->setFinder($finder);

$config->setUnsupportedPhpVersionAllowed(true);

return $config;
