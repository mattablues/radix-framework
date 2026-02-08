<?php

declare(strict_types=1);

namespace Radix\Tests\Support;

require_once __DIR__ . '/TestableValidator.php';

use Closure;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Radix\Support\Validator;
use ReflectionMethod;

final class ValidatorWithForcedHoneypotFailure extends Validator
{
    protected function validateHoneypot(mixed $value, ?string $parameter = null): bool
    {
        // Override ska alltid faila, även om värdet är tomt.
        return false;
    }
}

/**
 * Dödar ProtectedVisibility-mutanter för flera regler genom att visa att
 * metoderna måste vara overridable (protected) och att override faktiskt används.
 */
final class ValidatorWithForcedRuleFailures extends Validator
{
    protected function validateNumeric(mixed $value, ?string $parameter = null): bool
    {
        return false;
    }

    protected function validateAlphanumeric(mixed $value, ?string $parameter = null): bool
    {
        return false;
    }

    protected function validateRegex(mixed $value, ?string $parameter = null): bool
    {
        return false;
    }

    protected function validateIn(mixed $value, ?string $parameter = null): bool
    {
        return false;
    }

    protected function validateNotIn(mixed $value, ?string $parameter = null): bool
    {
        return false;
    }

    protected function validateBoolean(mixed $value, ?string $parameter = null): bool
    {
        return false;
    }
}

final class ValidatorWithForcedStringFailure extends Validator
{
    protected function validateString(mixed $value, ?string $parameter = null): bool
    {
        return false;
    }
}

final class ValidatorWithForcedRequiredWithFailure extends Validator
{
    protected function validateRequiredWith(mixed $value, ?string $parameter = null): bool
    {
        return false;
    }
}

/**
 * Exponerar Validator::getErrorMessage() för testning.
 */
final class ValidatorErrorMessageProbe extends Validator
{
    public function probeErrorMessage(string $field, string $rule, mixed $parameter = null): string
    {
        return $this->getErrorMessage($field, $rule, $parameter);
    }
}

/**
 * Icke-skalärt men stringable objekt.
 * Används för att göra skillnad mellan "is_scalar" och "!is_scalar" observerbar
 * utan att få "Array to string conversion"-varningar.
 */
final class StringableNonScalarValue
{
    public function __toString(): string
    {
        return 'OBJ';
    }
}

/**
 * Dödar ProtectedVisibility-mutanten för validateInteger() (protected -> private)
 * genom att visa att override måste användas.
 */
final class ValidatorWithForcedIntegerFailure extends Validator
{
    protected function validateInteger(mixed $value, ?string $parameter = null): bool
    {
        return false;
    }
}

/**
 * Dödar ProtectedVisibility-mutanten för validateRequired() (protected -> private)
 * genom att visa att override måste användas.
 */
final class ValidatorWithForcedRequiredFailure extends Validator
{
    protected function validateRequired(mixed $value, ?string $parameter = null): bool
    {
        return false;
    }
}

final class ValidatorWithForcedEmailFailure extends Validator
{
    protected function validateEmail(mixed $value, ?string $parameter = null): bool
    {
        return false;
    }
}

final class ValidatorWithForcedMinFailure extends Validator
{
    protected function validateMin(mixed $value, ?string $parameter = null): bool
    {
        return false;
    }
}

/**
 * Dödar ProtectedVisibility-mutanten för validateMax() (protected -> private)
 * genom att visa att override måste användas.
 */
final class ValidatorWithForcedMaxFailure extends Validator
{
    protected function validateMax(mixed $value, ?string $parameter = null): bool
    {
        return false;
    }
}

/**
 * Tvingar unique att faila om override får köras.
 * Dödar ProtectedVisibility-mutanten (protected -> private) för validateUnique().
 */
final class ValidatorWithForcedUniqueFailure extends Validator
{
    protected function validateUnique(mixed $value, string $parameter): bool
    {
        return false;
    }
}

/**
 * Tvingar date att faila om override får köras.
 * Dödar ProtectedVisibility-mutanten (protected -> private) för validateDate().
 */
final class ValidatorWithForcedDateFailure extends Validator
{
    protected function validateDate(mixed $value, ?string $parameter = null): bool
    {
        return false;
    }
}

final class NotAModel {}

/**
 * Spy som är kompatibel med Model::query(): QueryBuilder
 * men som loggar where()-anrop och aldrig går mot DB.
 */
final class UniqueRuleQuerySpy extends \Radix\Database\QueryBuilder\QueryBuilder
{
    /** @var array<int,array{column:string,op:string,value:mixed}> */
    public array $wheres = [];

    public function withSoftDeletes(): self
    {
        return $this;
    }

    public function where(Closure|string $column, ?string $operator = null, mixed $value = null, string $boolean = 'AND'): \Radix\Database\QueryBuilder\QueryBuilder
    {
        // Validator::validateUnique() anropar where($column, '=', $value)
        if (is_string($column) && is_string($operator)) {
            $this->wheres[] = [
                'column' => $column,
                'op' => $operator,
                'value' => $value,
            ];
        }

        // Anropa INTE parent::where() – vi vill inte bygga riktig query/SQL här.
        return $this;
    }

    public function first(): ?\Radix\Database\ORM\Model
    {
        return null;
    }
}

final class FakeUniqueModel extends \Radix\Database\ORM\Model
{
    public static ?UniqueRuleQuerySpy $lastQuery = null;

    public static function query(): \Radix\Database\QueryBuilder\QueryBuilder
    {
        self::$lastQuery = new UniqueRuleQuerySpy();
        return self::$lastQuery;
    }

    public static function getPrimaryKey(): string
    {
        return 'id';
    }
}

class ValidatorTest extends TestCase
{
    public function testStringRulePasses(): void
    {
        $data = ['name' => 'John'];
        $rules = ['name' => 'string'];
        $validator = new Validator($data, $rules);
        $this->assertTrue($validator->validate());
    }

    public function testHoneypotRuleUsesOverridableMethod(): void
    {
        $data = [
            'hp_test' => '', // tomt => bas-validateHoneypot() skulle ge true
        ];

        $rules = [
            'hp_test' => 'honeypot',
        ];

        $validator = new ValidatorWithForcedHoneypotFailure($data, $rules);

        // Original (protected): override körs => false => validate() blir false (PASS).
        // Mutant (private): override kan inte användas => basmetoden körs => empty('') => true => validate() blir true (då FAILAR testet)
        $this->assertFalse($validator->validate());
    }

    public function testValidateHoneypotMethodIsProtected(): void
    {
        $method = new ReflectionMethod(Validator::class, 'validateHoneypot');

        $this->assertTrue(
            $method->isProtected(),
            'validateHoneypot() måste vara protected (inte private) så att den kan overridas i subklasser.'
        );
    }

    public function testHoneypotIsCallableFromSubclassAndBehavesCorrectly(): void
    {
        $validator = new TestableValidator(data: [], rules: []);

        $this->assertTrue(
            $validator->testHoneypot(''),
            'Honeypot ska passera för tom sträng.'
        );

        $this->assertFalse(
            $validator->testHoneypot('bot'),
            'Honeypot ska faila när fältet är ifyllt.'
        );
    }

    public function testDotNotationValidationPasses(): void
    {
        $rules = [
            'search.term' => 'required|string|min:1',
            'search.current_page' => 'nullable|integer|min:1',
        ];

        $data = [
            'search' => [
                'term' => 'example',
                'current_page' => 1,
            ],
        ];

        $validator = new Validator($data, $rules);
        $this->assertTrue($validator->validate());
    }

    public function testMinRulePassesWithInteger(): void
    {
        $rules = ['current_page' => 'min:1'];
        $data = ['current_page' => 1];

        $validator = new Validator($data, $rules);
        $this->assertTrue($validator->validate());
    }

    public function testMinRuleFailsWithInteger(): void
    {
        $rules = ['current_page' => 'min:5'];
        $data = ['current_page' => 2];

        $validator = new Validator($data, $rules);
        $this->assertFalse($validator->validate());
    }

    public function testMaxRulePassesWithInteger(): void
    {
        $rules = ['current_page' => 'max:10'];
        $data = ['current_page' => 5];

        $validator = new Validator($data, $rules);
        $this->assertTrue($validator->validate());
    }

    public function testMaxRulePassesWhenValueEqualsNumericLimit(): void
    {
        $data = ['current_page' => 10];
        $rules = ['current_page' => 'max:10'];

        $validator = new Validator($data, $rules);

        // Original: 10 <= 10 => true.
        // Mutant (LessThanOrEqualTo): 10 < 10 => false.
        $this->assertTrue(
            $validator->validate(),
            'max:10 ska tillåta värdet 10 för numeriska fält.'
        );
    }

    public function testMaxRuleFailsWithInteger(): void
    {
        $rules = ['current_page' => 'max:5'];
        $data = ['current_page' => 10];

        $validator = new Validator($data, $rules);
        $this->assertFalse($validator->validate());
    }

    public function testStringRuleFails(): void
    {
        $data = ['name' => 123];
        $rules = ['name' => 'string'];
        $validator = new Validator($data, $rules);
        $this->assertFalse($validator->validate());
    }

    public function testRequiredWithPasses(): void
    {
        $data = ['email' => 'test@example.com', 'phone_number' => '123456789'];
        $rules = ['email' => 'required_with:phone_number'];
        $validator = new Validator($data, $rules);

        $this->assertTrue($validator->validate());
    }

    public function testRequiredWithFails(): void
    {
        $data = ['email' => '', 'phone_number' => '123456789'];
        $rules = ['email' => 'required_with:phone_number'];
        $validator = new Validator($data, $rules);

        $this->assertFalse($validator->validate());
    }

    public function testNullable(): void
    {
        $data = ['middle_name' => ''];
        $rules = ['middle_name' => 'nullable|string'];
        $validator = new Validator($data, $rules);

        $this->assertTrue($validator->validate());
    }

    public function testNullableStringPasses(): void
    {
        $data = ['middle_name' => null];
        $rules = ['middle_name' => 'nullable|string'];
        $validator = new Validator($data, $rules);

        $this->assertTrue($validator->validate());
    }

    public function testNullableStringFailsNonString(): void
    {
        $data = ['middle_name' => 123];
        $rules = ['middle_name' => 'nullable|string'];
        $validator = new Validator($data, $rules);

        $this->assertFalse($validator->validate());
    }

    public function testIntegerPasses(): void
    {
        $rules = ['current_page' => 'integer'];
        $data = ['current_page' => 5];

        $validator = new Validator($data, $rules);
        $this->assertTrue($validator->validate());
    }

    public function testIntegerPassesForNumericString(): void
    {
        $rules = ['current_page' => 'integer'];
        $data = ['current_page' => '5'];

        $validator = new Validator($data, $rules);

        // Original: is_int('5') == false, is_string('5') && ctype_digit('5') == true => true.
        // Mutanter inverterar and/negation och gör att rena siffersträngar ger false.
        $this->assertTrue(
            $validator->validate(),
            'integer-regeln ska godkänna en sträng som endast innehåller siffror, t.ex. "5".'
        );
    }

    public function testIntegerFailsForNonNumericString(): void
    {
        $rules = ['current_page' => 'integer'];
        $data = ['current_page' => '5a'];

        $validator = new Validator($data, $rules);

        // Original: is_int('5a') == false, is_string('5a') && ctype_digit('5a') == false => false.
        // Mutanter LogicalAnd*/LogicalOrAllSubExprNegation kan returnera true här.
        $this->assertFalse(
            $validator->validate(),
            'integer-regeln ska inte godkänna strängar som innehåller andra tecken än siffror, t.ex. "5a".'
        );
    }

    public function testNullableStringPassesWithString(): void
    {
        $data = ['middle_name' => 'Anders'];
        $rules = ['middle_name' => 'nullable|string'];
        $validator = new Validator($data, $rules);

        $this->assertTrue($validator->validate());
    }



    public function testRequiredRulePasses(): void
    {
        $data = ['name' => 'John'];
        $rules = ['name' => 'required'];

        $validator = new Validator($data, $rules);
        $this->assertTrue($validator->validate());
    }

    public function testRequiredRuleFails(): void
    {
        $data = [];
        $rules = ['name' => 'required'];

        $validator = new Validator($data, $rules);
        $this->assertFalse($validator->validate(), 'Validering ska misslyckas eftersom `name` krävs, men är tomt.');
    }

    public function testSingleRuleAsString(): void
    {
        $data = ['name' => 'John'];
        $rules = ['name' => 'required'];

        $validator = new Validator($data, $rules);
        $this->assertTrue($validator->validate(), 'Validering med en enkel regel som sträng ska passera.');
    }

    // Email: Passar och misslyckas
    public function testEmailRulePasses(): void
    {
        $data = ['email' => 'test@example.com'];
        $rules = ['email' => 'email'];

        $validator = new Validator($data, $rules);
        $this->assertTrue($validator->validate());
    }

    public function testEmailRuleFails(): void
    {
        $data = ['email' => 'fel-format'];
        $rules = ['email' => 'email'];

        $validator = new Validator($data, $rules);
        $this->assertFalse($validator->validate());
    }

    // Min och max längd
    public function testMinRulePasses(): void
    {
        $data = ['name' => 'John'];
        $rules = ['name' => 'min:3'];

        $validator = new Validator($data, $rules);
        $this->assertTrue($validator->validate());
    }

    public function testMinRuleFails(): void
    {
        $data = ['name' => 'Jo'];
        $rules = ['name' => 'min:3'];

        $validator = new Validator($data, $rules);
        $this->assertFalse($validator->validate());
    }

    public function testMinRulePassesWhenLengthEqualsMin(): void
    {
        $data = ['name' => 'Tom']; // längd 3
        $rules = ['name' => 'min:3'];

        $validator = new Validator($data, $rules);

        // Original: mb_strlen('Tom') = 3 => 3 >= 3 => true.
        // Mutant (GreaterThanOrEqualTo): 3 > 3 => false.
        $this->assertTrue(
            $validator->validate(),
            'min:3 ska tillåta en sträng med exakt 3 tecken.'
        );
    }

    public function testMinRuleFailsForMultibyteCharacterAtBoundary(): void
    {
        $data = ['name' => 'Å']; // 1 tecken, >1 byte
        $rules = ['name' => 'min:2'];

        $validator = new Validator($data, $rules);

        // Original: mb_strlen('Å') = 1 => 1 >= 2 => false.
        // MBString-mutanter använder strlen => 2 >= 2 => true.
        $this->assertFalse(
            $validator->validate(),
            'min:2 ska inte tillåta ett enstaka multibyte-tecken som "Å".'
        );
    }

    public function testMaxRulePasses(): void
    {
        $data = ['name' => 'John'];
        $rules = ['name' => 'max:10'];

        $validator = new Validator($data, $rules);
        $this->assertTrue($validator->validate());
    }

    public function testMaxRuleAllowsNullValue(): void
    {
        $data = ['name' => null];
        $rules = ['name' => 'max:10'];

        $validator = new Validator($data, $rules);

        // Original: is_null(null) || null === '' => true => vi kortsluter till true.
        // LogicalOr-mutanter (&&) samt ReturnRemoval skulle fortsätta och i slutändan ge false.
        $this->assertTrue(
            $validator->validate(),
            'max-regeln ska behandla null som giltig (ingen validering).'
        );
    }

    public function testMaxRulePassesForMultibyteCharacterAtBoundary(): void
    {
        $data = ['name' => 'Å']; // 1 tecken, men >1 byte i UTF-8
        $rules = ['name' => 'max:1'];

        $validator = new Validator($data, $rules);

        // Original: mb_strlen('Å') = 1 => 1 <= 1 => true.
        // MBString-mutanter använder strlen => 2 <= 1 => false.
        // LessThanOrEqualTo-mutanter gör < istället för <= => 1 < 1 => false.
        $this->assertTrue(
            $validator->validate(),
            'max:1 ska tillåta ett enstaka multibyte-tecken som "Å".'
        );
    }

    public function testMaxRuleAllowsEmptyString(): void
    {
        $data = ['name' => ''];
        $rules = ['name' => 'max:10'];

        $validator = new Validator($data, $rules);

        // Original: tom sträng => true (ingen validering).
        // LogicalOr-mutanter med && och TrueValue/ReturnRemoval-mutanter skulle behandla '' som ogiltigt.
        $this->assertTrue(
            $validator->validate(),
            'max-regeln ska behandla tom sträng som giltig (ingen validering).'
        );
    }

    public function testNullableWithConfirmed(): void
    {
        $data = [
            'password' => '', // Tomt värde, ska ignoreras av nullable
            'password_confirmation' => '',
        ];
        $rules = [
            'password' => 'nullable|confirmed:password_confirmation',
        ];

        $validator = new Validator($data, $rules);

        $this->assertTrue($validator->validate(), 'Password ska vara nullable och confirmed ska inte trigga fel');
    }

    public function testPasswordNullableAndConfirmed(): void
    {
        $data = [
            'password' => 'secret123', // Fyllt värde
            'password_confirmation' => 'secret123',
        ];
        $rules = [
            'password' => 'nullable|confirmed',
        ];

        $validator = new Validator($data, $rules);
        $this->assertTrue($validator->validate(), 'Validering ska passera för password + confirmed.');
    }

    public function testPasswordNullableConfirmed(): void
    {
        $data = [
            'password' => null,
            'password_confirmation' => null,
        ];

        $rules = [
            'password_confirmation' => 'nullable|required_with:password|confirmed:password',
        ];

        $validator = new Validator($data, $rules);
        $this->assertTrue($validator->validate(), 'Validering ska passera eftersom fields är nullable.');
    }

    public function testPasswordConfirmedFails(): void
    {
        $data = [
            'password' => 'secret123',
            'password_confirmation' => 'wrong',
        ];

        $rules = [
            'password_confirmation' => 'nullable|required_with:password|confirmed:password',
        ];

        $validator = new Validator($data, $rules);
        $this->assertFalse($validator->validate(), 'Valideringen ska misslyckas eftersom fält ej matchar.');
    }

    public function testPasswordConfirmedPasses(): void
    {
        $data = [
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ];

        $rules = [
            'password_confirmation' => 'nullable|required_with:password|confirmed:password',
        ];

        $validator = new Validator($data, $rules);
        $this->assertTrue($validator->validate(), 'Valideringen ska passera då fälten matchar.');
    }

    public function testMaxRuleFails(): void
    {
        $data = ['name' => 'Jonathan'];
        $rules = ['name' => 'max:5'];

        $validator = new Validator($data, $rules);
        $this->assertFalse($validator->validate());
    }

    // Numeric validering
    public function testNumericRulePasses(): void
    {
        $data = ['price' => 123];
        $rules = ['price' => 'numeric'];

        $validator = new Validator($data, $rules);
        $this->assertTrue($validator->validate());
    }

    public function testNumericRuleFails(): void
    {
        $data = ['price' => 'abc'];
        $rules = ['price' => 'numeric'];

        $validator = new Validator($data, $rules);
        $this->assertFalse($validator->validate());
    }

    public function testNumericRuleAllowsEmptyString(): void
    {
        $data = ['price' => ''];
        $rules = ['price' => 'numeric'];

        $validator = new Validator($data, $rules);

        // Original: tom sträng => true (ingen validering).
        // LogicalOr-mutanter med && skulle gå vidare och ge false.
        $this->assertTrue(
            $validator->validate(),
            'numeric-regeln ska behandla tom sträng som giltig (ingen validering).'
        );
    }

    // Alphanumeric validering
    public function testAlphanumericRulePasses(): void
    {
        $data = ['username' => 'JohnDoe123'];
        $rules = ['username' => 'alphanumeric'];

        $validator = new Validator($data, $rules);
        $this->assertTrue($validator->validate());
    }

    public function testAlphanumericRuleFails(): void
    {
        $data = ['username' => 'John.Doe!'];
        $rules = ['username' => 'alphanumeric'];

        $validator = new Validator($data, $rules);
        $this->assertFalse($validator->validate());
    }

    public function testAlphanumericRuleAllowsEmptyString(): void
    {
        $data = ['username' => ''];
        $rules = ['username' => 'alphanumeric'];

        $validator = new Validator($data, $rules);

        // Original: tom sträng => true (ingen validering).
        // LogicalOr-mutanter med && skulle gå vidare och ge false.
        $this->assertTrue(
            $validator->validate(),
            'alphanumeric-regeln ska behandla tom sträng som giltig (ingen validering).'
        );
    }

    // Regex
    public function testRegexRulePasses(): void
    {
        $data = ['username' => 'John123'];
        $rules = ['username' => 'regex:/^[a-zA-Z0-9]+$/'];

        $validator = new Validator($data, $rules);
        $this->assertTrue($validator->validate());
    }

    public function testRegexRuleFails(): void
    {
        $data = ['username' => 'John_Doe'];
        $rules = ['username' => 'regex:/^[a-zA-Z0-9]+$/'];

        $validator = new Validator($data, $rules);
        $this->assertFalse($validator->validate());
    }

    public function testRegexRuleAllowsEmptyString(): void
    {
        $data = ['username' => ''];
        $rules = ['username' => 'regex:/^[a-zA-Z0-9]+$/'];

        $validator = new Validator($data, $rules);

        // Original: tom sträng => true (ingen validering).
        // Mutant med && skulle fortsätta och ge false.
        $this->assertTrue(
            $validator->validate(),
            'regex-regeln ska behandla tom sträng som giltig (ingen validering).'
        );
    }

    public function testRegexRuleCastsNonStringScalarToString(): void
    {
        $data = ['code' => 12345]; // int-värde
        $rules = ['code' => 'regex:/^[0-9]+$/'];

        $validator = new Validator($data, $rules);

        // Original: (string) 12345 => "12345" => matchar regex => true.
        // CastString-mutanter utan cast: subject = 12345 (int) => preg_match får fel typ.
        $this->assertTrue(
            $validator->validate(),
            'regex-regeln ska fungera när värdet är ett heltal som matchar mönstret efter sträng-cast.'
        );
    }

    // In och Not in
    public function testInRulePasses(): void
    {
        $data = ['status' => 'active'];
        $rules = ['status' => 'in:active,inactive'];

        $validator = new Validator($data, $rules);
        $this->assertTrue($validator->validate());
    }

    public function testInRuleAllowsEmptyString(): void
    {
        $data = ['status' => ''];
        $rules = ['status' => 'in:active,inactive'];

        $validator = new Validator($data, $rules);

        // Original: tom sträng => true (ingen validering).
        $this->assertTrue(
            $validator->validate(),
            'in-regeln ska behandla tom sträng som giltig (nullable-liknande beteende).'
        );
    }

    public function testInRuleFailsWhenValueNotInAllowedList(): void
    {
        $data = ['status' => 'pending']; // ej i listan
        $rules = ['status' => 'in:active,inactive'];

        $validator = new Validator($data, $rules);

        // Original: går förbi null/''-check, ser att 'pending' inte finns i listan => false.
        // Mutanter LogicalOr* returnerar true direkt för icke-tomt värde.
        $this->assertFalse(
            $validator->validate(),
            'in-regeln ska misslyckas när värdet inte finns i listan med tillåtna värden.'
        );
    }

    public function testInRuleCastsNonStringScalarToString(): void
    {
        $data = ['status' => 1]; // int-värde
        $rules = ['status' => 'in:1,2,3'];

        $validator = new Validator($data, $rules);

        $this->assertTrue(
            $validator->validate(),
            'in-regeln ska fungera när värdet är ett heltal som matchar en tillåten sträng efter cast.'
        );
    }

    public function testNotInRulePasses(): void
    {
        $data = ['role' => 'viewer'];
        $rules = ['role' => 'not_in:admin,superuser'];

        $validator = new Validator($data, $rules);
        $this->assertTrue($validator->validate());
    }

    public function testNotInRuleFailsForDisallowedString(): void
    {
        $data = ['role' => 'admin'];
        $rules = ['role' => 'not_in:admin,superuser'];

        $validator = new Validator($data, $rules);

        // Original: 'admin' finns i disallowed → ska returnera false.
        // LogicalNot-mutanter godkänner alla scalars direkt (return true).
        $this->assertFalse(
            $validator->validate(),
            'not_in ska misslyckas när värdet finns i den otillåtna listan.'
        );
    }

    public function testNotInRuleFailsForDisallowedInt(): void
    {
        $data = ['code' => 1]; // int-värde
        $rules = ['code' => 'not_in:1,2,3'];

        $validator = new Validator($data, $rules);

        // Original: (string)1 => '1' finns i listan => in_array === true => !true => false.
        // CastString-mutanter utan cast: valueString = 1 (int) => strict in_array([... '1','2','3' ...]) => false => !false => true.
        $this->assertFalse(
            $validator->validate(),
            'not_in ska misslyckas även när värdet är ett heltal som matchar en otillåten sträng efter cast.'
        );
    }

    public function testUniqueRuleThrowsForNonStringModelClass(): void
    {
        $data = ['email' => 'test@example.com'];
        // Första delen ('123') är inte en giltig klass-sträng
        $rules = ['email' => 'unique:123,email'];

        $validator = new Validator($data, $rules);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Valideringsregeln 'unique' kräver en giltig modellklass.");

        $validator->validate();
    }

    public function testUniqueRuleThrowsForEmptyModelClass(): void
    {
        $data = ['email' => 'test@example.com'];
        // Tom modellklass, men giltig kolumn
        $rules = ['email' => 'unique:,email'];

        $validator = new Validator($data, $rules);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Valideringsregeln 'unique' kräver en giltig modellklass.");

        $validator->validate();
    }

    // IP validering
    public function testIpRulePasses(): void
    {
        $data = ['server' => '192.168.1.1'];
        $rules = ['server' => 'ip'];

        $validator = new Validator($data, $rules);
        $this->assertTrue($validator->validate());
    }

    public function testIpRuleFails(): void
    {
        $data = ['server' => 'not-an-ip'];
        $rules = ['server' => 'ip'];

        $validator = new Validator($data, $rules);
        $this->assertFalse($validator->validate());
    }

    public function testIpRuleAllowsEmptyString(): void
    {
        $data = ['server' => ''];
        $rules = ['server' => 'ip'];

        $validator = new Validator($data, $rules);
        $this->assertTrue(
            $validator->validate(),
            'IP-regeln ska betrakta tom sträng som giltig (nullable-liknande beteende).'
        );
    }

    // Boolean
    public function testBooleanRulePasses(): void
    {
        $data = ['isActive' => true];
        $rules = ['isActive' => 'boolean'];

        $validator = new Validator($data, $rules);
        $this->assertTrue($validator->validate());
    }

    public function testBooleanRuleFails(): void
    {
        $data = ['isActive' => 'yes'];
        $rules = ['isActive' => 'boolean'];

        $validator = new Validator($data, $rules);
        $this->assertFalse($validator->validate());
    }

    public function testBooleanRulePassesWithFalse(): void
    {
        $data = ['isActive' => false];
        $rules = ['isActive' => 'boolean'];

        $validator = new Validator($data, $rules);

        // Original: is_bool(false) => true.
        // ReturnRemoval-mutanter fortsätter och hamnar till slut på in_array('' eller false, ...) => false.
        $this->assertTrue(
            $validator->validate(),
            'boolean-regeln ska godkänna det rena boolska värdet false.'
        );
    }

    public function testBooleanRulePassesWithStringOne(): void
    {
        $data = ['isActive' => '1'];
        $rules = ['isActive' => 'boolean'];

        $validator = new Validator($data, $rules);

        // Original: !is_scalar är false, castar till '1' och in_array('1', ...) => true.
        // LogicalNot-mutanter vänder if (!is_scalar) till if (is_scalar) och returnerar false direkt.
        $this->assertTrue(
            $validator->validate(),
            "boolean-regeln ska godkänna strängen '1' som sant."
        );
    }

    public function testBooleanRulePassesWithIntOne(): void
    {
        $data = ['isActive' => 1];
        $rules = ['isActive' => 'boolean'];

        $validator = new Validator($data, $rules);

        // Original: (string) 1 => '1' => in_array('1', ...) => true.
        // CastString-mutanter slopar casten, valueString blir int 1 och strict in_array misslyckas.
        $this->assertTrue(
            $validator->validate(),
            'boolean-regeln ska godkänna heltalet 1 som sant.'
        );
    }

    // Date och DateFormat
    public function testDateRulePasses(): void
    {
        $data = ['birthday' => '2023-07-31'];
        $rules = ['birthday' => 'date'];

        $validator = new Validator($data, $rules);
        $this->assertTrue($validator->validate());
    }

    public function testDateRuleFailsWithInvalidValue(): void
    {
        $data = ['birthday' => 'not-a-date'];
        $rules = ['birthday' => 'date'];

        $validator = new Validator($data, $rules);

        // Original: is_scalar + strtotime('not-a-date') === false => false.
        // Mutant 104: skulle kortsluta till true för vissa icke-tomma värden.
        $this->assertFalse(
            $validator->validate(),
            'date-regeln ska misslyckas för ett värde som inte kan parsas som datum.'
        );
    }

    public function testDateRuleAllowsEmptyString(): void
    {
        $data = ['birthday' => ''];
        $rules = ['birthday' => 'date'];

        $validator = new Validator($data, $rules);

        // Original: tom sträng => true.
        // Mutant 104 (negation av första subexpr) skulle gå vidare och kunna ge false.
        $this->assertTrue(
            $validator->validate(),
            'date-regeln ska behandla tom sträng som giltig (ingen validering).'
        );
    }

    public function testDateRuleCastsNonStringScalarToString(): void
    {
        $data = ['birthday' => 20230731]; // int-värde
        $rules = ['birthday' => 'date'];

        $validator = new Validator($data, $rules);

        // Original: (string) 20230731 => "20230731" => strtotime("20230731") !== false => true.
        // Mutant 105 utan cast ger fel typ till strtotime.
        $this->assertTrue(
            $validator->validate(),
            'date-regeln ska fungera när värdet är ett heltal som kan tolkas som datum efter sträng-cast.'
        );
    }

    public function testDateFormatRulePasses(): void
    {
        $data = ['start_date' => '31-07-2023'];
        $rules = ['start_date' => 'date_format:d-m-Y'];

        $validator = new Validator($data, $rules);
        $this->assertTrue($validator->validate());
    }

    public function testDateFormatRuleFailsWithInvalidValue(): void
    {
        $data = ['start_date' => 'not-a-date'];
        $rules = ['start_date' => 'date_format:d-m-Y'];

        $validator = new Validator($data, $rules);
        $this->assertFalse(
            $validator->validate(),
            'date_format:d-m-Y ska misslyckas för ett värde som inte matchar formatet alls.'
        );
    }

    public function testDateFormatAllowsEmptyString(): void
    {
        $data = ['start_date' => ''];
        $rules = ['start_date' => 'date_format:d-m-Y'];

        $validator = new Validator($data, $rules);

        // Original: tom sträng => true.
        // Mutant med && skulle försöka validera vidare och i praktiken falla ut som false.
        $this->assertTrue(
            $validator->validate(),
            'date_format-regeln ska behandla tom sträng som giltig (ingen validering).'
        );
    }

    public function testDateFormatCastsNonStringScalarToString(): void
    {
        $data = ['start_date' => 20230731]; // int-värde
        $rules = ['start_date' => 'date_format:Ymd'];

        $validator = new Validator($data, $rules);

        // Original: (string) 20230731 => "20230731" => giltigt datum i formatet Ymd.
        // Mutant utan cast: valueString är int => DateTime::createFromFormat får fel typ och kraschar.
        $this->assertTrue(
            $validator->validate(),
            'date_format ska fungera även när värdet är ett heltal som matchar datumformatet efter sträng-cast.'
        );
    }

    // StartsWith och EndsWith
    public function testStartsWithRulePasses(): void
    {
        $data = ['username' => 'admin_user'];
        $rules = ['username' => 'starts_with:admin'];

        $validator = new Validator($data, $rules);
        $this->assertTrue($validator->validate());
    }


    public function testStartsWithCastsNonStringScalarToString(): void
    {
        $data = ['number' => 12345]; // int-värde
        $rules = ['number' => 'starts_with:12'];

        $validator = new Validator($data, $rules);

        $this->assertTrue(
            $validator->validate(),
            'starts_with ska fungera även när värdet är ett heltal som matchar prefixet efter sträng-cast.'
        );
    }

    public function testStartsWithFailsOnNonScalarValue(): void
    {
        $data = ['username' => ['not', 'scalar']];
        $rules = ['username' => 'starts_with:admin'];

        $validator = new Validator($data, $rules);

        // Original: !is_scalar($value) => false returneras.
        // Mutanter: första if-satsen blir sann även för array => returnerar true.
        $this->assertFalse(
            $validator->validate(),
            'starts_with ska returnera false för icke-skalära värden (t.ex. array).'
        );
    }

    public function testStartsWithAllowsEmptyString(): void
    {
        $data = ['username' => ''];
        $rules = ['username' => 'starts_with:admin'];

        $validator = new Validator($data, $rules);

        // Original: tom sträng => true.
        // Mutant med && skulle validera hela vägen och returnera false.
        $this->assertTrue(
            $validator->validate(),
            'starts_with-regeln ska behandla tom sträng som giltig (ingen validering).'
        );
    }

    public function testEndsWithRulePasses(): void
    {
        $data = ['file' => 'report.pdf'];
        $rules = ['file' => 'ends_with:.pdf'];

        $validator = new Validator($data, $rules);
        $this->assertTrue($validator->validate());
    }


    public function testEndsWithRuleFails(): void
    {
        $data = ['file' => 'report.txt'];
        $rules = ['file' => 'ends_with:.pdf'];

        $validator = new Validator($data, $rules);
        $this->assertFalse(
            $validator->validate(),
            'ends_with:.pdf ska inte godkänna en fil som slutar på .txt.'
        );
    }

    public function testEndsWithCastsNonStringScalarToString(): void
    {
        $data = ['number' => 12345]; // int-värde
        $rules = ['number' => 'ends_with:45'];

        $validator = new Validator($data, $rules);

        // Original: (string) 12345 => "12345" → slutar med "45" → true.
        // Mutant utan cast: valueString är int => str_ends_with får fel typ och kraschar.
        $this->assertTrue(
            $validator->validate(),
            'ends_with ska fungera även när värdet är ett heltal som matchar suffixet efter sträng-cast.'
        );
    }

    public function testEndsWithAllowsEmptyString(): void
    {
        $data = ['file' => ''];
        $rules = ['file' => 'ends_with:.pdf'];

        $validator = new Validator($data, $rules);

        // Original: tom sträng ska vara "ok" (som övriga nullable-liknande regler).
        // Mutant med && skulle försöka validera vidare och i praktiken falla ut som false.
        $this->assertTrue(
            $validator->validate(),
            'ends_with-regeln ska behandla tom sträng som giltig (ingen validering).'
        );
    }

    public function testGetValueForDotNotationReturnsNullWhenIntermediateIsNotArray(): void
    {
        $data = [
            'user' => 'not-array', // fel struktur
        ];
        $rules = [];

        $validator = new TestableValidator($data, $rules);

        // Original: ska lugnt returnera null utan fel
        $this->assertNull($validator->getDotValue('user.name'));
    }

    /**
     * Valideraren ska omedelbart avbryta och returnera false vid filuppladdningsfel,
     * samt sätta korrekt felmeddelande endast på 'file'.
     *
     * Dödar mutanter:
     * - NotIdentical (error === UPLOAD_ERR_OK)
     * - ArrayItemRemoval (errors['file'] = [])
     * - ReturnRemoval (saknat early return)
     */
    public function testValidateStopsAndSetsErrorOnFileUploadError(): void
    {
        $data = [
            'error' => UPLOAD_ERR_INI_SIZE, // vilket annat fel än UPLOAD_ERR_OK
            // notera: inget 'name' trots regel nedan
        ];
        $rules = [
            'name' => 'required',
        ];

        $validator = new Validator($data, $rules);

        $this->assertFalse(
            $validator->validate(),
            'Validering ska misslyckas direkt vid filuppladdningsfel.'
        );

        $errors = $validator->errors();
        $this->assertArrayHasKey('file', $errors, 'Filfel ska registreras på nyckeln "file".');
        $this->assertSame(
            ['Filen laddades inte upp korrekt.'],
            $errors['file'],
            'Felmeddelandet för filfel ska vara oförändrat.'
        );

        // Om return-satsen tas bort fortsätter valideringen och
        // genererar även fel för "name". Det ska INTE ske.
        $this->assertCount(
            1,
            $errors,
            'Endast filfelet ska finnas registrerat när filuppladdningen misslyckas.'
        );
    }

    /**
     * addError ska vara anropbar publikt och som default INTE stoppa in värdet i meddelandet.
     *
     * Dödar mutanter:
     * - FalseValue (default includeValue = true)
     * - PublicVisibility (protected i stället för public)
     */
    public function testAddErrorDoesNotIncludeValueByDefaultAndIsPublic(): void
    {
        $data = ['field' => 'ABC'];
        $rules = [];

        $validator = new Validator($data, $rules);

        // Om signaturen ändras till protected går detta anrop inte längre.
        $validator->addError('field', 'Värdet är {placeholder}');

        $errors = $validator->errors();
        $this->assertArrayHasKey('field', $errors);

        // Default-beteende: placeholdern ska finnas kvar oförändrad.
        $this->assertSame(
            ['Värdet är {placeholder}'],
            $errors['field'],
            'addError ska inte ersätta {placeholder} när includeValue inte är satt.'
        );
    }

    /**
     * När includeValue=true och värdet är ett skalärt värde, ska {placeholder}
     * ersättas med strängrepresentationen av värdet.
     *
     * Säkerställer korrekt grundbeteende och hjälper mot mutanter i det logiska uttrycket.
     */
    public function testAddErrorIncludesScalarValueWhenFlagIsTrue(): void
    {
        $data = ['age' => 42];
        $rules = [];

        $validator = new Validator($data, $rules);
        $validator->addError('age', 'Ålder är {placeholder}', true);

        $errors = $validator->errors();
        $this->assertSame(
            ['Ålder är 42'],
            $errors['age'],
            'När includeValue=true och värdet är satt ska {placeholder} ersättas med värdet.'
        );
    }

    /**
     * När includeValue=true men värdet är null ska placeholdern INTE ersättas.
     *
     * Dödar mutanter:
     * - NotIdentical (villkor $value === null)
     * - LogicalAnd (bytt till ||, dvs includeValue || $value !== null)
     */
    public function testAddErrorDoesNotIncludeValueWhenNullEvenIfFlagIsTrue(): void
    {
        $data = ['note' => null];
        $rules = [];

        $validator = new Validator($data, $rules);
        $validator->addError('note', 'Notering: {placeholder}', true);

        $errors = $validator->errors();
        $this->assertSame(
            ['Notering: {placeholder}'],
            $errors['note'],
            'När värdet är null ska {placeholder} lämnas orörd även om includeValue=true.'
        );
    }

    /**
     * När includeValue=false, oavsett om värdet är null eller inte, ska placeholdern
     * inte ersättas.
     *
     * Dödar mutant:
     * - LogicalAndAllSubExprNegation (villkor !$includeValue && !($value !== null))
     */
    public function testAddErrorDoesNotIncludeValueWhenFlagIsFalse(): void
    {
        $data = ['status' => 'active'];
        $rules = [];

        $validator = new Validator($data, $rules);
        $validator->addError('status', 'Status: {placeholder}', false);

        $errors = $validator->errors();
        $this->assertSame(
            ['Status: {placeholder}'],
            $errors['status'],
            'När includeValue=false ska {placeholder} inte ersättas, även om värdet är satt.'
        );
    }

    public function testUniqueRuleThrowsForExistingClassThatIsNotAModelSubclass(): void
    {
        $data = ['email' => 'test@example.com'];

        // Klassen finns (class_exists => true) men är inte en Model-subklass.
        // Original ska därför INTE kasta på "giltig modellklass"-guardet,
        // utan på is_subclass_of()-checken.
        $rules = [
            'email' => 'unique:' . NotAModel::class . ',email',
        ];

        $validator = new Validator($data, $rules);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Valideringsregeln 'unique' kräver att modellen ärver");

        $validator->validate();
    }

    public function testValidateDateFormatMethodIsProtected(): void
    {
        $method = new ReflectionMethod(Validator::class, 'validateDateFormat');
        $this->assertTrue($method->isProtected(), 'validateDateFormat() måste vara protected (inte private) för att kunna overridas.');
    }

    public function testValidateStartsWithMethodIsProtected(): void
    {
        $method = new ReflectionMethod(Validator::class, 'validateStartsWith');
        $this->assertTrue($method->isProtected(), 'validateStartsWith() måste vara protected (inte private) för att kunna overridas.');
    }

    public function testValidateEndsWithMethodIsProtected(): void
    {
        $method = new ReflectionMethod(Validator::class, 'validateEndsWith');
        $this->assertTrue($method->isProtected(), 'validateEndsWith() måste vara protected (inte private) för att kunna overridas.');
    }

    public function testValidateConfirmedMethodIsProtected(): void
    {
        $method = new ReflectionMethod(Validator::class, 'validateConfirmed');
        $this->assertTrue($method->isProtected(), 'validateConfirmed() måste vara protected (inte private) för att kunna overridas.');
    }

    public function testValidateIpMethodIsProtected(): void
    {
        $method = new ReflectionMethod(Validator::class, 'validateIp');
        $this->assertTrue($method->isProtected(), 'validateIp() måste vara protected (inte private) för att kunna overridas.');
    }

    public function testUniqueRuleUsesSecondParameterAsColumn(): void
    {
        $data = ['email' => 'test@example.com'];
        $rules = [
            'email' => 'unique:' . FakeUniqueModel::class . ',email,id=5',
        ];

        $validator = new Validator($data, $rules);

        $this->assertTrue($validator->validate(), 'unique ska passera när query->first() är null.');

        $this->assertNotNull(FakeUniqueModel::$lastQuery, 'Query-spy ska ha skapats.');
        $this->assertSame(
            'email',
            FakeUniqueModel::$lastQuery->wheres[0]['column'] ?? null,
            'Första where() ska använda kolumnen från parts[1] (t.ex. "email").'
        );
    }

    public function testUniqueRuleParsesExcludeIdAndAddsNotEqualWhereOnPrimaryKey(): void
    {
        $data = ['email' => 'test@example.com'];
        $rules = [
            'email' => 'unique:' . FakeUniqueModel::class . ',email,id=5',
        ];

        $validator = new Validator($data, $rules);
        $this->assertTrue($validator->validate());

        $this->assertNotNull(FakeUniqueModel::$lastQuery);
        $this->assertCount(
            2,
            FakeUniqueModel::$lastQuery->wheres,
            'När excludeId anges ska en extra where() läggas till.'
        );

        $this->assertSame('id', FakeUniqueModel::$lastQuery->wheres[1]['column']);
        $this->assertSame('!=', FakeUniqueModel::$lastQuery->wheres[1]['op']);
        $this->assertSame(5, FakeUniqueModel::$lastQuery->wheres[1]['value']);
    }

    public function testUniqueRuleDoesNotThrowForValidExistingModelClassString(): void
    {
        $data = ['email' => 'test@example.com'];
        $rules = [
            'email' => 'unique:' . FakeUniqueModel::class . ',email',
        ];

        $validator = new Validator($data, $rules);

        // Om guard-villkoret muteras (t.ex. LogicalNot #36) kan detta börja kasta exception.
        $this->assertTrue($validator->validate(), 'Giltig modellklass-sträng ska inte trigga "ogiltig modellklass"-exception.');
    }

    public function testValidateDateMethodIsProtected(): void
    {
        $method = new ReflectionMethod(Validator::class, 'validateDate');

        $this->assertTrue(
            $method->isProtected(),
            'validateDate() måste vara protected (inte private) så att den kan overridas i subklasser.'
        );
    }

    public function testValidateUniqueMethodIsProtected(): void
    {
        $method = new ReflectionMethod(Validator::class, 'validateUnique');

        $this->assertTrue(
            $method->isProtected(),
            'validateUnique() måste vara protected (inte private) så att den kan overridas i subklasser.'
        );
    }

    public function testDateRuleUsesOverridableMethod(): void
    {
        $data = [
            'birthday' => '2023-07-31', // giltigt datum => basmetoden skulle ge true
        ];

        $rules = [
            'birthday' => 'date',
        ];

        $validator = new ValidatorWithForcedDateFailure($data, $rules);

        // Original (protected): override körs => false => validate() blir false.
        // Mutant (private): override kan inte användas => basmetoden körs => true => validate() blir true (testet failar).
        $this->assertFalse($validator->validate());
    }

    public function testUniqueRuleUsesOverridableMethod(): void
    {
        $data = [
            'email' => 'test@example.com',
        ];

        // Använd fejkmodell som gör att bas-validateUnique() normalt skulle passera (first() => null).
        $rules = [
            'email' => 'unique:' . FakeUniqueModel::class . ',email,id=5',
        ];

        $validator = new ValidatorWithForcedUniqueFailure($data, $rules);

        // Original (protected): override körs => false => validate() blir false.
        // Mutant (private): override ignoreras => basmetoden körs => true => validate() blir true (testet failar).
        $this->assertFalse($validator->validate());
    }

    public function testUniqueRuleThrowsForExistingClassThatIsNotAModelSubclassWithExactMessage(): void
    {
        $data = ['email' => 'test@example.com'];

        $rules = [
            'email' => 'unique:' . NotAModel::class . ',email',
        ];

        $validator = new Validator($data, $rules);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            "Valideringsregeln 'unique' kräver att modellen ärver " . \Radix\Database\ORM\Model::class . "."
        );

        $validator->validate();
    }

    public function testValidateNumericMethodIsProtected(): void
    {
        $method = new ReflectionMethod(Validator::class, 'validateNumeric');
        $this->assertTrue($method->isProtected(), 'validateNumeric() måste vara protected (inte private) så att den kan overridas i subklasser.');
    }

    public function testValidateAlphanumericMethodIsProtected(): void
    {
        $method = new ReflectionMethod(Validator::class, 'validateAlphanumeric');
        $this->assertTrue($method->isProtected(), 'validateAlphanumeric() måste vara protected (inte private) så att den kan overridas i subklasser.');
    }

    public function testValidateRegexMethodIsProtected(): void
    {
        $method = new ReflectionMethod(Validator::class, 'validateRegex');
        $this->assertTrue($method->isProtected(), 'validateRegex() måste vara protected (inte private) så att den kan overridas i subklasser.');
    }

    public function testValidateInMethodIsProtected(): void
    {
        $method = new ReflectionMethod(Validator::class, 'validateIn');
        $this->assertTrue($method->isProtected(), 'validateIn() måste vara protected (inte private) så att den kan overridas i subklasser.');
    }

    public function testValidateNotInMethodIsProtected(): void
    {
        $method = new ReflectionMethod(Validator::class, 'validateNotIn');
        $this->assertTrue($method->isProtected(), 'validateNotIn() måste vara protected (inte private) så att den kan overridas i subklasser.');
    }

    public function testValidateBooleanMethodIsProtected(): void
    {
        $method = new ReflectionMethod(Validator::class, 'validateBoolean');
        $this->assertTrue($method->isProtected(), 'validateBoolean() måste vara protected (inte private) så att den kan overridas i subklasser.');
    }

    public function testNumericRuleUsesOverridableMethod(): void
    {
        $data = ['price' => 123]; // bas-validateNumeric() skulle ge true
        $rules = ['price' => 'numeric'];

        $validator = new ValidatorWithForcedRuleFailures($data, $rules);

        $this->assertFalse(
            $validator->validate(),
            'numeric ska använda overridebar metod; om metoden blir private ska basmetoden köras och detta test faila.'
        );
    }

    public function testAlphanumericRuleUsesOverridableMethod(): void
    {
        $data = ['username' => 'JohnDoe123']; // bas-validateAlphanumeric() skulle ge true
        $rules = ['username' => 'alphanumeric'];

        $validator = new ValidatorWithForcedRuleFailures($data, $rules);

        $this->assertFalse($validator->validate());
    }

    public function testRegexRuleUsesOverridableMethod(): void
    {
        $data = ['code' => 'ABC123']; // matchar regex => bas-validateRegex() skulle ge true
        $rules = ['code' => 'regex:/^[A-Z0-9]+$/'];

        $validator = new ValidatorWithForcedRuleFailures($data, $rules);

        $this->assertFalse($validator->validate());
    }

    public function testInRuleUsesOverridableMethod(): void
    {
        $data = ['status' => 'active']; // bas-validateIn() skulle ge true
        $rules = ['status' => 'in:active,inactive'];

        $validator = new ValidatorWithForcedRuleFailures($data, $rules);

        $this->assertFalse($validator->validate());
    }

    public function testNotInRuleUsesOverridableMethod(): void
    {
        $data = ['role' => 'viewer']; // bas-validateNotIn() skulle ge true (viewer ej i listan)
        $rules = ['role' => 'not_in:admin,superuser'];

        $validator = new ValidatorWithForcedRuleFailures($data, $rules);

        $this->assertFalse($validator->validate());
    }

    public function testBooleanRuleUsesOverridableMethod(): void
    {
        $data = ['isActive' => '1']; // bas-validateBoolean() skulle ge true
        $rules = ['isActive' => 'boolean'];

        $validator = new ValidatorWithForcedRuleFailures($data, $rules);

        $this->assertFalse($validator->validate());
    }

    public function testMinRuleCastsDecimalParameterToIntForStringLengthComparison(): void
    {
        $data = ['name' => 'Tom']; // längd 3
        $rules = ['name' => 'min:3.9'];

        $validator = new Validator($data, $rules);

        // Original:
        // - $minValue = (float)'3.9' => 3.9
        // - mb_strlen('Tom') >= (int)3.9 => 3 >= 3 => true
        //
        // Mutant (CastInt borttagen):
        // - 3 >= 3.9 => false
        $this->assertTrue(
            $validator->validate(),
            'min:3.9 ska behandla stränglängdsgränsen som heltal (3), dvs "Tom" ska passera.'
        );
    }

    public function testValidateMaxMethodIsProtected(): void
    {
        $method = new ReflectionMethod(Validator::class, 'validateMax');

        $this->assertTrue(
            $method->isProtected(),
            'validateMax() måste vara protected (inte private) så att den kan overridas i subklasser.'
        );
    }

    public function testMaxRuleUsesOverridableMethod(): void
    {
        $data = ['name' => 'John']; // bas-validateMax() skulle normalt ge true för max:10
        $rules = ['name' => 'max:10'];

        $validator = new ValidatorWithForcedMaxFailure($data, $rules);

        // Original (protected): override körs => false => validate() blir false.
        // Mutant (private): override ignoreras => basmetoden körs => true => validate() blir true (testet failar).
        $this->assertFalse($validator->validate());
    }

    public function testMinRuleAllowsEmptyString(): void
    {
        $data = ['name' => ''];
        $rules = ['name' => 'min:3'];

        $validator = new Validator($data, $rules);

        // Original: tom sträng => true (ingen validering).
        // Mutant (#16): (is_null($value) && $value === '') blir false för '' => fortsätter
        // och mb_strlen('') >= 3 blir false => validate() blir false.
        $this->assertTrue(
            $validator->validate(),
            'min-regeln ska behandla tom sträng som giltig (ingen validering).'
        );
    }

    public function testValidateStringMethodIsProtected(): void
    {
        $method = new ReflectionMethod(Validator::class, 'validateString');
        $this->assertTrue($method->isProtected(), 'validateString() måste vara protected (inte private) så att den kan overridas i subklasser.');
    }

    public function testStringRuleUsesOverridableMethod(): void
    {
        $data = ['name' => 'John']; // bas-validateString() skulle ge true
        $rules = ['name' => 'string'];

        $validator = new ValidatorWithForcedStringFailure($data, $rules);

        $this->assertFalse($validator->validate());
    }

    public function testValidateRequiredWithMethodIsProtected(): void
    {
        $method = new ReflectionMethod(Validator::class, 'validateRequiredWith');
        $this->assertTrue($method->isProtected(), 'validateRequiredWith() måste vara protected (inte private) så att den kan overridas i subklasser.');
    }

    public function testRequiredWithRuleUsesOverridableMethod(): void
    {
        $data = [
            'email' => 'test@example.com',
            'phone_number' => '123456789', // triggar required_with
        ];
        $rules = [
            'email' => 'required_with:phone_number',
        ];

        $validator = new ValidatorWithForcedRequiredWithFailure($data, $rules);

        $this->assertFalse($validator->validate());
    }

    public function testValidateEmailMethodIsProtected(): void
    {
        $method = new ReflectionMethod(Validator::class, 'validateEmail');
        $this->assertTrue($method->isProtected(), 'validateEmail() måste vara protected (inte private) så att den kan overridas i subklasser.');
    }

    public function testEmailRuleUsesOverridableMethod(): void
    {
        $data = ['email' => 'test@example.com']; // bas-validateEmail() skulle ge true
        $rules = ['email' => 'email'];

        $validator = new ValidatorWithForcedEmailFailure($data, $rules);

        $this->assertFalse($validator->validate());
    }

    public function testValidateMinMethodIsProtected(): void
    {
        $method = new ReflectionMethod(Validator::class, 'validateMin');
        $this->assertTrue($method->isProtected(), 'validateMin() måste vara protected (inte private) så att den kan overridas i subklasser.');
    }

    public function testMinRuleUsesOverridableMethod(): void
    {
        $data = ['name' => 'John']; // bas-validateMin() skulle ge true för min:3
        $rules = ['name' => 'min:3'];

        $validator = new ValidatorWithForcedMinFailure($data, $rules);

        $this->assertFalse($validator->validate());
    }

    public function testConfirmedErrorMessageUsesRtrimWhenParameterIsNull(): void
    {
        $data = [
            'password_confirmation' => 'x',
        ];
        $rules = [];

        $validator = new ValidatorErrorMessageProbe($data, $rules);

        // parameter=null => parameterString=null => ska använda rtrim($field,'_confirmation') => 'password'
        // och därmed översätta till 'lösenord' (inte 'repetera lösenord').
        $message = $validator->probeErrorMessage('password_confirmation', 'confirmed', null);

        $this->assertSame(
            'Fältet repetera lösenord måste matcha fältet lösenord.',
            $message,
            'confirmed ska använda rtrim(field,"_confirmation") när parameter saknas.'
        );
    }

    public function testConfirmedErrorMessagePrefersProvidedParameterStringOverRtrim(): void
    {
        $data = [
            'password_confirmation' => 'x',
        ];
        $rules = [];

        $validator = new ValidatorErrorMessageProbe($data, $rules);

        // Här skickar vi in ett parameterfält som INTE matchar rtrim(...) för att göra coalesce-mutanter observerbara.
        $message = $validator->probeErrorMessage('password_confirmation', 'confirmed', 'different_field');

        $this->assertSame(
            'Fältet repetera lösenord måste matcha fältet different_field.',
            $message,
            'confirmed ska använda parameter om den anges (inte rtrim(field,"_confirmation")).'
        );
    }

    public function testConfirmedErrorMessageTranslatesParameterFieldName(): void
    {
        $data = [
            'password_confirmation' => 'x',
        ];
        $rules = [];

        $validator = new ValidatorErrorMessageProbe($data, $rules);

        // parameter='password' ska översättas till 'lösenord' (dödar coalesce-mutant som hoppar över translation).
        $message = $validator->probeErrorMessage('password_confirmation', 'confirmed', 'password');

        $this->assertSame(
            'Fältet repetera lösenord måste matcha fältet lösenord.',
            $message,
            'confirmed ska översätta parametern med fieldTranslations när den finns.'
        );
    }

    public function testUniqueErrorMessageReplacesPlaceholderWithScalarValue(): void
    {
        $data = [
            'email' => 42, // int för att göra (string)-cast viktig
        ];
        $rules = [];

        $validator = new ValidatorErrorMessageProbe($data, $rules);

        $message = $validator->probeErrorMessage('email', 'unique', null);

        $this->assertSame(
            "Fältet e-post måste vara unikt, '42' används redan.",
            $message,
            'unique-meddelandet ska ersätta {placeholder} med sträng-castat skalärt värde.'
        );
    }

    public function testUniqueErrorMessageDoesNotInsertNonScalarConcreteValue(): void
    {
        $data = [
            'email' => new StringableNonScalarValue(), // icke-skalär men kan castas till "OBJ"
        ];
        $rules = [];

        $validator = new ValidatorErrorMessageProbe($data, $rules);

        $message = $validator->probeErrorMessage('email', 'unique', null);

        // Original: icke-skalär => valueString='' => placeholder ersätts med '' => vi ska INTE se 'OBJ'.
        $this->assertSame(
            "Fältet e-post måste vara unikt, '' används redan.",
            $message,
            'För icke-skalära värden ska vi inte stoppa in konkret värde i felmeddelandet.'
        );
    }

    public function testValidateIntegerMethodIsProtected(): void
    {
        $method = new ReflectionMethod(Validator::class, 'validateInteger');

        $this->assertTrue(
            $method->isProtected(),
            'validateInteger() måste vara protected (inte private) så att den kan overridas i subklasser.'
        );
    }

    public function testIntegerRuleUsesOverridableMethod(): void
    {
        $data = ['current_page' => 5]; // bas-validateInteger() skulle ge true
        $rules = ['current_page' => 'integer'];

        $validator = new ValidatorWithForcedIntegerFailure($data, $rules);

        // Original (protected): override körs => false => validate() blir false.
        // Mutant (private): override ignoreras => basmetoden körs => true => validate() blir true (testet failar).
        $this->assertFalse($validator->validate());
    }

    public function testValidateRequiredMethodIsProtected(): void
    {
        $method = new ReflectionMethod(Validator::class, 'validateRequired');

        $this->assertTrue(
            $method->isProtected(),
            'validateRequired() måste vara protected (inte private) så att den kan overridas i subklasser.'
        );
    }

    public function testRequiredRuleUsesOverridableMethod(): void
    {
        $data = ['name' => 'John']; // bas-validateRequired() skulle ge true
        $rules = ['name' => 'required'];

        $validator = new ValidatorWithForcedRequiredFailure($data, $rules);

        $this->assertFalse($validator->validate());
    }
}
