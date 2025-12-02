<?php

declare(strict_types=1);

namespace Radix\Console\Commands;

use Radix\Support\FileCache;

final class CacheClearCommand extends BaseCommand
{
    /**
     * Kör kommandot med givna argument.
     *
     * @param array<int,string> $args
     */
    public function execute(array $args): void
    {
        $this->__invoke($args);
    }

    /**
     * Gör objektet anropbart som ett kommando.
     *
     * @param array<int,string> $args
     */
    public function __invoke(array $args): void
    {
        if (in_array('--help', $args, true)) {
            $this->showHelp();
            return;
        }

        $this->coloredOutput("Rensar cache...\n", "green");

        // 1) App-cache (APP_CACHE_PATH eller ROOT_PATH/cache/app)
        $appPathEnv = getenv('APP_CACHE_PATH');
        $appCachePath = is_string($appPathEnv) && $appPathEnv !== ''
            ? $appPathEnv
            : null;

        $this->coloredOutput('- App-cache: ' . ($appCachePath ?? '[default ROOT_PATH/cache/app]'), 'yellow');

        $appCache = new FileCache($appCachePath);
        if ($appCache->clear()) {
            $this->coloredOutput("  ✔ App-cache rensad.", "green");
        } else {
            $this->coloredOutput("  ✖ Kunde inte rensa app-cache.", "red");
        }

        // 2) RateLimiter-cache (RATELIMIT_CACHE_PATH eller sys_get_temp_dir()/radix_ratelimit)
        $ratePathEnv = getenv('RATELIMIT_CACHE_PATH');
        if (is_string($ratePathEnv) && $ratePathEnv !== '') {
            $ratelimitDir = rtrim($ratePathEnv, '/\\');
        } else {
            $ratelimitDir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'radix_ratelimit';
        }

        $this->coloredOutput('- RateLimiter-cache: ' . $ratelimitDir, 'yellow');

        $deleted = $this->deleteCacheDirectory($ratelimitDir);
        if ($deleted) {
            $this->coloredOutput("  ✔ Ratellimit-cache rensad.", "green");
        } else {
            $this->coloredOutput("  • Inget att rensa eller kunde inte rensa alla filer.", "yellow");
        }

        $this->coloredOutput("\nKlar.\n", "green");
    }

    private function showHelp(): void
    {
        $this->coloredOutput("Usage:", "green");
        $this->coloredOutput("  cache:clear                Rensar applikations- och ratelimit-cache.", "yellow");
        $this->coloredOutput("Options:", "green");
        $this->coloredOutput("  --help                     Visa denna hjälp.", "yellow");
        echo PHP_EOL;
    }

    private function deleteCacheDirectory(string $dir): bool
    {
        if (!is_dir($dir)) {
            return true;
        }

        $ok = true;
        $items = @scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $ok = $this->deleteCacheDirectory($path) && $ok;
            } else {
                $ok = @unlink($path) && $ok;
            }
        }

        // Låt själva katalogen ligga kvar
        return $ok;
    }
}
