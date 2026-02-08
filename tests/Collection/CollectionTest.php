<?php

declare(strict_types=1);

namespace Radix\Tests\Collection;

use ErrorException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Radix\Collection\Collection;
use ReflectionMethod;
use stdClass;

class CollectionTest extends TestCase
{
    public function testBasicArrayAccessAndCount(): void
    {
        $c = new Collection([1, 2, 3]);
        $this->assertCount(3, $c);
        $this->assertSame(1, $c[0]);
        $c[] = 4;
        $this->assertSame(4, $c[3]);
        unset($c[1]);
        $this->assertFalse(isset($c[1]));
        $this->assertCount(3, $c);
    }

    public function testGetSetAndRemove(): void
    {
        $c = new Collection(['a' => 1]);
        $this->assertSame(1, $c->get('a'));
        $this->assertNull($c->get('missing'));
        $c->set('b', 2);
        $this->assertSame(2, $c->get('b'));
        $removed = $c->remove('a');
        $this->assertSame(1, $removed);
        $this->assertNull($c->get('a'));
    }

    /**
     * add() ska vara publik och returnera true.
     * Mutanter:
     *  - PublicVisibility (protected)
     *  - TrueValue (return false)
     */
    public function testAddIsPublicAndReturnsTrue(): void
    {
        $c = new Collection([]);
        $result = $c->add('x');

        $this->assertTrue($result);
        $this->assertSame(['x'], $c->toArray());
    }

    /**
     * offsetExists ska:
     *  - returnera true för befintliga int- och string-nycklar
     *  - gå via containsKey(), inte bara kortslutas felaktigt.
     * Dödar flera LogicalNot/LogicalAnd/ReturnRemoval-mutanter på rad 73.
     */
    public function testOffsetExistsForValidIntAndStringKeys(): void
    {
        $c = new Collection([10, 'b' => 20]);

        // int-nyckel 0 finns
        $this->assertTrue(isset($c[0]));
        // string-nyckel 'b' finns
        $this->assertTrue(isset($c['b']));

        // icke-existerande giltiga nycklar ska ge false
        $this->assertFalse(isset($c[1]));
        $this->assertFalse(isset($c['missing']));
    }

    public function testFirstLastAndFirstWhere(): void
    {
        $c = new Collection([
            ['id' => 1, 'name' => 'Alice'],
            ['id' => 2, 'name' => 'Bob'],
        ]);

        $this->assertSame(['id' => 1, 'name' => 'Alice'], $c->first());
        $this->assertSame(['id' => 2, 'name' => 'Bob'], $c->last());
        $this->assertSame(['id' => 2, 'name' => 'Bob'], $c->firstWhere('name', 'Bob'));
        $this->assertNull($c->firstWhere('name', 'Eve'));
    }

    public function testMapFilterRejectReduce(): void
    {
        $c = new Collection([1, 2, 3, 4]);

        // map: vi vet att kollektionen innehåller ints, så vi kan typa v som int
        $mapped = $c->map(fn(int $v, int|string $k): int => $v * 2);
        $this->assertSame([2, 4, 6, 8], $mapped->toArray());

        // filter: officiell signatur är callable(mixed, int|string): bool,
        // så vi tar mixed, men smalnar av med runtime‑check.
        $filtered = $c->filter(function (mixed $v, int|string $k): bool {
            if (!is_int($v)) {
                $this->fail('Expected int value in Collection::filter() test.');
            }
            return $v % 2 === 0;
        });
        $this->assertSame([1 => 2, 3 => 4], $filtered->toArray());

        $rejected = $c->reject(function (mixed $v, int|string $k): bool {
            if (!is_int($v)) {
                $this->fail('Expected int value in Collection::reject() test.');
            }
            return $v <= 2;
        });
        $this->assertSame([2 => 3, 3 => 4], $rejected->toArray());

        $sum = $c->reduce(function (mixed $acc, mixed $v, int|string $k): int {
            if (!is_int($acc)) {
                $this->fail('Expected int accumulator in Collection::reduce() test.');
            }
            if (!is_int($v)) {
                $this->fail('Expected int value in Collection::reduce() test.');
            }
            return $acc + $v;
        }, 0);
        $this->assertSame(10, $sum);
    }

    public function testOnlyExceptUniqueValuesKeys(): void
    {
        $c = new Collection(['a' => 1, 'b' => 1, 'c' => 2]);

        $only = $c->only(['a', 'c']);
        $this->assertSame(['a' => 1, 'c' => 2], $only->toArray());

        $except = $c->except(['b']);
        $this->assertSame(['a' => 1, 'c' => 2], $except->toArray());

        $unique = $c->unique();
        $this->assertSame(['a' => 1, 'c' => 2], $unique->toArray());

        $vals = $c->values();
        $this->assertSame([1,1,2], $vals->toArray());

        $keys = $c->keys();
        $this->assertSame(['a','b','c'], $keys->toArray());
    }

    public function testPluckOnArraysAndObjects(): void
    {
        $obj = (object) ['id' => 2, 'name' => 'Bob'];
        $c = new Collection([
            ['id' => 1, 'name' => 'Alice'],
            $obj,
        ]);

        $names = $c->pluck('name')->values()->toArray();
        $this->assertSame(['Alice', 'Bob'], $names);

        $namesById = $c->pluck('name', 'id')->toArray();
        $this->assertSame([1 => 'Alice', 2 => 'Bob'], $namesById);
    }

    /**
     * pluck() ska inte försöka läsa saknade objektproperties (dödar LogicalAnd-mutanter).
     * Muterad kod skulle göra property-read även när isset(...) är false,
     * vilket ger notice/varning och gör testet "riskfyllt".
     */
    public function testPluckSkipsMissingObjectPropertyWithoutNotice(): void
    {
        $objWithName = (object) ['id' => 1, 'name' => 'Alice'];
        $objWithoutName = (object) ['id' => 2]; // saknar 'name'

        $c = new Collection([$objWithName, $objWithoutName]);

        $plucked = $c->pluck('name')->toArray();

        // andra elementet saknar 'name' -> ska bli null men utan notice
        $this->assertSame(
            [0 => 'Alice', 1 => null],
            $plucked
        );
    }

    public function testPluckKeyBySkipsMissingObjectPropertyWithoutNotice(): void
    {
        $objWithId = (object) ['id' => 1, 'name' => 'Alice'];
        $objWithoutId = (object) ['name' => 'Bob']; // saknar 'id'

        $c = new Collection([$objWithId, $objWithoutId]);

        $plucked = $c->pluck('name', 'id')->toArray();

        // Det viktiga här är att anropet inte genererar notice/varning
        // när andra objektet saknar 'id'. Vi nöjer oss med att resultatet
        // innehåller 'Bob' som värde.
        $this->assertSame(['Bob'], array_values($plucked));
    }

    public function testClearAndIsEmpty(): void
    {
        $c = new Collection([1]);
        $this->assertFalse($c->isEmpty());
        $c->clear();
        $this->assertTrue($c->isEmpty());
    }

    public function testContainsKeyAndArrayAccessRejectsInvalidKeyTypes(): void
    {
        $c = new Collection(['a' => 1]);
        $this->assertFalse($c->containsKey(false));
        /** @phpstan-ignore-next-line  intentional invalid key type for runtime behaviour */
        $this->assertFalse(isset($c[false]));
        /** @phpstan-ignore-next-line  intentional invalid key type for runtime behaviour */
        $this->assertNull($c[false]);
        /** @phpstan-ignore-next-line  intentional invalid key type for runtime behaviour */
        unset($c[false]); // ska inte kasta
        $this->assertSame(['a' => 1], $c->toArray());
    }

    public function testLastHandlesFalseAtEndCorrectly(): void
    {
        $c = new Collection([1, false]);
        $this->assertFalse($c->last('default'));
        $empty = new Collection([]);
        $this->assertSame('default', $empty->last('default'));
    }

    public function testUniqueStrictVsNonStrict(): void
    {
        $c = new Collection([1, '1', 1]);

        $strict = $c->unique(null, true)->toArray();
        $loose  = $c->unique()->toArray();

        $this->assertSame([0 => 1, 1 => '1'], $strict);
        $this->assertSame([0 => 1], $loose);
    }

    public function testUniqueWithObjectsAndArrays(): void
    {
        $obj1 = (object) ['a' => 1];
        $obj2 = (object) ['a' => 1];
        $arr1 = ['a' => 1];
        $arr2 = ['a' => 1];

        $c = new Collection([$obj1, $obj2, $arr1, $arr2]);
        $unique = $c->unique()->toArray();

        $this->assertCount(2, $unique, 'Objekt och arr med samma innehåll ska dedupliceras.');
    }

    public function testPluckWithNonScalarKeyByFallsBackToIndex(): void
    {
        $c = new Collection([
            ['id' => 1, 'data' => ['x' => 1]],
            ['id' => 2, 'data' => ['x' => 2]],
        ]);

        $plucked = $c->pluck('data', 'data')->toArray();
        // eftersom keyBy 'data' inte är int|string -> ska använda originalindex
        $this->assertSame(
            [0 => ['x' => 1], 1 => ['x' => 2]],
            $plucked
        );
    }

    public function testOnlyAndExceptIgnoreNonIntStringKeys(): void
    {
        $c = new Collection(['a' => 1, 'b' => 2, 'c' => 3]);
        /** @phpstan-ignore-next-line  testar att ogiltiga nycklar ignoreras vid runtime */
        $only = $c->only(['a', false])->toArray();
        $this->assertSame(['a' => 1], $only);
        /** @phpstan-ignore-next-line  testar att ogiltiga nycklar ignoreras vid runtime */
        $except = $c->except(['b', []])->toArray();
        $this->assertSame(['a' => 1, 'c' => 3], $except);
    }

    public function testOffsetAndContainsKeyRejectNonIntStringKeys(): void
    {
        $c = new Collection(['a' => 1]);

        // containsKey
        $this->assertFalse($c->containsKey(false));
        $this->assertFalse($c->containsKey([]));

        /** @phpstan-ignore-next-line  intentional invalid key type for runtime behaviour */
        $this->assertFalse(isset($c[false]));
        /** @phpstan-ignore-next-line  intentional invalid key type for runtime behaviour */
        $this->assertFalse(isset($c[[]]));

        // offsetGet – ska ge null, inte exception
        /** @phpstan-ignore-next-line  intentional invalid key type for runtime behaviour */
        $this->assertNull($c[false]);

        // offsetUnset – ska bara ignorera
        /** @phpstan-ignore-next-line  intentional invalid key type for runtime behaviour */
        unset($c[false]);
        $this->assertSame(['a' => 1], $c->toArray());
    }

    public function testLastHandlesFalseCorrectly(): void
    {
        $c = new Collection([1, false]);
        $this->assertFalse($c->last('default'), 'last ska returnera false om sista elementet är false');

        $empty = new Collection([]);
        $this->assertSame('default', $empty->last('default'), 'last på tom collection ska ge default');
    }

    /**
     * Extra explicit test för last() på tom samling
     * för att säkert döda ReturnRemoval-mutanter på rad 157.
     */
    public function testLastOnEmptyCollectionAlwaysReturnsDefault(): void
    {
        $c = new Collection([]);
        $this->assertSame('fallback', $c->last('fallback'));
    }

    public function testFirstWhereForArraysAndObjects(): void
    {
        $obj1 = (object) ['id' => 2, 'name' => 'Bob'];
        $obj2 = (object) ['id' => 3, 'name' => 'Carol'];
        $objWithoutId = (object) ['name' => 'NoId'];

        $c = new Collection([
            ['id' => 1, 'name' => 'Alice'],
            $obj1,
            $obj2,
            $objWithoutId,
        ]);

        $resArr = $c->firstWhere('id', 1);
        $this->assertSame(['id' => 1, 'name' => 'Alice'], $resArr);

        $resObj = $c->firstWhere('id', 3);
        $this->assertSame($obj2, $resObj);

        // Objekt utan 'id' får inte matcha
        $this->assertNull($c->firstWhere('id', 99));
    }

    public function testUniqueStrictVsNonStrictAndComplexValues(): void
    {
        $obj1 = (object) ['a' => 1];
        $obj2 = (object) ['a' => 1];
        $arr1 = ['a' => 1];
        $arr2 = ['a' => 1];

        $c = new Collection([1, '1', 1, $obj1, $obj2, $arr1, $arr2]);

        $strict = $c->unique(null, true)->toArray();
        $loose  = $c->unique()->toArray();

        // strict: 1 och '1' räknas som olika
        $this->assertSame([0 => 1, 1 => '1', 3 => $obj1, 5 => $arr1], $strict);

        // loose: 1 och '1' slås ihop
        $this->assertSame([0 => 1, 3 => $obj1, 5 => $arr1], $loose);
    }

    /**
     * unique() strikt läge ska skilja mellan olika scalar-värden
     * med samma typ (dödar ArrayItemRemoval-mutanter i strict-delen).
     */
    public function testUniqueStrictKeepsDifferentScalarValues(): void
    {
        $c = new Collection([1, 2, 2]);

        $strict = $c->unique(null, true)->toArray();

        // både 1 och 2 ska finnas kvar, 2 ska dedupliceras
        $this->assertSame([0 => 1, 1 => 2], $strict);
    }

    public function testOnlyAndExceptIgnoreInvalidKeys(): void
    {
        $c = new Collection(['a' => 1, 'b' => 2, 'c' => 3]);
        /** @phpstan-ignore-next-line  testar ogiltig nyckeltyp i only() */
        $only = $c->only(['a', false, 123])->toArray();
        // false är ogiltig nyckeltyp, 123 finns inte som nyckel → bara 'a' ska komma med
        $this->assertSame(['a' => 1], $only);

        /** @phpstan-ignore-next-line  testar ogiltig nyckeltyp i except() */
        $except = $c->except(['b', []])->toArray();
        $this->assertSame(['a' => 1, 'c' => 3], $except);
    }

    /**
     * only() ska fortsätta iterera efter ogiltig nyckel (dödar LogicalNot/LogicalAnd/Continue_-mutanter).
     */
    public function testOnlyContinuesAfterInvalidKey(): void
    {
        $c = new Collection(['a' => 1, 'b' => 2, 'c' => 3]);

        /** @phpstan-ignore-next-line  medvetet ogiltig nyckeltyp för att testa runtime-beteende */
        $only = $c->only(['a', false, 'c'])->toArray();

        // false ska ignoreras, men 'c' får INTE tappas bort
        $this->assertSame(['a' => 1, 'c' => 3], $only);
    }

    /**
     * except() ska också fortsätta efter ogiltig nyckel (dödar Continue_-mutant).
     */
    public function testExceptContinuesAfterInvalidKey(): void
    {
        $c = new Collection(['a' => 1, 'b' => 2, 'c' => 3]);

        // ogiltig först, sedan giltig
        /** @phpstan-ignore-next-line  medvetet ogiltig nyckeltyp för att testa runtime-beteende */
        $except = $c->except([false, 'b'])->toArray();

        // 'b' ska tas bort trots att en ogiltig nyckel kom före
        $this->assertSame(['a' => 1, 'c' => 3], $except);
    }

    public function testObjectSignatureBuildsExpectedShapeAndKeyFormat(): void
    {
        $method = new ReflectionMethod(Collection::class, 'objectSignature');
        $method->setAccessible(true);

        $obj = new class {
            private int $b = 2;
            private int $a = 1;

            /** @return array{a:int,b:int} */
            public function expose(): array
            {
                return ['a' => $this->a, 'b' => $this->b];
            }
        };

        // Gör en faktisk läsning så PHPStan inte flaggar properties som "onlyWritten".
        $this->assertSame(['a' => 1, 'b' => 2], $obj->expose());

        /** @var array{class:string, props: array<string,mixed>} $sig */
        $sig = $method->invoke(null, $obj);

        $this->assertArrayHasKey('class', $sig);
        $this->assertArrayHasKey('props', $sig);

        $this->assertSame(get_class($obj), $sig['class'], 'Signaturen ska innehålla objektets klassnamn.');

        // Nycklarna ska innehålla "DeclaringClass::propName"
        $expectedAKey = get_class($obj) . '::a';
        $expectedBKey = get_class($obj) . '::b';

        $this->assertArrayHasKey($expectedAKey, $sig['props']);
        $this->assertArrayHasKey($expectedBKey, $sig['props']);

        $this->assertSame(1, $sig['props'][$expectedAKey]);
        $this->assertSame(2, $sig['props'][$expectedBKey]);
    }

    public function testObjectSignatureSortsPropsByKeySoOrderIsDeterministic(): void
    {
        $method = new ReflectionMethod(Collection::class, 'objectSignature');
        $method->setAccessible(true);

        // Medvetet deklarationsordning: b sedan a.
        // Utan ksort() skulle keys typiskt komma i deklarationsordning.
        $obj = new class {
            private int $b = 2;
            private int $a = 1;

            /** @return array{a:int,b:int} */
            public function expose(): array
            {
                return ['a' => $this->a, 'b' => $this->b];
            }
        };

        // Gör en faktisk läsning så PHPStan inte flaggar properties som "onlyWritten".
        $this->assertSame(['a' => 1, 'b' => 2], $obj->expose());

        /** @var array{props: array<string,mixed>} $sig */
        $sig = $method->invoke(null, $obj);

        $keys = array_keys($sig['props']);

        $this->assertSame(
            [get_class($obj) . '::a', get_class($obj) . '::b'],
            $keys,
            'Props-keys ska vara sorterade (ksort) för stabil serialisering/hash.'
        );
    }

    public function testObjectSignatureMarksUninitializedTypedProperty(): void
    {
        $method = new ReflectionMethod(Collection::class, 'objectSignature');
        $method->setAccessible(true);

        $obj = new class {
            /** @phpstan-ignore-next-line  oinitierad typed property används av reflection i objectSignature() */
            private int $a; // oinitierad typed property
            private int $b = 123;

            public function exposeB(): int
            {
                return $this->b;
            }
        };

        // Läs en property “på riktigt” så PHPStan inte flaggar $b som onlyWritten.
        $this->assertSame(123, $obj->exposeB());

        /** @var array{props: array<string,mixed>} $sig */
        $sig = $method->invoke(null, $obj);

        $aKey = get_class($obj) . '::a';
        $bKey = get_class($obj) . '::b';

        $this->assertSame('__UNINITIALIZED__', $sig['props'][$aKey]);
        $this->assertSame(123, $sig['props'][$bKey]);
    }

    public function testOffsetExistsReturnsFalseForInvalidOffsetTypes(): void
    {
        $c = new Collection(['a' => 1, 2 => 'x']);

        $this->assertTrue(isset($c['a']));
        $this->assertTrue(isset($c[2]));

        // ogiltig offset-typ (varken int eller string) => false
        $obj = new stdClass();
        /** @phpstan-ignore-next-line  medvetet fel typ för att testa runtime-beteende */
        $this->assertFalse($c->offsetExists($obj));
    }

    public function testUniqueNonStrictHandlesResourceKeysWithoutSerializingAndDeduplicates(): void
    {
        $r = tmpfile();
        if ($r === false) {
            $this->fail('tmpfile() misslyckades i testet.');
        }

        $c = new Collection(['a' => 'A', 'b' => 'B']);

        // Om implementationen försöker serialize($resource) kan det ge warnings/notices.
        set_error_handler(static function (int $errno, string $errstr): bool {
            throw new ErrorException($errstr, 0, $errno);
        });

        try {
            $out = $c->unique(
                static fn(mixed $_v, string $_k): mixed => $r,
                strict: false
            );
        } finally {
            restore_error_handler();
            fclose($r);
        }

        // Båda får samma resource-key => bara första ska vara kvar
        $this->assertSame(['a' => 'A'], $out->toArray());
    }

    public function testUniqueNonStrictWithScalarCallbackDoesNotCallResourceIdAndDoesNotThrow(): void
    {
        $c = new Collection(['a' => 'A', 'b' => 'B']);

        // Om mutanten (is_resource -> !is_resource) finns kvar kommer detta försöka köra
        // get_resource_id('x') och kasta TypeError.
        $out = $c->unique(
            static fn(mixed $_v, string $_k): string => 'x',
            strict: false
        );

        // Båda får samma nyckel => bara första ska vara kvar
        $this->assertSame(['a' => 'A'], $out->toArray());
    }

    public function testOffsetExistsDoesNotCallContainsKeyForInvalidOffsetTypes(): void
    {
        $c = new class (['a' => 1]) extends Collection {
            public int $containsKeyCalls = 0;

            public function containsKey(mixed $key): bool
            {
                $this->containsKeyCalls++;
                return parent::containsKey($key);
            }
        };

        $obj = new stdClass();

        /** @phpstan-ignore-next-line  medvetet fel typ för att testa runtime-beteende */
        $this->assertFalse($c->offsetExists($obj));
        $this->assertSame(
            0,
            $c->containsKeyCalls,
            'offsetExists() ska inte anropa containsKey() för ogiltiga offset-typer.'
        );
    }

    public function testUniqueNonStrictDoesNotTreatAllResourcesAsSameKey(): void
    {
        $r1 = tmpfile();
        $r2 = tmpfile();

        if ($r1 === false || $r2 === false) {
            $this->fail('tmpfile() misslyckades i testet.');
        }

        $c = new Collection(['one' => 'A', 'two' => 'B']);

        try {
            $out = $c->unique(
                static fn(mixed $_v, string $k): mixed => $k === 'one' ? $r1 : $r2,
                strict: false
            );
        } finally {
            fclose($r1);
            fclose($r2);
        }

        // Korrekt: olika resources ska ge olika hash => båda ska vara kvar.
        // Mutant (ConcatOperandRemoval -> "resource:" konstant) skulle deduplicera bort en.
        $this->assertSame(['one' => 'A', 'two' => 'B'], $out->toArray());
    }

    public function testUniqueNonStrictResourcePrefixMustBeBeforeIdToAvoidCollision(): void
    {
        $r = tmpfile();
        if ($r === false) {
            $this->fail('tmpfile() misslyckades i testet.');
        }

        $id = get_resource_id($r);
        $scalarThatMatchesSwappedFormat = (string) $id . 'resource:';

        $c = new Collection(['res' => 'A', 'scalar' => 'B']);

        try {
            $out = $c->unique(
                static function (mixed $_v, string $k) use ($r, $scalarThatMatchesSwappedFormat): mixed {
                    return $k === 'res' ? $r : $scalarThatMatchesSwappedFormat;
                },
                strict: false
            );
        } finally {
            fclose($r);
        }

        // Korrekt: resource-hash ska vara "resource:<id>", inte "<id>resource:".
        // Alltså ska den INTE krocka med scalaren "<id>resource:" => båda ska vara kvar.
        // Mutant (Concat -> id . 'resource:') skulle göra krock => en försvinner.
        $this->assertSame(['res' => 'A', 'scalar' => 'B'], $out->toArray());
    }

    public function testUniqueNonStrictResourceHashIsNamespacedSoItDoesNotCollideWithScalarIdString(): void
    {
        $r = tmpfile();
        if ($r === false) {
            $this->fail('tmpfile() misslyckades i testet.');
        }

        $idString = (string) get_resource_id($r);

        $c = new Collection(['res' => 'A', 'scalar' => 'B']);

        try {
            $out = $c->unique(
                static function (mixed $_v, string $k) use ($r, $idString): mixed {
                    return $k === 'res' ? $r : $idString;
                },
                strict: false
            );
        } finally {
            fclose($r);
        }

        // Korrekt: resource-hash ("resource:<id>") och scalar-hash ("<id>") ska INTE krocka
        $this->assertSame(
            ['res' => 'A', 'scalar' => 'B'],
            $out->toArray(),
            'Resource-hash i non-strict måste vara namespaced/prefixad för att inte krocka med vanliga strängar.'
        );
    }

    public function testOnlyIgnoresFalseKeyEvenIfZeroKeyExists(): void
    {
        $c = new Collection([0 => 'zero', 'a' => 'A']);

        /** @phpstan-ignore-next-line  intentional invalid key type for runtime behaviour */
        $out = $c->only([false])->toArray();

        $this->assertSame(
            [],
            $out,
            'only() ska ignorera false (ogiltig nyckeltyp) och får inte råka inkludera key 0.'
        );
    }

    public function testUniqueNonStrictTreatsNullAndEmptyStringAsSameKey(): void
    {
        $c = new Collection(['a' => 'A', 'b' => 'B']);

        $out = $c->unique(
            static fn(mixed $_v, string $k): mixed => $k === 'a' ? null : '',
            strict: false
        );

        // null -> (string)null === '' och '' -> '' => samma hash => bara första ska vara kvar
        $this->assertSame(['a' => 'A'], $out->toArray());
    }

    public function testUniqueSeenMapMustMarkSeenAsTrueSoDuplicatesAreRemoved(): void
    {
        $c = new Collection([1, 1]);

        $out = $c->unique();

        $this->assertSame([0 => 1], $out->toArray());
    }

    public function testLastReturnsDefaultWhenEmpty(): void
    {
        $c = new Collection([]);
        $this->assertSame('DEF', $c->last('DEF'));
    }

    public function testFilterRejectsNonZeroModeAndDefaultModeIsZero(): void
    {
        $c = new Collection([1, 2, 3]);

        // Default mode=0 ska fungera (om mutanten sätter default=1/-1 -> detta kastar och testet dör)
        $out = $c->filter(static fn(mixed $v, int|string $_k): bool => $v !== 2);
        $this->assertSame([0 => 1, 2 => 3], $out->toArray());

        // mode != 0 ska kasta (och det ska vara stabilt)
        $this->expectException(InvalidArgumentException::class);
        $c->filter(static fn(mixed $_v, int|string $_k): bool => true, 1);
    }

    public function testFilterCastsCallbackReturnToBoolToAvoidTypeError(): void
    {
        $c = new Collection([1, 2, 3]);

        // callback returnerar int 0 (inte bool) => utan (bool)-cast skulle PHP kasta TypeError
        /** @phpstan-ignore-next-line  medvetet fel returtyp för att testa att filter() castar till bool */
        $out = $c->filter(static fn(mixed $_v, int|string $_k): int => 0);

        $this->assertSame([], $out->toArray());
    }

    public function testUniqueNonStrictAllowsNullKeyAndDoesNotThrowTypeError(): void
    {
        $c = new Collection(['a' => 'x', 'b' => 'y']);

        $out = $c->unique(static fn(): null => null, strict: false);

        // båda får samma hash (''), så bara första ska vara kvar
        $this->assertSame(['a' => 'x'], $out->toArray());
    }

    public function testUniqueNonStrictFallsBackToSerializeForArrayKeyWithoutWarnings(): void
    {
        $c = new Collection([
            'first' => 'A',
            'second' => 'B',
        ]);

        // Om mutanten försöker (string)$key när $key är array får vi en warning ("Array to string conversion").
        set_error_handler(static function (int $errno, string $errstr): bool {
            throw new ErrorException($errstr, 0, $errno);
        });

        try {
            $out = $c->unique(
                static fn(mixed $v, string $k): array => ['k' => $k], // array => ska serialize-hashas
                strict: false
            );
        } finally {
            restore_error_handler();
        }

        // olika array-nycklar => båda ska vara kvar
        $this->assertSame(['first' => 'A', 'second' => 'B'], $out->toArray());
    }

    public function testUniqueUsesSeenMapSoDuplicatesAreRemoved(): void
    {
        $c = new Collection([1, 1, 2, 2, 3]);

        $out = $c->unique();

        // bevisar att seen-mapen funkar; om $seen[$hash] muteras till false och vi använder isset,
        // kommer dubletter slinka igenom och testet failar.
        $this->assertSame([0 => 1, 2 => 2, 4 => 3], $out->toArray());
    }

    public function testUniqueStrictUsesSerializeForObjectsSoPrivateStateAffectsUniqueness(): void
    {
        $a = new class (1) {
            public function __construct(private int $x) {}

            public function exposeX(): int
            {
                return $this->x;
            }
        };
        $b = new class (2) {
            public function __construct(private int $x) {}

            public function exposeX(): int
            {
                return $this->x;
            }
        };

        // Läs properties “på riktigt” så PHPStan inte flaggar dem som onlyWritten.
        $this->assertSame(1, $a->exposeX());
        $this->assertSame(2, $b->exposeX());

        $c = new Collection(['a' => 'A', 'b' => 'B']);

        $out = $c->unique(
            static fn(mixed $_v, string $k): object => $k === 'a' ? $a : $b,
            strict: true
        );

        // Original: object => serialize-hash => olika => båda kvar.
        // Mutant (&&): object-grenen körs aldrig => strict-branch => json_encode på objekt med bara privata props blir "{}", dvs samma => en tas bort.
        $this->assertSame(['a' => 'A', 'b' => 'B'], $out->toArray());
    }

    public function testUniqueNonStrictCastsFloatKeysToStringSoTheyDoNotCollide(): void
    {
        $c = new Collection([1.2, 1.5]);

        $out = $c->unique(null, strict: false);

        // Original: scalar => (string) float => "1.2" och "1.5" => olika => båda kvar
        // Mutant (CastString): $hash = $key (float) => PHP array key castar float till int (1) => krock => en försvinner
        $this->assertSame([0 => 1.2, 1 => 1.5], $out->toArray());
    }

    public function testUniqueRemovesDuplicatesInDefaultMode(): void
    {
        $c = new Collection([1, 1, 2, 2, 3]);

        $out = $c->unique();

        // Extra tydlig assert för seen-flaggan:
        // Om mutanten sätter $seen[$hash] = false och vi använder isset, så "glöms" den och dubletter slinker igenom.
        $this->assertCount(3, $out->toArray());
        $this->assertSame([0 => 1, 2 => 2, 4 => 3], $out->toArray());
    }

    public function testUniqueNonStrictDoesNotCastArrayToStringAndTriggerWarning(): void
    {
        $c = new Collection(['first' => 'A', 'second' => 'B']);

        set_error_handler(static function (int $errno, string $errstr): bool {
            throw new ErrorException($errstr, 0, $errno);
        });

        try {
            $out = $c->unique(
                static fn(mixed $_v, string $k): array => ['k' => $k],
                strict: false
            );
        } finally {
            restore_error_handler();
        }

        // Original: array => serialize-branch eller serialize-fallback => inga warnings.
        // Mutant (7/8): kan hamna i (string)$key på array => "Array to string conversion" => ErrorException => testet failar.
        $this->assertSame(['first' => 'A', 'second' => 'B'], $out->toArray());
    }
}
