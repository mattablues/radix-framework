<?php

declare(strict_types=1);

namespace Radix\Tests\Database\ORM;

use Exception;
use PHPUnit\Framework\TestCase;
use Radix\Database\ORM\Relationships\Concerns\EnsuresModelClassLoaded;

final class EnsuresModelClassLoadedVisibilityTest extends TestCase
{
    public function testMethodMustBeProtectedSoChildCanCallIt(): void
    {
        $child = new EnsuresModelClassLoadedChild();

        $missing = 'Dummy\\Models\\__VisibilityDummyMissing__';

        // Med protected kastar vi "not found" (från traiten).
        // Om mutanten gör metoden private får vi istället Error -> test failar -> mutanten dör.
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Model class '{$missing}' not found.");

        $child->childCallsEnsure($missing);
    }
}

class EnsuresModelClassLoadedBase
{
    use EnsuresModelClassLoaded;
}

final class EnsuresModelClassLoadedChild extends EnsuresModelClassLoadedBase
{
    public function childCallsEnsure(string $fqcn): void
    {
        $this->ensureModelClassLoaded($fqcn);
    }
}
