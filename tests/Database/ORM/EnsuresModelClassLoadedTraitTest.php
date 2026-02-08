<?php

declare(strict_types=1);

namespace Radix\Tests\Database\ORM;

use Exception;
use LogicException;
use PHPUnit\Framework\TestCase;
use Radix\Database\ORM\Relationships\Concerns\EnsuresModelClassLoaded;
use RuntimeException;

final class EnsuresModelClassLoadedTraitTest extends TestCase
{
    public function testDoesNotAutoloadWhenClassAlreadyLoadedInFalseMode(): void
    {
        $h = new EnsuresModelClassLoadedHarness();

        $fqcn = 'Radix\\Tests\\Database\\ORM\\AlreadyLoadedDummy';
        if (!class_exists($fqcn, false)) {
            eval('namespace Radix\\Tests\\Database\\ORM; final class AlreadyLoadedDummy {}');
        }

        $cb = static function (string $class): void {
            throw new RuntimeException('Autoloader should not be called. class=' . $class);
        };

        spl_autoload_register($cb);
        try {
            $h->callEnsureModelClassLoaded($fqcn);
            $this->addToAssertionCount(1);
        } finally {
            spl_autoload_unregister($cb);
        }
    }

    public function testDetectsReEntrantAutoloadGuard(): void
    {
        $h = new EnsuresModelClassLoadedHarness();
        $fqcn = 'Dummy\\Models\\__ReentrantGuardDummy__';

        $cb = function (string $class) use ($h, $fqcn): void {
            if ($class !== $fqcn) {
                return;
            }

            // Re-entrant: detta anrop sker medan första ensureModelClassLoaded körs
            $h->callEnsureModelClassLoaded($fqcn);
        };

        spl_autoload_register($cb);
        try {
            $this->expectException(LogicException::class);
            $this->expectExceptionMessage("Re-entrant autoload detected for '{$fqcn}'.");

            $h->callEnsureModelClassLoaded($fqcn);
        } finally {
            spl_autoload_unregister($cb);
        }
    }

    public function testCleansUpLoadingFlagWhenAutoloadFailsSoSecondCallThrowsNotFound(): void
    {
        $h = new EnsuresModelClassLoadedHarness();
        $fqcn = 'Dummy\\Models\\__CleanupFlagDummy__';

        // Autoload gör inget => class_exists($fqcn) förblir false
        $cb = static function (string $class): void {
            // no-op
        };

        spl_autoload_register($cb);
        try {
            // 1) första anropet ska kasta "not found"
            try {
                $h->callEnsureModelClassLoaded($fqcn);
                $this->fail('Expected exception not thrown.');
            } catch (Exception $e) {
                $this->assertSame("Model class '{$fqcn}' not found.", $e->getMessage());
            }

            // 2) andra anropet ska också kasta "not found" (inte re-entrant)
            // Om mutanten tar bort finally/unset så blir det istället re-entrant på call #2.
            $this->expectException(Exception::class);
            $this->expectExceptionMessage("Model class '{$fqcn}' not found.");

            $h->callEnsureModelClassLoaded($fqcn);
        } finally {
            spl_autoload_unregister($cb);
        }
    }
}

final class EnsuresModelClassLoadedHarness
{
    use EnsuresModelClassLoaded;

    public function callEnsureModelClassLoaded(string $fqcn): void
    {
        $this->ensureModelClassLoaded($fqcn);
    }
}
