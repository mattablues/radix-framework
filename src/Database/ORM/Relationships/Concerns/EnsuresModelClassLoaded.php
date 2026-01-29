<?php

declare(strict_types=1);

namespace Radix\Database\ORM\Relationships\Concerns;

use Exception;
use LogicException;

trait EnsuresModelClassLoaded
{
    protected function ensureModelClassLoaded(string $fqcn): void
    {
        /** @var array<string, bool> $loading */
        static $loading = [];

        // Ingen early-return här (för att undvika ReturnRemoval-mutanten)
        if (!class_exists($fqcn, false)) {
            // Re-entrant: kontrollera VÄRDET, inte bara nyckelns existens
            if (($loading[$fqcn] ?? false) === true) {
                throw new LogicException("Re-entrant autoload detected for '{$fqcn}'.");
            }

            $loading[$fqcn] = true;
            try {
                if (!class_exists($fqcn)) {
                    throw new Exception("Model class '{$fqcn}' not found.");
                }
            } finally {
                unset($loading[$fqcn]);
            }
        }
    }
}
