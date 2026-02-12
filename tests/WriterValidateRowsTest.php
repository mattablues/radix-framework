<?php

declare(strict_types=1);

namespace Radix\Tests\File;

use PHPUnit\Framework\TestCase;
use Radix\File\Writer;
use ReflectionMethod;
use RuntimeException;

final class WriterValidateRowsTest extends TestCase
{
    public function testDefaultsAreAppliedWhenKeyMissing(): void
    {
        $rows = [
            ['a' => 'x'],
        ];

        $out = Writer::validateRows($rows, [
            'defaults' => ['b' => 'DEF'],
        ]);

        self::assertSame('DEF', $out[0]['b']);
    }

    public function testIntTypeRejectsNonStringNonIntWithoutTypeError(): void
    {
        $schema = [
            'required' => ['id'],
            'types' => ['id' => 'int'],
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Fält id måste vara int');

        // Korrekt kod: ska kasta RuntimeException.
        // Mutant (||) försöker isIntLikeString() på array => TypeError => testet dödar mutanten.
        Writer::validateRows([['id' => ['nope']]], $schema, onError: 'throw');
    }

    public function testDefaultsAreAppliedWhenValueIsNull(): void
    {
        $rows = [
            ['a' => null],
        ];

        $out = Writer::validateRows($rows, [
            'defaults' => ['a' => 'DEF'],
        ]);

        self::assertSame('DEF', $out[0]['a']);
    }

    public function testDefaultsAreAppliedWhenValueIsEmptyString(): void
    {
        $rows = [
            ['a' => ''],
        ];

        $out = Writer::validateRows($rows, [
            'defaults' => ['a' => 'DEF'],
        ]);

        self::assertSame('DEF', $out[0]['a']);
    }

    public function testDefaultsAreNotAppliedForZeroLikeValues(): void
    {
        $rows = [
            ['a' => 0],
            ['a' => '0'],
        ];

        $out = Writer::validateRows($rows, [
            'defaults' => ['a' => 'DEF'],
        ]);

        self::assertSame(0, $out[0]['a']);
        self::assertSame('0', $out[1]['a']);
    }

    public function testTrimMustBeBool(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Schema-fältet 'trim' måste vara boolean-like (0/1/true/false)");

        Writer::validateRows([['a' => ' x ']], [
            'trim' => 'yes',
        ]);
    }

    public function testTrimTrueTrimsStrings(): void
    {
        $out = Writer::validateRows([['a' => ' x ']], [
            'trim' => true,
        ]);

        self::assertSame('x', $out[0]['a']);
    }

    public function testBoolTypeAcceptsIntegerOneAndZeroAndRejectsTwo(): void
    {
        $schema = [
            'required' => ['active'],
            'types' => ['active' => 'bool'],
        ];

        $out1 = Writer::validateRows([['active' => 1]], $schema, onError: 'throw');
        self::assertSame([['active' => true]], $out1);

        $out0 = Writer::validateRows([['active' => 0]], $schema, onError: 'throw');
        self::assertSame([['active' => false]], $out0);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Fält active måste vara bool');

        // Dödar Identical-mutanten som annars tenderar att acceptera "allt möjligt"
        // och dödar IncrementInteger-mutanten (1 -> 2) genom att kräva att 2 INTE accepteras.
        Writer::validateRows([['active' => 2]], $schema, onError: 'throw');
    }

    public function testBoolTypeAcceptsUppercaseTrueAndYes(): void
    {
        $schema = [
            'required' => ['active'],
            'types' => ['active' => 'bool'],
        ];

        $outTrue = Writer::validateRows([['active' => 'TRUE']], $schema, onError: 'throw');
        self::assertSame([['active' => true]], $outTrue);

        $outYes = Writer::validateRows([['active' => 'YES']], $schema, onError: 'throw');
        self::assertSame([['active' => true]], $outYes);

        // Detta dödar UnwrapStrToLower-mutanten (om strtolower tas bort matchar inte 'TRUE'/'YES').
    }

    public function testBoolTypeAcceptsStringOneAndZero(): void
    {
        $schema = [
            'required' => ['active'],
            'types' => ['active' => 'bool'],
        ];

        $out1 = Writer::validateRows([['active' => '1']], $schema, onError: 'throw');
        self::assertSame([['active' => true]], $out1);

        $out0 = Writer::validateRows([['active' => '0']], $schema, onError: 'throw');
        self::assertSame([['active' => false]], $out0);
    }

    public function testOnErrorSkipBreakStopsCheckingFurtherRequiredKeys(): void
    {
        $rows = [
            [
                '' => '',          // den här skulle trigga required-fail OM den utvärderas
                'present' => 'x',  // saknar 'missing' => ska skippas
            ],
        ];

        $schema = [
            'required' => ['missing', ''],
        ];

        // Korrekt: miss på 'missing' => skipRow=true => break => raden skippas
        // Mutant (break->continue): fortsätter och ser även '' => fortsatt skip, men vi verifierar beteendet via output.
        $out = Writer::validateRows($rows, $schema, onError: 'skip');

        self::assertSame([], $out);
    }

    public function testCastMixedToStringCastsIntFloatAndBool(): void
    {
        $rm = new ReflectionMethod(Writer::class, 'castMixedToString');
        $rm->setAccessible(true);

        self::assertSame('123', $rm->invoke(null, 123));
        self::assertSame('1.5', $rm->invoke(null, 1.5));

        // PHP casting: true => "1", false => ""
        self::assertSame('1', $rm->invoke(null, true));
        self::assertSame('', $rm->invoke(null, false));
    }

    public function testOnErrorSkipBreakStopsCheckingFurtherRequiredKeysWithoutInvalidSchema(): void
    {
        $rows = [
            ['present' => 'x'], // saknar 'missing' => ska skippas
        ];

        $schema = [
            // Enbart giltiga strängnycklar (inga null/trasiga schema)
            'required' => ['missing', 'also_missing_but_must_not_be_checked'],
        ];

        $checked = [];
        $hook = static function (string $key) use (&$checked): void {
            $checked[] = $key;
        };

        $out = Writer::validateRows($rows, $schema, onError: 'skip', onRequiredChecked: $hook);

        self::assertSame([], $out);

        // Korrekt: vi ska bara hinna checka första required innan break.
        // Mutant (break->continue): skulle checka båda och då blir listan längre => testet dödar mutanten.
        self::assertSame(['missing'], $checked);
    }

    public function testOnErrorSkipBreaksOutOfRequiredLoopSoInvalidLaterRequiredKeyIsNotEvaluated(): void
    {
        $rows = [
            ['present' => 'x'], // saknar 'missing' => ska skippas
        ];

        $schema = [
            // Viktigt: null är ogiltigt som array_key_exists()-nyckel och ska aldrig nås i skip-läge
            // om första required redan saknas.
            'required' => ['missing', null],
        ];

        $out = Writer::validateRows($rows, $schema, onError: 'skip');

        self::assertSame([], $out);
    }

    public function testCastMixedToStringCastsInfFloatSoItDoesNotBecomeEmptyString(): void
    {
        $rm = new ReflectionMethod(Writer::class, 'castMixedToString');
        $rm->setAccessible(true);

        // KRITISKT:
        // (string) INF === 'INF'
        // json_encode(INF) => false (utan specialflaggor) och fallbacken skulle bli ''.
        // Detta dödar LogicalOr-mutanter som gör att float inte går via float-branch.
        self::assertSame('INF', $rm->invoke(null, INF));
    }

    public function testCastMixedToStringSerializesArraysToJsonNotLiteralArrayString(): void
    {
        $rm = new ReflectionMethod(Writer::class, 'castMixedToString');
        $rm->setAccessible(true);

        // Korrekt: arrays ska gå via json_encode => '{"x":1}'
        // Mutant (!is_int || !is_float || !is_bool) gör att array hamnar i "(string)$value" => "Array"
        self::assertSame('{"x":1}', $rm->invoke(null, ['x' => 1]));
    }

    public function testNormalizeBoolLikeTrimsStringSoWhitespaceTrueIsAccepted(): void
    {
        // Om trim() tas bort i normalizeBoolLike()
        // blir strtolower("  TRUE  ") => "  true  " och matchar inte => exception.
        $out = Writer::validateRows([['a' => ' x ']], [
            'trim' => '  TRUE  ',
        ]);

        self::assertSame('x', $out[0]['a']);
    }

    public function testRequiredIsTreatedAsListSoNonZeroIndexesDoNotBreakValidation(): void
    {
        $rows = [
            ['a' => 'x', 'b' => 'y'],
        ];

        // required med icke-0-baserade indexes (detta är fortfarande "rimlig" input i PHP)
        $schema = [
            'required' => [
                10 => 'a',
                20 => 'b',
            ],
        ];

        $out = Writer::validateRows($rows, $schema, onError: 'throw');

        self::assertSame($rows, $out);
    }

    public function testTrimDefaultsToFalseSoWhitespaceIsPreservedWhenTrimNotProvided(): void
    {
        $rows = [
            ['a' => '  x  '],
        ];

        $out = Writer::validateRows($rows, [
            'required' => ['a'],
            // trim saknas => default false
        ]);

        self::assertSame('  x  ', $out[0]['a']);
    }

    public function testCastMixedToStringUsesMagicToStringForStringableObjects(): void
    {
        $rm = new ReflectionMethod(Writer::class, 'castMixedToString');
        $rm->setAccessible(true);

        $obj = new class {
            public function __toString(): string
            {
                return 'HELLO';
            }
        };

        self::assertSame('HELLO', $rm->invoke(null, $obj));
    }

    public function testTrimRejectsInvalidStringWithValueInMessage(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Schema-fältet 'trim' måste vara boolean-like (0/1/true/false), fick: 'yes'");

        Writer::validateRows([['a' => ' x ']], [
            'trim' => 'yes',
        ]);
    }

    public function testIntTypeAcceptsWhitespaceAroundIntLikeString(): void
    {
        $schema = [
            'required' => ['id'],
            'types' => ['id' => 'int'],
        ];

        $out = Writer::validateRows([['id' => '  1  ']], $schema, onError: 'throw');

        self::assertSame([['id' => 1]], $out);
    }
}
