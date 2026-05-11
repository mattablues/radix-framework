<?php

declare(strict_types=1);

namespace Radix\Tests\Config;

use PHPUnit\Framework\TestCase;
use Radix\Config\EnvValidator;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

final class EnvValidatorTest extends TestCase
{
    /** @var list<string> */
    private array $touched = [];

    protected function tearDown(): void
    {
        // Återställ alla env-variabler vi rörde
        foreach (array_unique($this->touched) as $k) {
            putenv($k);
            unset($_ENV[$k], $_SERVER[$k]);
        }
        $this->touched = [];
    }

    private function setEnv(string $key, string $value): void
    {
        $this->touched[] = $key;
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    private function seedCommonEnv(string $appEnv): void
    {
        // Dummy
        $this->setEnv('APP_ENV', $appEnv);
        $this->setEnv('APP_URL', 'http://localhost');
        $this->setEnv('APP_LANG', 'sv');
        $this->setEnv('APP_NAME', 'Radix System');
        $this->setEnv('APP_TIMEZONE', 'Europe/Stockholm');
        $this->setEnv('APP_COPY', 'Ditt Företag');
        $this->setEnv('APP_COPY_YEAR', '2026');

        $this->setEnv('APP_DEBUG', '1');
        $this->setEnv('APP_MAINTENANCE', '0');
        $this->setEnv('APP_PRIVATE', '0');

        // Locator
        $this->setEnv('LOCATOR_COUNTRY', 'SE');
        $this->setEnv('LOCATOR_CITY', 'Stockholm');
        $this->setEnv('LOCATOR_CITY_URL', 'https://example.com/city');

        // GeoLocator
        $this->setEnv('GEOLOCATOR_ENABLED', '0');
        $this->setEnv('GEOLOCATOR_BASE_URL', 'http://ip-api.com/json');
        $this->setEnv('GEOLOCATOR_TIMEOUT', '2');

        // CORS
        $this->setEnv('CORS_ALLOW_ORIGIN', 'http://localhost');
        $this->setEnv('CORS_ALLOW_CREDENTIALS', '1');

        // Health / API
        $this->setEnv('HEALTH_REQUIRE_TOKEN', '0');
        $this->setEnv('API_TOKEN', 'dummy-token'); // irrelevant när HEALTH_REQUIRE_TOKEN=0
        $this->setEnv('HEALTH_IP_ALLOWLIST', '127.0.0.1,::1');
        $this->setEnv('TRUSTED_PROXY', ''); // får vara tom

        // ORM
        $this->setEnv('ORM_MODEL_NAMESPACE', 'Dummy\\Models\\');

        // DB
        $this->setEnv('DB_DRIVER', 'mysql');
        $this->setEnv('DB_HOST', '127.0.0.1');
        $this->setEnv('DB_PORT', '3306');
        $this->setEnv('DB_NAME', 'radix');
        $this->setEnv('DB_USERNAME', 'root');
        $this->setEnv('DB_PASSWORD', '');
        $this->setEnv('DB_CHARSET', 'utf8mb4');

        // Session
        $this->setEnv('SESSION_DRIVER', 'file');
        $this->setEnv('SESSION_FILE_PATH', 'cache/sessions');
        $this->setEnv('SESSION_TABLE', 'sessions');
        $this->setEnv('SESSION_LIFETIME', '1440');
        $this->setEnv('SESSION_COOKIE_SECURE', 'auto');
        $this->setEnv('SESSION_COOKIE_HTTPONLY', 'true');
        $this->setEnv('SESSION_COOKIE_SAMESITE', 'Lax');

        // Keys
        $this->setEnv('SECURE_TOKEN_HMAC', 'dummy-hmac-key-very-long');
        $this->setEnv('SECURE_ENCRYPTION_KEY', 'dummy-encryption-key-very-long-32-chars');

        // Cache paths
        $this->setEnv('CACHE_ROOT', 'cache');
        $this->setEnv('VIEWS_CACHE_PATH', 'cache/views');
        $this->setEnv('APP_CACHE_PATH', 'cache/app');
        $this->setEnv('HEALTH_CACHE_PATH', 'cache/health');
        $this->setEnv('RATELIMIT_CACHE_PATH', 'cache/ratelimit');

        // Mail
        $this->setEnv('MAIL_DEBUG', '0');
        $this->setEnv('MAIL_CHARSET', 'UTF-8');
        $this->setEnv('MAIL_HOST', 'smtp.example.com');
        $this->setEnv('MAIL_PORT', '2525');
        $this->setEnv('MAIL_SECURE', 'tls');
        $this->setEnv('MAIL_AUTH', '0');
        $this->setEnv('MAIL_ACCOUNT', '');   // får vara tom när MAIL_AUTH=0
        $this->setEnv('MAIL_PASSWORD', '');  // får vara tom när MAIL_AUTH=0
        $this->setEnv('MAIL_EMAIL', 'noreply@example.com');
        $this->setEnv('MAIL_FROM', 'Radix System');
    }

    public function testValidatePassesInDevelopmentWithMinimalConfig(): void
    {
        $this->seedCommonEnv('development');

        // Tillåt tomma “prod-only”-grejer i development
        $this->setEnv('ORM_MODEL_NAMESPACE', '');
        $this->setEnv('HEALTH_IP_ALLOWLIST', '');

        $v = new EnvValidator();
        $v->validate(basePath: rtrim(sys_get_temp_dir(), "/\\"));

        $this->assertSame('development', getenv('APP_ENV'));
    }

    public function testValidateFailsInProductionWhenProductionOnlyMissing(): void
    {
        $this->seedCommonEnv('production');

        // Production-only saknas
        $this->setEnv('ORM_MODEL_NAMESPACE', '');
        $this->setEnv('HEALTH_IP_ALLOWLIST', '');

        $v = new EnvValidator();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid environment configuration:');

        $v->validate(basePath: rtrim(sys_get_temp_dir(), "/\\"));
    }

    public function testValidateExceptionMessageIncludesConcreteErrors(): void
    {
        // Dödar 82: om implode-biten tas bort innehåller meddelandet inga faktiska felrader.
        $this->seedCommonEnv('production');

        // Se till att minst ett fel uppstår på ett deterministiskt sätt
        $this->setEnv('ORM_MODEL_NAMESPACE', '');
        $this->setEnv('HEALTH_IP_ALLOWLIST', '');

        $v = new EnvValidator();

        try {
            $v->validate(basePath: rtrim(sys_get_temp_dir(), "/\\"));
            $this->fail('Expected exception not thrown.');
        } catch (RuntimeException $e) {
            $this->assertStringStartsWith(
                "Invalid environment configuration:\n - ",
                $e->getMessage(),
                'Meddelandet ska börja med prefixen.'
            );

            $this->assertStringContainsString(
                'ORM_MODEL_NAMESPACE is required in production',
                $e->getMessage(),
                'Meddelandet ska innehålla minst ett konkret fel från errors-listan.'
            );
        }
    }

    public function testGetTrimsEnvironmentValues(): void
    {
        // Dödar 83: get() måste trimma (annars blir det whitespace kvar).
        $this->setEnv('TEST_TRIM', "   value-with-spaces   ");

        $v = new EnvValidator();

        $rm = new ReflectionMethod(EnvValidator::class, 'get');
        $rm->setAccessible(true);

        $value = $rm->invoke($v, 'TEST_TRIM');
        $this->assertIsString($value);

        $this->assertSame(
            'value-with-spaces',
            $value,
            'get() ska trimma värdet från miljön.'
        );
    }

    public function testIsProductionIsCaseInsensitive(): void
    {
        // Dödar 84: isProduction() måste lowercasa APP_ENV.
        $this->setEnv('APP_ENV', 'PrOdUcTiOn');

        $v = new EnvValidator();

        $rm = new ReflectionMethod(EnvValidator::class, 'isProduction');
        $rm->setAccessible(true);

        $this->assertTrue(
            (bool) $rm->invoke($v),
            'isProduction() ska vara case-insensitive.'
        );
    }

    public function testRequireIfBoolTrueAcceptsUppercaseTrue(): void
    {
        // Dödar 85: requireIfBoolTrue() måste lowercasa bool-värdet så "TRUE" triggar.
        $this->setEnv('TEST_BOOL', 'TRUE');
        $this->setEnv('TEST_REQUIRED', '');

        $v = new EnvValidator();

        $rm = new ReflectionMethod(EnvValidator::class, 'requireIfBoolTrue');
        $rm->setAccessible(true);

        $rm->invoke($v, 'TEST_BOOL', 'TEST_REQUIRED');

        $rp = new ReflectionProperty(EnvValidator::class, 'errors');
        $rp->setAccessible(true);

        /** @var array<int, string> $errors */
        $errors = $rp->getValue($v);

        $this->assertNotEmpty(
            $errors,
            'requireIfBoolTrue() ska kräva requiredKey när boolKey är TRUE (case-insensitive).'
        );
    }

    public function testRequireIfEqualsIsCaseInsensitiveForEqualsArgument(): void
    {
        // Dödar 86: requireIfEquals() måste lowercasa $equals också, inte bara env-värdet.
        $this->setEnv('TEST_DRIVER', 'file');
        $this->setEnv('TEST_REQUIRED', '');

        $v = new EnvValidator();

        $rm = new ReflectionMethod(EnvValidator::class, 'requireIfEquals');
        $rm->setAccessible(true);

        $rm->invoke($v, 'TEST_DRIVER', 'FILE', 'TEST_REQUIRED');

        $rp = new ReflectionProperty(EnvValidator::class, 'errors');
        $rp->setAccessible(true);

        /** @var array<int, string> $errors */
        $errors = $rp->getValue($v);

        $this->assertNotEmpty(
            $errors,
            'requireIfEquals() ska trigga även när $equals har annan casing.'
        );
    }

    public function testRequireIfEqualsIsCaseInsensitiveForValue(): void
    {
        // Dödar 78: om strtolower($this->get($key)) tas bort ska detta sluta trigga.
        $this->setEnv('TEST_DRIVER', 'FILE');
        $this->setEnv('TEST_REQUIRED', '');

        $v = new EnvValidator();

        $rm = new ReflectionMethod(EnvValidator::class, 'requireIfEquals');
        $rm->setAccessible(true);

        $rm->invoke($v, 'TEST_DRIVER', 'file', 'TEST_REQUIRED');

        $rp = new ReflectionProperty(EnvValidator::class, 'errors');
        $rp->setAccessible(true);

        /** @var array<int, string> $errors */
        $errors = $rp->getValue($v);

        $this->assertNotEmpty($errors, 'requireIfEquals() ska matcha FILE mot file (case-insensitive).');
    }
}
