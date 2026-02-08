<?php

declare(strict_types=1);

namespace Radix\Tests\Config;

use PHPUnit\Framework\TestCase;
use Radix\Config\Config;

final class ConfigTest extends TestCase
{
    public function testGetReturnsDefaultWhenIntermediateKeyIsNotArray(): void
    {
        // Första mutationen: || -> &&
        // config['database'] är en sträng, inte array.
        $config = new Config([
            'database' => 'sqlite',
        ]);

        // Original: upptäcker att $value inte är array → returnerar $default.
        // Mutant: kräver både !is_array OCH !array_key_exists → försöker läsa ['host'] på en sträng.
        $value = $config->get('database.host', 'default-host');

        $this->assertSame('default-host', $value);
    }

    public function testGetExecutesClosureOnceAndCachesResult(): void
    {
        $calls = 0;

        $config = new Config([
            'lazy' => function () use (&$calls) {
                $calls++;
                return 'computed';
            },
        ]);

        $first  = $config->get('lazy');
        $second = $config->get('lazy');

        // Original: kör Closuren en gång och cachar resultatet.
        // Mutationer 3, 5, 6 gör att Closuren aldrig körs.
        $this->assertSame('computed', $first);
        $this->assertSame('computed', $second);
        $this->assertSame(1, $calls, 'Closure ska exekveras exakt en gång');
    }

    public function testGetDoesNotInvokeInvokableObjectValues(): void
    {
        $invokable = new class {
            public int $calls = 0;

            public function __invoke(): string
            {
                $this->calls++;
                return 'invoked';
            }
        };

        $config = new Config([
            'lazy_object' => $invokable,
        ]);

        // Original: vill bara exekvera om värdet är en Closure.
        // Invokable objekt (icke-Closure) ska därför INTE anropas.
        // Mutation 3 och 4 börjar exekvera icke-Closure-objekt.
        $value = $config->get('lazy_object');

        $this->assertSame($invokable, $value, 'Värdet ska vara själva objektet, inte returvärdet från __invoke()');
        $this->assertSame(0, $invokable->calls, '__invoke() får inte köras för icke-Closure-objekt');
    }
}
