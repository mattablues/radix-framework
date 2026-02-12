<?php

declare(strict_types=1);

namespace Radix\Tests\Support;

use PHPUnit\Framework\TestCase;
use Radix\Support\StringHelper;
use ReflectionClass;

final class StringHelperMutationTest extends TestCase
{
    protected function tearDown(): void
    {
        // Städa statiskt state så tester inte påverkar varandra.
        $ref = new ReflectionClass(StringHelper::class);

        $pCache = $ref->getProperty('irregularCache');
        $pCache->setAccessible(true);
        $pCache->setValue(null, null);

        $pOverride = $ref->getProperty('pluralizationOverride');
        $pOverride->setAccessible(true);
        $pOverride->setValue(null, null);

        parent::tearDown();
    }

    public function testIrregularMapCachingAndNormalizationAreCorrect(): void
    {
        $ref = new ReflectionClass(StringHelper::class);

        $pCache = $ref->getProperty('irregularCache');
        $pCache->setAccessible(true);

        $pOverride = $ref->getProperty('pluralizationOverride');
        $pOverride->setAccessible(true);

        // 1) Först: sätt en cache som ska vinna över allt annat (dödar ReturnRemoval)
        $pCache->setValue(null, ['status' => 'status']);

        // Override som skulle ge TOMT resultat om koden råkar fortsätta förbi cache-returnen.
        $pOverride->setValue(null, ['irregular' => []]);

        self::assertSame('status', StringHelper::pluralize('Status'));
        self::assertSame('status', StringHelper::singularize('Status'));

        // 2) Sedan: nolla cache så vi tvingar rebuild från override (dödar Foreach_/IfNegation/Ternary/LogicalAnd*/UnwrapStrToLower)
        $pCache->setValue(null, null);

        // Override med både giltigt och ogiltigt skräp för att testa filtrering + normalisering
        $pOverride->setValue(null, [
            'irregular' => [
                'Status' => 'status', // ska funka case-insensitivt => key normaliseras till 'status'
                123 => 'NOPE',        // icke-strängnyckel ska ignoreras
                'bad' => 456,         // icke-strängvärde ska ignoreras
            ],
        ]);

        // Om foreach ersätts med foreach([]) eller if (is_string($k)) negieras => mappen blir tom => dessa failar
        self::assertSame('status', StringHelper::pluralize('STATUS'));
        self::assertSame('status', StringHelper::singularize('Status'));
    }
}
