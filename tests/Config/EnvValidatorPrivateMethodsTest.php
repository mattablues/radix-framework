<?php

declare(strict_types=1);

namespace Radix\Tests\Config;

use PHPUnit\Framework\TestCase;
use Radix\Config\EnvValidator;
use ReflectionMethod;
use ReflectionProperty;

final class EnvValidatorPrivateMethodsTest extends TestCase
{
    /** @var list<string> */
    private array $touched = [];

    protected function tearDown(): void
    {
        foreach (array_unique($this->touched) as $k) {
            putenv($k);
            unset($_ENV[$k], $_SERVER[$k]);
        }
        $this->touched = [];
    }

    private function clearEnv(string $key): void
    {
        $this->touched[] = $key;
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    }

    /**
     * @return array<int,string>
     */
    private function errorsOf(EnvValidator $v): array
    {
        $rp = new ReflectionProperty(EnvValidator::class, 'errors');
        $rp->setAccessible(true);

        /** @var array<int, string> $errors */
        $errors = $rp->getValue($v);
        return $errors;
    }

    private function rm(string $method): ReflectionMethod
    {
        $rm = new ReflectionMethod(EnvValidator::class, $method);
        $rm->setAccessible(true);
        return $rm;
    }

    public function testTimezoneAddsErrorWhenEmptyAndAllowEmptyIsFalse(): void
    {
        $this->clearEnv('APP_TIMEZONE');

        $v = new EnvValidator();

        $rm = $this->rm('timezone');
        $rm->invoke($v, 'APP_TIMEZONE', false);

        $errors = $this->errorsOf($v);
        $this->assertNotEmpty($errors);
    }

    public function testTimezoneAddsErrorWhenValueIsInvalid(): void
    {
        // Dödar mutant 133: (|| -> &&) skulle göra att ogiltigt värde inte ger fel.
        putenv('APP_TIMEZONE=Not/A_Real_Timezone');
        $_ENV['APP_TIMEZONE'] = 'Not/A_Real_Timezone';
        $_SERVER['APP_TIMEZONE'] = 'Not/A_Real_Timezone';

        $v = new EnvValidator();

        $rm = new ReflectionMethod(EnvValidator::class, 'timezone');
        $rm->setAccessible(true);

        // allowEmpty = false (spelar ingen roll här, värdet är inte tomt)
        $rm->invoke($v, 'APP_TIMEZONE', false);

        $errors = $this->errorsOf($v);
        $this->assertNotEmpty($errors, 'timezone() ska lägga till fel när värdet inte är en giltig timezone.');
    }

    public function testIsValidIpOrCidrRejectsPlusPrefixedPrefixLength(): void
    {
        $v = new EnvValidator();

        $rm = new ReflectionMethod(EnvValidator::class, 'isValidIpOrCidr');
        $rm->setAccessible(true);

        $this->assertFalse(
            (bool) $rm->invoke($v, '203.0.113.5/+1'),
            'CIDR-prefix får inte acceptera +1; bara rena siffror ska vara tillåtna.'
        );
    }

    public function testTimezoneDefaultDoesNotAllowEmpty(): void
    {
        // Dödar mutant 127 (default allowEmpty=true).
        // Vi anropar timezone() utan andra parametern -> default ska vara false -> tomt ska ge fel.
        putenv('APP_TIMEZONE=');
        unset($_ENV['APP_TIMEZONE'], $_SERVER['APP_TIMEZONE']);

        $v = new EnvValidator();

        $rm = new ReflectionMethod(EnvValidator::class, 'timezone');
        $rm->setAccessible(true);

        $rm->invoke($v, 'APP_TIMEZONE'); // default allowEmpty=false

        $errors = $this->errorsOf($v);
        $this->assertNotEmpty($errors, 'timezone() ska ge fel för tomt värde när allowEmpty inte anges.');
    }

    public function testTimezoneAllowEmptyTrueStillValidatesNonEmptyValue(): void
    {
        // Dödar mutant 128 (returnerar felaktigt när v !== '' && allowEmpty).
        putenv('APP_TIMEZONE=Not/A_Real_Timezone');
        $_ENV['APP_TIMEZONE'] = 'Not/A_Real_Timezone';
        $_SERVER['APP_TIMEZONE'] = 'Not/A_Real_Timezone';

        $v = new EnvValidator();

        $rm = new ReflectionMethod(EnvValidator::class, 'timezone');
        $rm->setAccessible(true);

        $rm->invoke($v, 'APP_TIMEZONE', true);

        $errors = $this->errorsOf($v);
        $this->assertNotEmpty($errors, 'timezone() ska validera ogiltiga värden även när allowEmpty=true.');
    }

    public function testIpListAddsErrorWhenEmptyAndAllowEmptyIsFalse(): void
    {
        // Dödar mutant 134 (default allowEmpty=true) + 135 (raw=='' || allowEmpty) genom att skicka allowEmpty=false explicit.
        putenv('TRUSTED_PROXY=');
        unset($_ENV['TRUSTED_PROXY'], $_SERVER['TRUSTED_PROXY']);

        $v = new EnvValidator();

        $rm = new ReflectionMethod(EnvValidator::class, 'ipList');
        $rm->setAccessible(true);

        $rm->invoke($v, 'TRUSTED_PROXY', false);

        $errors = $this->errorsOf($v);
        $this->assertNotEmpty($errors, 'ipList() ska lägga till fel när värdet är tomt och allowEmpty=false.');
    }

    public function testIpListDefaultDoesNotAllowEmpty(): void
    {
        // Dödar mutant 129 (default allowEmpty=true).
        // Vi anropar ipList() utan andra parametern -> default ska vara false -> tomt ska ge fel.
        putenv('TRUSTED_PROXY=');
        unset($_ENV['TRUSTED_PROXY'], $_SERVER['TRUSTED_PROXY']);

        $v = new EnvValidator();

        $rm = new ReflectionMethod(EnvValidator::class, 'ipList');
        $rm->setAccessible(true);

        $rm->invoke($v, 'TRUSTED_PROXY'); // default allowEmpty=false

        $errors = $this->errorsOf($v);
        $this->assertNotEmpty($errors, 'ipList() ska ge fel för tomt värde när allowEmpty inte anges.');
    }

    public function testIsAbsoluteDoesNotMatchDriveLetterInMiddleOfString(): void
    {
        // Dödar mutant 136 (pregmatch utan ^): "prefixC:\..." ska INTE räknas som absolut.
        $v = new EnvValidator();

        $rm = new ReflectionMethod(EnvValidator::class, 'isAbsolute');
        $rm->setAccessible(true);

        $this->assertFalse(
            (bool) $rm->invoke($v, 'prefixC:\\temp'),
            'Drive-prefix ska bara matcha i början av strängen.'
        );
    }

    public function testEmailAddsErrorForInvalidNonEmptyEmail(): void
    {
        putenv('MAIL_EMAIL=not-an-email');
        $_ENV['MAIL_EMAIL'] = 'not-an-email';
        $_SERVER['MAIL_EMAIL'] = 'not-an-email';

        $v = new EnvValidator();

        $rm = new ReflectionMethod(EnvValidator::class, 'email');
        $rm->setAccessible(true);

        // allowEmpty=false -> ogiltigt värde ska ge fel
        $rm->invoke($v, 'MAIL_EMAIL', false);

        $errors = $this->errorsOf($v);
        $this->assertNotEmpty($errors, 'email() ska lägga till fel när e-post är ogiltig och inte tom.');
    }

    public function testEmailDefaultDoesNotAllowEmpty(): void
    {
        // Dödar mutant 120 (default allowEmpty=true)
        putenv('MAIL_EMAIL=');
        unset($_ENV['MAIL_EMAIL'], $_SERVER['MAIL_EMAIL']);

        $v = new EnvValidator();

        $rm = new ReflectionMethod(EnvValidator::class, 'email');
        $rm->setAccessible(true);

        // Ingen allowEmpty-parameter -> default ska vara false -> tomt ska ge fel
        $rm->invoke($v, 'MAIL_EMAIL');

        $errors = $this->errorsOf($v);
        $this->assertNotEmpty($errors, 'email() ska ge fel för tomt värde när allowEmpty inte anges.');
    }

    public function testEmailAllowEmptyTrueStillValidatesNonEmptyValue(): void
    {
        // Dödar mutant 121/122/123: allowEmpty=true får inte göra att vi skippar validering när värdet är non-empty.
        putenv('MAIL_EMAIL=not-an-email');
        $_ENV['MAIL_EMAIL'] = 'not-an-email';
        $_SERVER['MAIL_EMAIL'] = 'not-an-email';

        $v = new EnvValidator();

        $rm = new ReflectionMethod(EnvValidator::class, 'email');
        $rm->setAccessible(true);

        $rm->invoke($v, 'MAIL_EMAIL', true);

        $errors = $this->errorsOf($v);
        $this->assertNotEmpty($errors, 'email() ska validera ogiltiga värden även när allowEmpty=true.');
    }

    public function testEnumDefaultDoesNotAllowEmpty(): void
    {
        // Dödar 89: default allowEmpty får inte vara true.
        $v = new EnvValidator();

        $rm = new ReflectionMethod(EnvValidator::class, 'enum');
        $rm->setAccessible(true);

        putenv('TEST_ENUM=');
        unset($_ENV['TEST_ENUM'], $_SERVER['TEST_ENUM']);

        // Ingen allowEmpty-parameter -> default ska vara false -> tomt värde ska ge fel
        $rm->invoke($v, 'TEST_ENUM', ['a', 'b']);

        $errors = $this->errorsOf($v);
        $this->assertNotEmpty($errors, 'enum() ska ge fel för tomt värde när allowEmpty inte anges.');
    }

    public function testEnumAllowEmptyTrueSkipsOnlyWhenValueIsEmpty(): void
    {
        // Dödar 90/91: allowEmpty=true får inte göra att vi returnerar för non-empty värden.
        $rm = new ReflectionMethod(EnvValidator::class, 'enum');
        $rm->setAccessible(true);

        // 1) allowEmpty=true + tomt värde => ska INTE ge fel
        $v1 = new EnvValidator();

        putenv('TEST_ENUM=');
        unset($_ENV['TEST_ENUM'], $_SERVER['TEST_ENUM']);

        $rm->invoke($v1, 'TEST_ENUM', ['a', 'b'], true);

        $errors1 = $this->errorsOf($v1);
        $this->assertSame([], $errors1, 'enum() ska acceptera tomt värde när allowEmpty=true.');

        // 2) allowEmpty=true + icke-tomt OGILTIGT värde => ska ge fel
        // (om mutanten "if ($v === '' || $allowEmpty) return;" finns så blir detta felaktigt OK)
        $v2 = new EnvValidator();

        putenv('TEST_ENUM=bogus');
        $_ENV['TEST_ENUM'] = 'bogus';
        $_SERVER['TEST_ENUM'] = 'bogus';

        $rm->invoke($v2, 'TEST_ENUM', ['a', 'b'], true);

        $errors2 = $this->errorsOf($v2);
        $this->assertNotEmpty($errors2, 'enum() ska fortfarande validera non-empty värden även när allowEmpty=true.');
    }

    public function testEnumAllowEmptyFalseStillValidatesEmptyAsError(): void
    {
        // Dödar 92: om villkoret blir ($v === '' && !$allowEmpty) return; så skulle tomt + allowEmpty=false bli OK.
        $v = new EnvValidator();

        $rm = new ReflectionMethod(EnvValidator::class, 'enum');
        $rm->setAccessible(true);

        putenv('TEST_ENUM=');
        unset($_ENV['TEST_ENUM'], $_SERVER['TEST_ENUM']);

        $rm->invoke($v, 'TEST_ENUM', ['a', 'b'], false);

        $errors = $this->errorsOf($v);
        $this->assertNotEmpty($errors, 'enum() ska ge fel för tomt värde när allowEmpty=false.');
    }

    public function testIntLikeMinAllowsExactMinButFailsBelow(): void
    {
        $v = new EnvValidator();

        $rm = new ReflectionMethod(EnvValidator::class, 'intLike');
        $rm->setAccessible(true);

        // Exact min ska PASSERA (dödar mutant 116: <= istället för <)
        putenv('TEST_INT=10');
        $_ENV['TEST_INT'] = '10';
        $_SERVER['TEST_INT'] = '10';

        $rm->invoke($v, 'TEST_INT', false, 10, null);
        $errors = $this->errorsOf($v);
        $this->assertSame([], $errors, 'intLike() ska acceptera värde exakt lika med min.');

        // Under min ska FAILA (säkerställer att min-branch faktiskt körs, relevant för 115)
        $v2 = new EnvValidator();
        putenv('TEST_INT=9');
        $_ENV['TEST_INT'] = '9';
        $_SERVER['TEST_INT'] = '9';

        $rm->invoke($v2, 'TEST_INT', false, 10, null);
        $errors2 = $this->errorsOf($v2);
        $this->assertNotEmpty($errors2, 'intLike() ska lägga till fel när värdet är mindre än min.');
    }

    public function testIntLikeMaxAllowsExactMaxButFailsAbove(): void
    {
        $v = new EnvValidator();

        $rm = new ReflectionMethod(EnvValidator::class, 'intLike');
        $rm->setAccessible(true);

        // Exact max ska PASSERA (dödar mutant 117: >= istället för >)
        putenv('TEST_INT=10');
        $_ENV['TEST_INT'] = '10';
        $_SERVER['TEST_INT'] = '10';

        $rm->invoke($v, 'TEST_INT', false, null, 10);
        $errors = $this->errorsOf($v);
        $this->assertSame([], $errors, 'intLike() ska acceptera värde exakt lika med max.');

        // Över max ska FAILA (dödar även 118-typen, eftersom max-branch måste kunna trigga)
        $v2 = new EnvValidator();
        putenv('TEST_INT=11');
        $_ENV['TEST_INT'] = '11';
        $_SERVER['TEST_INT'] = '11';

        $rm->invoke($v2, 'TEST_INT', false, null, 10);
        $errors2 = $this->errorsOf($v2);
        $this->assertNotEmpty($errors2, 'intLike() ska lägga till fel när värdet är större än max.');
    }

    public function testIntLikeAllowEmptyTrueDoesNotSkipValidationWhenValueIsPresent(): void
    {
        // Dödar 107 + 108: allowEmpty=true får inte göra att vi skippar när värdet är non-empty.
        putenv('TEST_INT=not-an-int');
        $_ENV['TEST_INT'] = 'not-an-int';
        $_SERVER['TEST_INT'] = 'not-an-int';

        $v = new EnvValidator();

        $rm = new ReflectionMethod(EnvValidator::class, 'intLike');
        $rm->setAccessible(true);

        $rm->invoke($v, 'TEST_INT', true, null, null);

        $errors = $this->errorsOf($v);
        $this->assertNotEmpty($errors, 'intLike() ska fortfarande validera non-empty värden även när allowEmpty=true.');
    }

    public function testIntLikeEmptyWithAllowEmptyFalseAddsError(): void
    {
        // Dödar 109: tomt värde ska INTE returnera tidigt när allowEmpty=false; det ska ge "must be integer".
        putenv('TEST_INT=');
        unset($_ENV['TEST_INT'], $_SERVER['TEST_INT']);

        $v = new EnvValidator();

        $rm = new ReflectionMethod(EnvValidator::class, 'intLike');
        $rm->setAccessible(true);

        $rm->invoke($v, 'TEST_INT', false, null, null);

        $errors = $this->errorsOf($v);
        $this->assertNotEmpty($errors, 'intLike() ska ge fel för tomt värde när allowEmpty=false.');
    }

    public function testIntLikeAllowsLeadingPlusAndMinComparisonUsesIntCast(): void
    {
        // Dödar 110: ltrim($v, '+') behövs så +123 räknas som siffra.
        // Dödar 112: cast till int behövs så min-jämförelser blir rätt.
        $rm = new ReflectionMethod(EnvValidator::class, 'intLike');
        $rm->setAccessible(true);

        // +123 ska accepteras som integer
        $v1 = new EnvValidator();
        putenv('TEST_INT=+123');
        $_ENV['TEST_INT'] = '+123';
        $_SERVER['TEST_INT'] = '+123';

        $rm->invoke($v1, 'TEST_INT', false, null, null);
        $errors1 = $this->errorsOf($v1);
        $this->assertSame([], $errors1, 'intLike() ska acceptera heltal med ledande +.');

        // 9 med min=10 ska ge fel (för att säkerställa korrekt int-cast och jämförelse)
        $v2 = new EnvValidator();
        putenv('TEST_INT=9');
        $_ENV['TEST_INT'] = '9';
        $_SERVER['TEST_INT'] = '9';

        $rm->invoke($v2, 'TEST_INT', false, 10, null);
        $errors2 = $this->errorsOf($v2);
        $this->assertNotEmpty($errors2, 'intLike() ska ge fel när värdet är mindre än min.');
    }

    public function testBoolLikeAllowEmptyTrueSkipsValidationOnlyWhenValueIsEmpty(): void
    {
        $v = new EnvValidator();

        $rm = new ReflectionMethod(EnvValidator::class, 'boolLike');
        $rm->setAccessible(true);

        // 1) allowEmpty=true + tomt värde => ska INTE ge fel (dödar 103 + 105 m.fl.)
        putenv('TEST_BOOL=');
        unset($_ENV['TEST_BOOL'], $_SERVER['TEST_BOOL']);

        $rm->invoke($v, 'TEST_BOOL', true);

        $errors = $this->errorsOf($v);
        $this->assertSame([], $errors, 'boolLike() ska acceptera tomt värde när allowEmpty=true.');

        // 2) allowEmpty=true + icke-tomt OGILTIGT värde => ska ge fel (dödar 101 + 102 + 104)
        $v2 = new EnvValidator();

        putenv('TEST_BOOL=maybe');
        $_ENV['TEST_BOOL'] = 'maybe';
        $_SERVER['TEST_BOOL'] = 'maybe';

        $rm->invoke($v2, 'TEST_BOOL', true);

        $errors2 = $this->errorsOf($v2);
        $this->assertNotEmpty($errors2, 'boolLike() ska validera icke-tomma värden även när allowEmpty=true.');
    }

    public function testIntLikeDefaultAllowEmptyIsFalse(): void
    {
        // Dödar 106: default allowEmpty får inte vara true.
        $v = new EnvValidator();

        $rm = new ReflectionMethod(EnvValidator::class, 'intLike');
        $rm->setAccessible(true);

        // Tomt värde + ingen allowEmpty-parameter => ska ge fel
        putenv('TEST_INT=');
        unset($_ENV['TEST_INT'], $_SERVER['TEST_INT']);

        $rm->invoke($v, 'TEST_INT'); // default allowEmpty=false

        $errors = $this->errorsOf($v);
        $this->assertNotEmpty($errors, 'intLike() ska ge fel för tomt värde när allowEmpty inte anges.');
    }

    public function testIntLikeStopsAfterNonIntegerSoMinMaxErrorsAreNotAdded(): void
    {
        // Dödar 107 (ReturnRemoval): utan return skulle min/max-checkar kunna lägga extra fel.
        $v = new EnvValidator();

        $rm = new ReflectionMethod(EnvValidator::class, 'intLike');
        $rm->setAccessible(true);

        putenv('TEST_INT=not-an-int');
        $_ENV['TEST_INT'] = 'not-an-int';
        $_SERVER['TEST_INT'] = 'not-an-int';

        $rm->invoke($v, 'TEST_INT', false, 10, 20);

        $errors = $this->errorsOf($v);

        $this->assertCount(
            1,
            $errors,
            'intLike() ska bara lägga ett fel ("must be integer") och inte fortsätta med min/max när värdet inte är ett heltal.'
        );
        $this->assertSame('TEST_INT must be integer', $errors[0]);
    }

    public function testIntLikeMinMaxComparisonUsesIntValueNotRawString(): void
    {
        // Avsikt: döda 108 (CastInt) genom att säkerställa korrekt numerisk jämförelseväg.
        // Vi triggar både min och max-path på ett kontrollerat sätt.
        $rm = new ReflectionMethod(EnvValidator::class, 'intLike');
        $rm->setAccessible(true);

        // Under min -> ska ge min-fel
        $v1 = new EnvValidator();
        putenv('TEST_INT=9');
        $_ENV['TEST_INT'] = '9';
        $_SERVER['TEST_INT'] = '9';

        $rm->invoke($v1, 'TEST_INT', false, 10, 20);
        $errors1 = $this->errorsOf($v1);
        $this->assertNotEmpty($errors1, 'intLike() ska ge fel när värdet är mindre än min.');

        // Över max -> ska ge max-fel
        $v2 = new EnvValidator();
        putenv('TEST_INT=21');
        $_ENV['TEST_INT'] = '21';
        $_SERVER['TEST_INT'] = '21';

        $rm->invoke($v2, 'TEST_INT', false, 10, 20);
        $errors2 = $this->errorsOf($v2);
        $this->assertNotEmpty($errors2, 'intLike() ska ge fel när värdet är större än max.');
    }

    public function testEnumIsCaseInsensitive(): void
    {
        // Dödar 97: enum() måste lowercasa input innan in_array mot allowedLower.
        $v = new EnvValidator();

        $rm = new ReflectionMethod(EnvValidator::class, 'enum');
        $rm->setAccessible(true);

        putenv('TEST_ENUM=DeVeLoPmEnT');
        $_ENV['TEST_ENUM'] = 'DeVeLoPmEnT';
        $_SERVER['TEST_ENUM'] = 'DeVeLoPmEnT';

        $rm->invoke($v, 'TEST_ENUM', ['development'], false);

        $errors = $this->errorsOf($v);
        $this->assertSame([], $errors, 'enum() ska vara case-insensitive för tillåtna värden.');
    }

    public function testEnumAddsErrorForInvalidNonEmptyValue(): void
    {
        // Dödar 98: (|| -> &&) skulle göra att icke-tomma ogiltiga värden INTE ger fel.
        $v = new EnvValidator();

        $rm = new ReflectionMethod(EnvValidator::class, 'enum');
        $rm->setAccessible(true);

        putenv('TEST_ENUM=not-allowed');
        $_ENV['TEST_ENUM'] = 'not-allowed';
        $_SERVER['TEST_ENUM'] = 'not-allowed';

        $rm->invoke($v, 'TEST_ENUM', ['development', 'production'], false);

        $errors = $this->errorsOf($v);
        $this->assertNotEmpty($errors, 'enum() ska lägga till fel för icke-tomt ogiltigt värde.');
    }

    public function testBoolLikeDefaultDoesNotAllowEmpty(): void
    {
        // Dödar 99: default allowEmpty får inte vara true.
        $v = new EnvValidator();

        $rm = new ReflectionMethod(EnvValidator::class, 'boolLike');
        $rm->setAccessible(true);

        putenv('TEST_BOOL=');
        unset($_ENV['TEST_BOOL'], $_SERVER['TEST_BOOL']);

        $rm->invoke($v, 'TEST_BOOL'); // default allowEmpty=false

        $errors = $this->errorsOf($v);
        $this->assertNotEmpty($errors, 'boolLike() ska ge fel för tomt värde när allowEmpty inte anges.');
    }

    public function testBoolLikeAcceptsUppercaseTrue(): void
    {
        // Dödar 100: boolLike() måste lowercasa input så "TRUE" accepteras.
        $v = new EnvValidator();

        $rm = new ReflectionMethod(EnvValidator::class, 'boolLike');
        $rm->setAccessible(true);

        putenv('TEST_BOOL=TRUE');
        $_ENV['TEST_BOOL'] = 'TRUE';
        $_SERVER['TEST_BOOL'] = 'TRUE';

        $rm->invoke($v, 'TEST_BOOL', false);

        $errors = $this->errorsOf($v);
        $this->assertSame([], $errors, 'boolLike() ska acceptera TRUE/FALSE oavsett case.');
    }

    public function testIntLikeUsesIntCastWhenComparingWithMaxForHugeNumericStrings(): void
    {
        // Dödar 101 (CastInt): gör casten observerbar via overflow/float-jämförelse.
        $v = new EnvValidator();

        $rm = new ReflectionMethod(EnvValidator::class, 'intLike');
        $rm->setAccessible(true);

        // Större än PHP_INT_MAX (på 64-bit): ctype_digit OK, men (int) cast saturerar.
        $huge = (string) PHP_INT_MAX . '0';

        putenv('TEST_INT=' . $huge);
        $_ENV['TEST_INT'] = $huge;
        $_SERVER['TEST_INT'] = $huge;

        // Med cast: (int)$huge blir PHP_INT_MAX => ska INTE vara > PHP_INT_MAX
        $rm->invoke($v, 'TEST_INT', false, null, PHP_INT_MAX);

        $errors = $this->errorsOf($v);
        $this->assertSame([], $errors, 'intLike() ska jämföra med int-cast så max-check blir stabil för stora tal.');
    }

    public function testEnumIsCaseInsensitiveAlsoForAllowedList(): void
    {
        // Dödar 93 + 94: om allowedLower inte lowercasas så failar t.ex. 'dev' mot ['DeV'].
        $v = new EnvValidator();

        $rm = new ReflectionMethod(EnvValidator::class, 'enum');
        $rm->setAccessible(true);

        putenv('TEST_ENUM=dev');
        $_ENV['TEST_ENUM'] = 'dev';
        $_SERVER['TEST_ENUM'] = 'dev';

        $rm->invoke($v, 'TEST_ENUM', ['DeV'], false);

        $errors = $this->errorsOf($v);
        $this->assertSame([], $errors, 'enum() ska matcha case-insensitive även när allowed-listan antyder annan casing.');
    }

    public function testEnumErrorMessageContainsKeyAndAllowedListInExpectedFormat(): void
    {
        // Dödar 95–97: säkerställ exakt strängformat så concat-mutanter inte överlever.
        $v = new EnvValidator();

        $rm = new ReflectionMethod(EnvValidator::class, 'enum');
        $rm->setAccessible(true);

        putenv('TEST_ENUM=bogus');
        $_ENV['TEST_ENUM'] = 'bogus';
        $_SERVER['TEST_ENUM'] = 'bogus';

        $allowed = ['mysql', 'sqlite'];
        $rm->invoke($v, 'TEST_ENUM', $allowed, false);

        $errors = $this->errorsOf($v);

        $this->assertCount(1, $errors, 'enum() ska lägga exakt ett fel vid ogiltigt värde.');
        $this->assertSame(
            'TEST_ENUM must be one of: mysql, sqlite',
            $errors[0],
            'enum() ska ha formatet: "$key must be one of: " + implode(", ", $allowed).'
        );
    }

    public function testUrlAddsErrorForInvalidNonEmptyUrl(): void
    {
        putenv('APP_URL=not-a-url');
        $_ENV['APP_URL'] = 'not-a-url';
        $_SERVER['APP_URL'] = 'not-a-url';

        $v = new EnvValidator();

        $rm = new ReflectionMethod(EnvValidator::class, 'url');
        $rm->setAccessible(true);

        $rm->invoke($v, 'APP_URL');

        $errors = $this->errorsOf($v);
        $this->assertNotEmpty($errors, 'url() ska lägga till fel när URL är ogiltig och inte tom.');
    }

    public function testEnumAllowEmptyTrueAllowsEmptyValue(): void
    {
        // Säkerställ att enum() med allowEmpty=true inte ger fel när värdet är tomt.
        putenv('TEST_ENUM=');
        unset($_ENV['TEST_ENUM'], $_SERVER['TEST_ENUM']);

        $v = new EnvValidator();

        $rm = new ReflectionMethod(EnvValidator::class, 'enum');
        $rm->setAccessible(true);

        $rm->invoke($v, 'TEST_ENUM', ['a', 'b'], true);

        $errors = $this->errorsOf($v);
        $this->assertSame([], $errors);
    }

    public function testEnumAllowEmptyTrueDoesNotAddErrorForEmptyValue(): void
    {
        // Dödar mutant 48 (allowEmpty true -> false) i enum()-anropet för MAIL_SECURE,
        // genom att verifiera enum()-beteendet isolerat.
        putenv('TEST_ENUM=');
        unset($_ENV['TEST_ENUM'], $_SERVER['TEST_ENUM']);

        $v = new EnvValidator();

        $rm = new ReflectionMethod(EnvValidator::class, 'enum');
        $rm->setAccessible(true);

        $rm->invoke($v, 'TEST_ENUM', ['none', 'ssl', 'tls'], true);

        $errors = $this->errorsOf($v);
        $this->assertSame([], $errors, 'enum() ska acceptera tomt värde när allowEmpty=true.');
    }

    public function testIpListTrimsItemsAndIgnoresEmptySegments(): void
    {
        // Dödar:
        // - 41 UnwrapArrayMap (utan trim blir " 127.0.0.1 " ogiltig)
        // - 43 UnwrapArrayFilter / 42 NotIdentical (tomma segment ska ignoreras)
        putenv('TEST_IP_LIST= 127.0.0.1 , , 198.51.100.0/24 ,   ::1  ,');
        $_ENV['TEST_IP_LIST'] = ' 127.0.0.1 , , 198.51.100.0/24 ,   ::1  ,';
        $_SERVER['TEST_IP_LIST'] = ' 127.0.0.1 , , 198.51.100.0/24 ,   ::1  ,';

        $v = new EnvValidator();

        $rm = $this->rm('ipList');
        $rm->invoke($v, 'TEST_IP_LIST', false);

        $errors = $this->errorsOf($v);
        $this->assertSame([], $errors, 'ipList() ska trimma items och ignorera tomma segment.');
    }

    public function testIpListContinuesAfterValidItemAndReportsLaterInvalidTrimmedItem(): void
    {
        // Dödar:
        // - 45 Foreach_ (foreach([]) => inga fel)
        // - 46 Continue_ (continue->break skulle sluta efter första giltiga item och missa ogiltiga)
        // - 41 UnwrapArrayMap (utan trim blir " not-an-ip" annan sträng; vi kräver trim-meddelande)
        putenv('TEST_IP_LIST=127.0.0.1, not-an-ip');
        $_ENV['TEST_IP_LIST'] = '127.0.0.1, not-an-ip';
        $_SERVER['TEST_IP_LIST'] = '127.0.0.1, not-an-ip';

        $v = new EnvValidator();

        $rm = $this->rm('ipList');
        $rm->invoke($v, 'TEST_IP_LIST', false);

        $errors = $this->errorsOf($v);

        $this->assertNotEmpty($errors, 'ipList() ska ge fel när listan innehåller ett ogiltigt item.');
        $this->assertStringContainsString(
            'TEST_IP_LIST contains invalid IP/CIDR: not-an-ip',
            implode("\n", $errors),
            'Felmeddelandet ska innehålla det trimmade ogiltiga itemet.'
        );

        // Viktigt för att döda NotIdentical/UnwrapArrayFilter-varianter:
        // vi ska INTE få fel på tomma segment här (vi har inga), och vi vill se exakt "not-an-ip".
        $this->assertStringNotContainsString(
            "invalid IP/CIDR: ",
            implode("\n", array_filter($errors, static fn(string $e): bool => str_contains($e, 'invalid IP/CIDR: ') && !str_contains($e, 'not-an-ip'))),
            'ipList() ska inte rapportera något annat "invalid IP/CIDR" än just not-an-ip i detta test.'
        );
    }

    public function testIpListDoesNotReportEmptyItemsWhenThereAreDoubleCommas(): void
    {
        // Dödar 42 NotIdentical (om filtret blir === '' så skulle vi bara iterera tomma items och ALDRIG se not-an-ip).
        putenv('TEST_IP_LIST=127.0.0.1,,not-an-ip');
        $_ENV['TEST_IP_LIST'] = '127.0.0.1,,not-an-ip';
        $_SERVER['TEST_IP_LIST'] = '127.0.0.1,,not-an-ip';

        $v = new EnvValidator();

        $rm = $this->rm('ipList');
        $rm->invoke($v, 'TEST_IP_LIST', false);

        $errors = $this->errorsOf($v);

        $this->assertNotEmpty($errors);
        $all = implode("\n", $errors);

        $this->assertStringContainsString(
            'TEST_IP_LIST contains invalid IP/CIDR: not-an-ip',
            $all,
            'ipList() ska rapportera not-an-ip även om det finns tomma segment i listan.'
        );

        $this->assertStringNotContainsString(
            'TEST_IP_LIST contains invalid IP/CIDR: ',
            implode("\n", array_filter($errors, static fn(string $e): bool => $e === 'TEST_IP_LIST contains invalid IP/CIDR: ')),
            'ipList() ska inte rapportera ett "tomt" invalid-item.'
        );
    }

    public function testIsValidIpOrCidrAcceptsIpv4PrefixZeroAndThirtyTwoAndRejectsThirtyThree(): void
    {
        // Dödar 49/50/51 (gränser) + 52 (ReturnRemoval) + 47/48 (v4-detektering påverkar branch).
        $v = new EnvValidator();
        $rm = new ReflectionMethod(EnvValidator::class, 'isValidIpOrCidr');
        $rm->setAccessible(true);

        $this->assertTrue(
            (bool) $rm->invoke($v, '198.51.100.0/0'),
            'IPv4 CIDR med /0 ska vara giltig.'
        );

        $this->assertTrue(
            (bool) $rm->invoke($v, '198.51.100.42/32'),
            'IPv4 CIDR med /32 ska vara giltig.'
        );

        $this->assertFalse(
            (bool) $rm->invoke($v, '198.51.100.0/33'),
            'IPv4 CIDR med /33 ska vara ogiltig.'
        );
    }

    public function testIsValidIpOrCidrAcceptsIpv6PrefixZeroAndOneTwoEightAndRejectsOneTwoNine(): void
    {
        // Dödar v6-gränserna i sista return-satsen och säkerställer att v4-branch inte körs för IPv6.
        $v = new EnvValidator();
        $rm = new ReflectionMethod(EnvValidator::class, 'isValidIpOrCidr');
        $rm->setAccessible(true);

        $this->assertTrue(
            (bool) $rm->invoke($v, '2001:db8::/0'),
            'IPv6 CIDR med /0 ska vara giltig.'
        );

        $this->assertTrue(
            (bool) $rm->invoke($v, '2001:db8::1/128'),
            'IPv6 CIDR med /128 ska vara giltig.'
        );

        $this->assertFalse(
            (bool) $rm->invoke($v, '2001:db8::/129'),
            'IPv6 CIDR med /129 ska vara ogiltig.'
        );
    }

    public function testIsValidIpOrCidrTreatsIpv4AsIpv4AndIpv6AsIpv6(): void
    {
        // Dödar 47/48: om isV4-flippen/negationen sker kan IPv6 felaktigt bedömas i IPv4-branch eller tvärtom.
        $v = new EnvValidator();
        $rm = new ReflectionMethod(EnvValidator::class, 'isValidIpOrCidr');
        $rm->setAccessible(true);

        // IPv4 med /32 ska vara OK, men det kräver att den går i IPv4-branch.
        $this->assertTrue(
            (bool) $rm->invoke($v, '127.0.0.1/32'),
            'IPv4 ska hanteras som IPv4.'
        );

        // IPv6 med /128 ska vara OK, men det kräver att den INTE går i IPv4-branch.
        $this->assertTrue(
            (bool) $rm->invoke($v, '::1/128'),
            'IPv6 ska hanteras som IPv6.'
        );
    }

    public function testIsValidIpOrCidrRejectsValuesWithExtraSlashAfterPrefix(): void
    {
        // Dödar mutant 42: explode('/', $value, 2) -> explode('/', $value, 3)
        // "ip/24/extra" ska vara ogiltigt (prefixRaw får INTE tappa "/extra").
        $v = new EnvValidator();
        $rm = new ReflectionMethod(EnvValidator::class, 'isValidIpOrCidr');
        $rm->setAccessible(true);

        $this->assertFalse(
            (bool) $rm->invoke($v, '198.51.100.0/24/extra'),
            'CIDR med extra "/" efter prefix ska vara ogiltig.'
        );
    }

    public function testIsValidIpOrCidrTrimsIpPartBeforeValidation(): void
    {
        // Dödar mutant 43: $ip = trim($ip) tas bort.
        $v = new EnvValidator();
        $rm = new ReflectionMethod(EnvValidator::class, 'isValidIpOrCidr');
        $rm->setAccessible(true);

        $this->assertTrue(
            (bool) $rm->invoke($v, ' 127.0.0.1 /32'),
            'Whitespace runt IP-delen ska trimmas och fortfarande vara giltigt.'
        );
    }

    public function testIsValidIpOrCidrTrimsPrefixPartBeforeDigitCheck(): void
    {
        // Dödar mutant 44: $prefixRaw = trim($prefixRaw) tas bort.
        $v = new EnvValidator();
        $rm = new ReflectionMethod(EnvValidator::class, 'isValidIpOrCidr');
        $rm->setAccessible(true);

        $this->assertTrue(
            (bool) $rm->invoke($v, '127.0.0.1/ 32 '),
            'Whitespace runt prefix ska trimmas och fortfarande vara giltigt.'
        );
    }

    public function testIsValidIpOrCidrRejectsNonDigitPrefix(): void
    {
        // Dödar mutant 45: ($prefixRaw === '' || !ctype_digit($prefixRaw)) -> (&&)
        $v = new EnvValidator();
        $rm = new ReflectionMethod(EnvValidator::class, 'isValidIpOrCidr');
        $rm->setAccessible(true);

        $this->assertFalse(
            (bool) $rm->invoke($v, '127.0.0.1/xx'),
            'Prefix måste vara numeriskt; "xx" ska vara ogiltigt.'
        );

        $this->assertFalse(
            (bool) $rm->invoke($v, '127.0.0.1/'),
            'Tomt prefix ska vara ogiltigt.'
        );
    }

    public function testCookieNameDefaultDoesNotAllowEmpty(): void
    {
        // Dödar mutant där default allowEmpty ändras från false till true.
        putenv('SESSION_COOKIE_NAME=');
        unset($_ENV['SESSION_COOKIE_NAME'], $_SERVER['SESSION_COOKIE_NAME']);

        $v = new EnvValidator();

        $rm = $this->rm('cookieName');
        $rm->invoke($v, 'SESSION_COOKIE_NAME');

        $errors = $this->errorsOf($v);

        $this->assertNotEmpty(
            $errors,
            'cookieName() ska ge fel för tomt värde när allowEmpty inte anges.'
        );
    }

    public function testCookieNameAllowEmptyTrueSkipsOnlyWhenValueIsEmpty(): void
    {
        // Dödar mutanter som ändrar:
        // if ($v === '' && $allowEmpty)
        // till varianter som returnerar vid fel tillfälle.

        $rm = $this->rm('cookieName');

        // 1) allowEmpty=true + tomt värde => ska accepteras.
        $v1 = new EnvValidator();

        putenv('SESSION_COOKIE_NAME=');
        unset($_ENV['SESSION_COOKIE_NAME'], $_SERVER['SESSION_COOKIE_NAME']);

        $rm->invoke($v1, 'SESSION_COOKIE_NAME', true);

        $errors1 = $this->errorsOf($v1);

        $this->assertSame(
            [],
            $errors1,
            'cookieName() ska acceptera tomt värde när allowEmpty=true.'
        );

        // 2) allowEmpty=true + icke-tomt ogiltigt värde => ska fortfarande valideras.
        $v2 = new EnvValidator();

        putenv('SESSION_COOKIE_NAME=bad cookie name');
        $_ENV['SESSION_COOKIE_NAME'] = 'bad cookie name';
        $_SERVER['SESSION_COOKIE_NAME'] = 'bad cookie name';

        $rm->invoke($v2, 'SESSION_COOKIE_NAME', true);

        $errors2 = $this->errorsOf($v2);

        $this->assertNotEmpty(
            $errors2,
            'cookieName() ska validera icke-tomma värden även när allowEmpty=true.'
        );

        $this->assertSame(
            'SESSION_COOKIE_NAME must be a valid cookie name',
            $errors2[0]
        );
    }

    public function testCookieNameAllowEmptyFalseStillValidatesEmptyAsError(): void
    {
        // Dödar mutant:
        // if ($v === '' && $allowEmpty)
        // -> if ($v === '' && !$allowEmpty)
        putenv('SESSION_COOKIE_NAME=');
        unset($_ENV['SESSION_COOKIE_NAME'], $_SERVER['SESSION_COOKIE_NAME']);

        $v = new EnvValidator();

        $rm = $this->rm('cookieName');
        $rm->invoke($v, 'SESSION_COOKIE_NAME', false);

        $errors = $this->errorsOf($v);

        $this->assertNotEmpty(
            $errors,
            'cookieName() ska ge fel för tomt värde när allowEmpty=false.'
        );

        $this->assertSame(
            'SESSION_COOKIE_NAME must be a valid cookie name',
            $errors[0]
        );
    }

    public function testCookieNameStopsAfterEmptyValueSoItDoesNotAddDuplicateErrors(): void
    {
        // Dödar ReturnRemoval-mutanten efter:
        // if ($v === '') { ... return; }
        putenv('SESSION_COOKIE_NAME=');
        unset($_ENV['SESSION_COOKIE_NAME'], $_SERVER['SESSION_COOKIE_NAME']);

        $v = new EnvValidator();

        $rm = $this->rm('cookieName');
        $rm->invoke($v, 'SESSION_COOKIE_NAME', false);

        $errors = $this->errorsOf($v);

        $this->assertCount(
            1,
            $errors,
            'cookieName() ska bara lägga ett fel för tomt värde och sedan returnera.'
        );

        $this->assertSame(
            'SESSION_COOKIE_NAME must be a valid cookie name',
            $errors[0]
        );
    }

    public function testCookieNameAcceptsValidSessionCookieNames(): void
    {
        $rm = $this->rm('cookieName');

        foreach (['radix_session', '__Host-radix_session', '__Secure-radix_session'] as $name) {
            $v = new EnvValidator();

            putenv('SESSION_COOKIE_NAME=' . $name);
            $_ENV['SESSION_COOKIE_NAME'] = $name;
            $_SERVER['SESSION_COOKIE_NAME'] = $name;

            $rm->invoke($v, 'SESSION_COOKIE_NAME', false);

            $this->assertSame(
                [],
                $this->errorsOf($v),
                "{$name} ska vara ett giltigt cookie-namn."
            );
        }
    }

    public function testCookieNameRejectsInvalidCharacters(): void
    {
        $rm = $this->rm('cookieName');

        foreach (['bad cookie name', 'bad;cookie', 'bad=cookie', 'bad,cookie'] as $name) {
            $v = new EnvValidator();

            putenv('SESSION_COOKIE_NAME=' . $name);
            $_ENV['SESSION_COOKIE_NAME'] = $name;
            $_SERVER['SESSION_COOKIE_NAME'] = $name;

            $rm->invoke($v, 'SESSION_COOKIE_NAME', false);

            $errors = $this->errorsOf($v);

            $this->assertNotEmpty(
                $errors,
                "{$name} ska nekas som cookie-namn."
            );

            $this->assertSame(
                'SESSION_COOKIE_NAME must be a valid cookie name',
                $errors[0]
            );
        }
    }

}
