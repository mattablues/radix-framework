<?php

declare(strict_types=1);

namespace Radix\Config {
    function mkdir(string $directory, int $permissions = 0o777, bool $recursive = false, mixed $context = null): bool
    {
        \Radix\Tests\Config\EnvValidatorFsSpy::$lastMkdirPermissions = $permissions;
        \Radix\Tests\Config\EnvValidatorFsSpy::$lastMkdirPath = $directory;
        \Radix\Tests\Config\EnvValidatorFsSpy::$mkdirPaths[] = $directory;
        /** @var resource|null $context */
        return \mkdir($directory, $permissions, $recursive, $context);
    }
}

namespace Radix\Tests\Config {

    use PHPUnit\Framework\TestCase;
    use Radix\Config\Dotenv;
    use Radix\Config\EnvValidator;
    use RuntimeException;

    final class EnvValidatorFsSpy
    {
        public static ?int $lastMkdirPermissions = null;
        public static ?string $lastMkdirPath = null;
        /** @var list<string> */
        public static array $mkdirPaths = [];
    }

    final class EnvValidatorIntegrationTest extends TestCase
    {
        private ?string $tmpEnvPath = null;

        protected function tearDown(): void
        {
            if (class_exists(DotenvFileSpy::class)) {
                DotenvFileSpy::reset();
            }

            if (is_string($this->tmpEnvPath) && file_exists($this->tmpEnvPath)) {
                @unlink($this->tmpEnvPath);
            }
            $this->tmpEnvPath = null;

            EnvValidatorFsSpy::$lastMkdirPermissions = null;
            EnvValidatorFsSpy::$lastMkdirPath = null;
            EnvValidatorFsSpy::$mkdirPaths = [];

            // Städa upp env som kan läcka mellan tester
            foreach (
                [
                    'APP_ENV', 'APP_URL', 'APP_LANG', 'APP_NAME', 'APP_TIMEZONE', 'APP_COPY', 'APP_COPY_YEAR',
                    'APP_DEBUG', 'APP_MAINTENANCE', 'APP_PRIVATE',
                    'LOCATOR_COUNTRY', 'LOCATOR_CITY', 'LOCATOR_CITY_URL',
                    'CORS_ALLOW_ORIGIN', 'CORS_ALLOW_CREDENTIALS',
                    'HEALTH_REQUIRE_TOKEN', 'API_TOKEN', 'HEALTH_IP_ALLOWLIST', 'TRUSTED_PROXY',
                    'ORM_MODEL_NAMESPACE',
                    'DB_DRIVER', 'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USERNAME', 'DB_PASSWORD', 'DB_CHARSET',
                    'SESSION_DRIVER', 'SESSION_FILE_PATH', 'SESSION_TABLE', 'SESSION_LIFETIME',
                    'SESSION_COOKIE_SECURE', 'SESSION_COOKIE_HTTPONLY', 'SESSION_COOKIE_SAMESITE',
                    'SECURE_TOKEN_HMAC', 'SECURE_ENCRYPTION_KEY',
                    'CACHE_ROOT', 'VIEWS_CACHE_PATH', 'APP_CACHE_PATH', 'HEALTH_CACHE_PATH', 'RATELIMIT_CACHE_PATH',
                    'MAIL_HOST', 'MAIL_PORT', 'MAIL_EMAIL', 'MAIL_FROM', 'MAIL_DEBUG', 'MAIL_CHARSET', 'MAIL_SECURE',
                    'MAIL_AUTH', 'MAIL_ACCOUNT', 'MAIL_PASSWORD',
                ] as $k
            ) {
                putenv($k);
                unset($_ENV[$k], $_SERVER[$k]);
            }
        }

        private function writeTempEnv(string $contents): string
        {
            $path = tempnam(sys_get_temp_dir(), 'radix-envvalidator-');
            $this->assertIsString($path, 'Kunde inte skapa temporär .env-fil.');

            $ok = file_put_contents($path, $contents);
            $this->assertNotFalse($ok, 'Kunde inte skriva temporär .env-fil.');

            $this->tmpEnvPath = $path;
            return $path;
        }

        private function baseEnv(string $appEnv, string $ormNamespace, string $healthAllowlist): string
        {
            // Bygg en minimal men komplett .env som matchar din EnvValidator
            return
                "APP_ENV={$appEnv}\n"
                . "APP_URL=http://localhost\n"
                . "APP_LANG=sv\n"
                . "APP_NAME=Radix System\n"
                . "APP_TIMEZONE=Europe/Stockholm\n"
                . "APP_COPY=Ditt Företag\n"
                . "APP_COPY_YEAR=2026\n"
                . "APP_DEBUG=1\n"
                . "APP_MAINTENANCE=0\n"
                . "APP_PRIVATE=0\n"
                . "LOCATOR_COUNTRY=SE\n"
                . "LOCATOR_CITY=Stockholm\n"
                . "LOCATOR_CITY_URL=https://example.com/city\n"
                . "CORS_ALLOW_ORIGIN=http://localhost\n"
                . "CORS_ALLOW_CREDENTIALS=1\n"
                . "HEALTH_REQUIRE_TOKEN=0\n"
                . "API_TOKEN=dummy-token\n"
                . "HEALTH_IP_ALLOWLIST={$healthAllowlist}\n"
                . "TRUSTED_PROXY=\n"
                . "ORM_MODEL_NAMESPACE={$ormNamespace}\n"
                . "DB_DRIVER=mysql\n"
                . "DB_HOST=127.0.0.1\n"
                . "DB_PORT=3306\n"
                . "DB_NAME=radix\n"
                . "DB_USERNAME=root\n"
                . "DB_PASSWORD=\n"
                . "DB_CHARSET=utf8mb4\n"
                . "SESSION_DRIVER=file\n"
                . "SESSION_FILE_PATH=cache/sessions\n"
                . "SESSION_TABLE=sessions\n"
                . "SESSION_LIFETIME=1440\n"
                . "SESSION_COOKIE_SECURE=auto\n"
                . "SESSION_COOKIE_HTTPONLY=true\n"
                . "SESSION_COOKIE_SAMESITE=Lax\n"
                . "SECURE_TOKEN_HMAC=dummy-hmac-key-very-long\n"
                . "SECURE_ENCRYPTION_KEY=dummy-encryption-key-very-long-32-chars\n"
                . "CACHE_ROOT=cache\n"
                . "VIEWS_CACHE_PATH=cache/views\n"
                . "APP_CACHE_PATH=cache/app\n"
                . "HEALTH_CACHE_PATH=cache/health\n"
                . "RATELIMIT_CACHE_PATH=cache/ratelimit\n"
                . "MAIL_HOST=smtp.example.com\n"
                . "MAIL_PORT=2525\n"
                . "MAIL_EMAIL=noreply@example.com\n"
                . "MAIL_FROM=Radix System\n"
                . "MAIL_DEBUG=0\n"
                . "MAIL_CHARSET=UTF-8\n"
                . "MAIL_SECURE=tls\n"
                . "MAIL_AUTH=0\n"
                . "MAIL_ACCOUNT=\n"
                . "MAIL_PASSWORD=\n";
        }

        public function testLoadsDotenvAndValidatesInDevelopmentEvenIfProdOnlyValuesAreEmpty(): void
        {
            $envFile = $this->writeTempEnv(
                $this->baseEnv(
                    appEnv: 'development',
                    ormNamespace: '',
                    healthAllowlist: ''
                )
            );

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            $dotenv = new Dotenv($envFile, $basePath);
            $dotenv->load();

            (new EnvValidator())->validate($basePath);

            $this->assertSame('development', getenv('APP_ENV'));
        }

        public function testFailsInProductionWhenProdOnlyValuesMissing(): void
        {
            $envFile = $this->writeTempEnv(
                $this->baseEnv(
                    appEnv: 'production',
                    ormNamespace: '',
                    healthAllowlist: ''
                )
            );

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            $dotenv = new Dotenv($envFile, $basePath);
            $dotenv->load();

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Invalid environment configuration:');

            (new EnvValidator())->validate($basePath);
        }

        public function testFailsWhenHealthRequiresTokenButApiTokenMissing(): void
        {
            $env = $this->baseEnv(
                appEnv: 'development',
                ormNamespace: '',
                healthAllowlist: ''
            );

            $env = str_replace("HEALTH_REQUIRE_TOKEN=0\n", "HEALTH_REQUIRE_TOKEN=1\n", $env);
            $env = str_replace("API_TOKEN=dummy-token\n", "API_TOKEN=\n", $env);

            $envFile = $this->writeTempEnv($env);

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            $dotenv = new Dotenv($envFile, $basePath);
            $dotenv->load();

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Invalid environment configuration:');

            (new EnvValidator())->validate($basePath);
        }

        public function testRelativeCachePathsAreCreatedUnderBasePath(): void
        {
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');

            $uniq = 'p_' . preg_replace('/[^a-z0-9_]+/i', '_', uniqid('', true));
            $env = str_replace("VIEWS_CACHE_PATH=cache/views\n", "VIEWS_CACHE_PATH=cache/views_{$uniq}\n", $env);
            $env = str_replace("APP_CACHE_PATH=cache/app\n", "APP_CACHE_PATH=cache/app_{$uniq}\n", $env);
            $env = str_replace("HEALTH_CACHE_PATH=cache/health\n", "HEALTH_CACHE_PATH=cache/health_{$uniq}\n", $env);
            $env = str_replace(
                "RATELIMIT_CACHE_PATH=cache/ratelimit\n",
                "RATELIMIT_CACHE_PATH=cache/ratelimit_{$uniq}\n",
                $env
            );

            $envFile = $this->writeTempEnv($env);

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_base_' . uniqid(
                '',
                true
            );
            @mkdir($basePath, 0o755, true);

            $dotenv = new Dotenv($envFile, $basePath);
            $dotenv->load();

            (new EnvValidator())->validate($basePath);

            $this->assertDirectoryExists($basePath . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . "views_{$uniq}");
            $this->assertDirectoryExists($basePath . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . "app_{$uniq}");
            $this->assertDirectoryExists($basePath . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . "health_{$uniq}");
            $this->assertDirectoryExists($basePath . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . "ratelimit_{$uniq}");
        }

        public function testAbsoluteCachePathIsUsedAsIs(): void
        {
            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_base_' . uniqid(
                '',
                true
            );
            @mkdir($basePath, 0o755, true);

            $absViews = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_abs_views_' . uniqid(
                '',
                true
            );

            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');
            $env = str_replace("VIEWS_CACHE_PATH=cache/views\n", "VIEWS_CACHE_PATH={$absViews}\n", $env);

            $envFile = $this->writeTempEnv($env);

            $dotenv = new Dotenv($envFile, $basePath);
            $dotenv->load();

            (new EnvValidator())->validate($basePath);

            $this->assertDirectoryExists($absViews);
            $this->assertDirectoryDoesNotExist($basePath . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'views');
        }

        public function testLeadingSlashOrBackslashIsTrimmedForRelativePaths(): void
        {
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');

            $uniq = 'lead_' . preg_replace('/[^a-z0-9_]+/i', '_', uniqid('', true));
            $env = str_replace("VIEWS_CACHE_PATH=cache/views\n", "VIEWS_CACHE_PATH=\\cache\\views_{$uniq}\n", $env);

            $envFile = $this->writeTempEnv($env);

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_base_' . uniqid(
                '',
                true
            );
            @mkdir($basePath, 0o755, true);

            $dotenv = new Dotenv($envFile, $basePath);
            $dotenv->load();

            (new EnvValidator())->validate($basePath);

            $expected = $basePath . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . "views_{$uniq}";
            $this->assertDirectoryExists($expected);
        }

        public function testFailsWhenCachePathPointsToFileInsteadOfDirectory(): void
        {
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');

            $uniq = 'file_' . preg_replace('/[^a-z0-9_]+/i', '_', uniqid('', true));
            $env = str_replace("VIEWS_CACHE_PATH=cache/views\n", "VIEWS_CACHE_PATH=cache/views_{$uniq}\n", $env);

            $envFile = $this->writeTempEnv($env);

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_base_' . uniqid(
                '',
                true
            );
            @mkdir($basePath, 0o755, true);

            // Skapa en FIL där EnvValidator förväntar sig en katalog
            $asFile = $basePath . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . "views_{$uniq}";
            @mkdir(dirname($asFile), 0o755, true);
            file_put_contents($asFile, 'not a dir');

            $dotenv = new Dotenv($envFile, $basePath);
            $dotenv->load();

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Invalid environment configuration:');

            (new EnvValidator())->validate($basePath);
        }

        public function testCreatesDirectoriesWith0755OnUnixWhenUmaskIsZero(): void
        {
            // Nu kör vi även på Windows (ingen skip) och verifierar permissions-argumentet deterministiskt.
            $oldUmask = umask(0);
            try {
                $uniq = 'perm_' . preg_replace('/[^a-z0-9_]+/i', '_', uniqid('', true));

                $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');
                $env = str_replace("VIEWS_CACHE_PATH=cache/views\n", "VIEWS_CACHE_PATH=cache/views_{$uniq}\n", $env);

                $envFile = $this->writeTempEnv($env);

                $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_base_' . uniqid(
                    '',
                    true
                );
                @mkdir($basePath, 0o755, true);

                $dotenv = new Dotenv($envFile, $basePath);
                $dotenv->load();

                (new EnvValidator())->validate($basePath);

                $dir = $basePath . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . "views_{$uniq}";
                $this->assertDirectoryExists($dir);

                $this->assertSame(
                    0o755,
                    EnvValidatorFsSpy::$lastMkdirPermissions,
                    'EnvValidator ska anropa mkdir() med permissions 0755.'
                );
            } finally {
                umask($oldUmask);
            }
        }

        public function testWritablePathBuildsAbsolutePathUsingRtrimAndLtrim(): void
        {
            $uniq = 'spy_' . preg_replace('/[^a-z0-9_]+/i', '_', uniqid('', true));

            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');

            $env = str_replace(
                "VIEWS_CACHE_PATH=cache/views\n",
                "VIEWS_CACHE_PATH=\\cache\\views_{$uniq}\n",
                $env
            );

            $envFile = $this->writeTempEnv($env);

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_base_' . uniqid('', true) . DIRECTORY_SEPARATOR;
            @mkdir($basePath, 0o755, true);

            $dotenv = new Dotenv($envFile, $basePath);
            $dotenv->load();

            (new EnvValidator())->validate($basePath);

            $expected = rtrim($basePath, "/\\")
                . DIRECTORY_SEPARATOR
                . 'cache'
                . DIRECTORY_SEPARATOR
                . "views_{$uniq}";

            $this->assertContains(
                $expected,
                EnvValidatorFsSpy::$mkdirPaths,
                'EnvValidator ska bygga sökvägen med rtrim(basePath) och ltrim(value).'
            );

            $this->assertDirectoryExists($expected);
        }

        public function testSessionFilePathIsValidatedWhenSessionDriverIsFileCaseInsensitive(): void
        {
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');

            $uniq = 'sess_' . preg_replace('/[^a-z0-9_]+/i', '_', uniqid('', true));
            $env = str_replace("SESSION_DRIVER=file\n", "SESSION_DRIVER=FILE\n", $env);

            $relSessionPath = "cache/sessions_{$uniq}\n";
            $env = str_replace("SESSION_FILE_PATH=cache/sessions\n", "SESSION_FILE_PATH={$relSessionPath}", $env);

            $envFile = $this->writeTempEnv($env);

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_base_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            $dotenv = new Dotenv($envFile, $basePath);
            $dotenv->load();

            (new EnvValidator())->validate($basePath);

            $expectedRel = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, "cache/sessions_{$uniq}");

            $expected = rtrim($basePath, "/\\") . DIRECTORY_SEPARATOR . ltrim($expectedRel, "/\\");

            $this->assertContains(
                $expected,
                EnvValidatorFsSpy::$mkdirPaths,
                'När SESSION_DRIVER=file (case-insensitive) ska EnvValidator validera/skapa SESSION_FILE_PATH.'
            );
            $this->assertDirectoryExists($expected);
        }

        public function testSessionFilePathIsIgnoredWhenSessionDriverIsNotFile(): void
        {
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');

            $uniq = 'sess_ignore_' . preg_replace('/[^a-z0-9_]+/i', '_', uniqid('', true));
            $env = str_replace("SESSION_DRIVER=file\n", "SESSION_DRIVER=database\n", $env);

            $relSessionPath = "cache/sessions_{$uniq}\n";
            $env = str_replace("SESSION_FILE_PATH=cache/sessions\n", "SESSION_FILE_PATH={$relSessionPath}", $env);

            $envFile = $this->writeTempEnv($env);

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_base_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            $dotenv = new Dotenv($envFile, $basePath);
            $dotenv->load();

            (new EnvValidator())->validate($basePath);

            $expected = rtrim($basePath, "/\\") . DIRECTORY_SEPARATOR . ltrim("cache/sessions_{$uniq}", "/\\");

            $this->assertNotContains(
                $expected,
                EnvValidatorFsSpy::$mkdirPaths,
                'När SESSION_DRIVER inte är file ska SESSION_FILE_PATH ignoreras helt (ingen mkdir för den).'
            );
        }

        public function testValidateFailsWhenCorsAllowOriginIsNotAUrl(): void
        {
            // Dödar 74: om url('CORS_ALLOW_ORIGIN') tas bort skulle validate() felaktigt passera.
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');

            $env = str_replace("CORS_ALLOW_ORIGIN=http://localhost\n", "CORS_ALLOW_ORIGIN=not-a-url\n", $env);

            $envFile = $this->writeTempEnv($env);

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            (new Dotenv($envFile, $basePath))->load();

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Invalid environment configuration:');

            (new EnvValidator())->validate($basePath);
        }

        public function testValidateFailsWhenMailEmailIsInvalid(): void
        {
            // Dödar 75: om email('MAIL_EMAIL') tas bort skulle validate() felaktigt passera.
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');

            $env = str_replace("MAIL_EMAIL=noreply@example.com\n", "MAIL_EMAIL=not-an-email\n", $env);

            $envFile = $this->writeTempEnv($env);

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            (new Dotenv($envFile, $basePath))->load();

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Invalid environment configuration:');

            (new EnvValidator())->validate($basePath);
        }

        public function testValidateFailsWhenTimezoneIsInvalid(): void
        {
            // Dödar 76: om timezone('APP_TIMEZONE') tas bort skulle validate() felaktigt passera.
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');

            $env = str_replace("APP_TIMEZONE=Europe/Stockholm\n", "APP_TIMEZONE=Not/A_Real_Timezone\n", $env);

            $envFile = $this->writeTempEnv($env);

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            (new Dotenv($envFile, $basePath))->load();

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Invalid environment configuration:');

            (new EnvValidator())->validate($basePath);
        }

        public function testValidateAcceptsAppCopyYearAtMinAndMaxBounds(): void
        {
            // Dödar mutant-varianter som ändrar 1970->1971 och 3000->2999.
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_bounds_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            // Min (1970) ska vara OK
            $envMin = str_replace("APP_COPY_YEAR=2026\n", "APP_COPY_YEAR=1970\n", $env);
            (new Dotenv($this->writeTempEnv($envMin), $basePath))->load();
            (new EnvValidator())->validate($basePath);

            // Max (3000) ska vara OK
            $envMax = str_replace("APP_COPY_YEAR=2026\n", "APP_COPY_YEAR=3000\n", $env);
            (new Dotenv($this->writeTempEnv($envMax), $basePath))->load();
            (new EnvValidator())->validate($basePath);

            // (ingen assert behövs: om vi kom hit så passade valideringen)
        }

        public function testValidateFailsWhenAppCopyYearIsBelowMinOrAboveMax(): void
        {
            // Dödar mutant-varianter som tar bort intLike() eller ändrar 3000->3001.
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_bounds_fail_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            // Under min (1969) ska FAILA
            $envUnder = str_replace("APP_COPY_YEAR=2026\n", "APP_COPY_YEAR=1969\n", $env);
            (new Dotenv($this->writeTempEnv($envUnder), $basePath))->load();

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Invalid environment configuration:');

            (new EnvValidator())->validate($basePath);

            // OBS: vi behöver inte testa "3001" i samma test p.g.a. exception-stop,
            // så det ligger i separat test nedan.
        }

        public function testValidateFailsWhenAppCopyYearIsAboveMax(): void
        {
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');

            $envOver = str_replace("APP_COPY_YEAR=2026\n", "APP_COPY_YEAR=3001\n", $env);

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_bounds_fail2_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            (new Dotenv($this->writeTempEnv($envOver), $basePath))->load();

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Invalid environment configuration:');

            (new EnvValidator())->validate($basePath);
        }

        public function testValidateFailsWhenAppUrlIsNotAUrl(): void
        {
            // Dödar mutant som tar bort url('APP_URL') i validate().
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');
            $env = str_replace("APP_URL=http://localhost\n", "APP_URL=not-a-url\n", $env);

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_appurl_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            (new Dotenv($this->writeTempEnv($env), $basePath))->load();

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Invalid environment configuration:');

            (new EnvValidator())->validate($basePath);
        }

        public function testValidateFailsWhenLocatorCityUrlIsNotAUrl(): void
        {
            // Dödar mutant som tar bort url('LOCATOR_CITY_URL') i validate().
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');
            $env = str_replace("LOCATOR_CITY_URL=https://example.com/city\n", "LOCATOR_CITY_URL=not-a-url\n", $env);

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_locatorurl_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            (new Dotenv($this->writeTempEnv($env), $basePath))->load();

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Invalid environment configuration:');

            (new EnvValidator())->validate($basePath);
        }

        public function testValidateAcceptsMailPortAtMinAndMaxBounds(): void
        {
            // Dödar:
            // - min 1 -> 2 (MAIL_PORT=1 måste passera)
            // - max 65535 -> 65534 (MAIL_PORT=65535 måste passera)
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_mailport_ok_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            $envMin = str_replace("MAIL_PORT=2525\n", "MAIL_PORT=1\n", $env);
            (new Dotenv($this->writeTempEnv($envMin), $basePath))->load();
            (new EnvValidator())->validate($basePath);

            $envMax = str_replace("MAIL_PORT=2525\n", "MAIL_PORT=65535\n", $env);
            (new Dotenv($this->writeTempEnv($envMax), $basePath))->load();
            (new EnvValidator())->validate($basePath);
        }

        public function testValidateFailsWhenMailPortIsOutOfRange(): void
        {
            // Dödar:
            // - max 65535 -> 65536 (MAIL_PORT=65536 måste faila)
            // - MethodCallRemoval av MAIL_PORT-valideringen (annars skulle detta passera)
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_mailport_fail_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            $envOver = str_replace("MAIL_PORT=2525\n", "MAIL_PORT=65536\n", $env);
            (new Dotenv($this->writeTempEnv($envOver), $basePath))->load();

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Invalid environment configuration:');

            (new EnvValidator())->validate($basePath);
        }

        public function testValidateFailsWhenMailPortIsZero(): void
        {
            // Dödar indirekt att min=1 faktiskt gäller (ytterkant under min).
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_mailport_zero_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            $envZero = str_replace("MAIL_PORT=2525\n", "MAIL_PORT=0\n", $env);
            (new Dotenv($this->writeTempEnv($envZero), $basePath))->load();

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Invalid environment configuration:');

            (new EnvValidator())->validate($basePath);
        }

        public function testValidateWithEmptySessionLifetimeDoesNotAddIntegerError(): void
        {
            // Dödar mutant 65 (allowEmpty: true -> false) genom att säkerställa att tomt SESSION_LIFETIME
            // INTE ger extra "must be integer"-fel ovanpå "is required".
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');
            $env = str_replace("SESSION_LIFETIME=1440\n", "SESSION_LIFETIME=\n", $env);

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_sesslife_empty_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            (new Dotenv($this->writeTempEnv($env), $basePath))->load();

            try {
                (new EnvValidator())->validate($basePath);
                $this->fail('Expected exception not thrown.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString(
                    'SESSION_LIFETIME is required',
                    $e->getMessage(),
                    'Tom SESSION_LIFETIME ska ge presence-fel.'
                );

                $this->assertStringNotContainsString(
                    'SESSION_LIFETIME must be integer',
                    $e->getMessage(),
                    'När SESSION_LIFETIME är tom ska intLike() (allowEmpty=true) inte lägga till integer-fel.'
                );
            }
        }

        public function testValidateFailsWhenSessionLifetimeIsNotAnInteger(): void
        {
            // Dödar mutant 66 (MethodCallRemoval av intLike(SESSION_LIFETIME)).
            // Presence passerar (icke-tomt), men intLike ska stoppa.
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');
            $env = str_replace("SESSION_LIFETIME=1440\n", "SESSION_LIFETIME=not-an-int\n", $env);

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_sesslife_bad_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            (new Dotenv($this->writeTempEnv($env), $basePath))->load();

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Invalid environment configuration:');

            (new EnvValidator())->validate($basePath);
        }

        public function testValidateAcceptsDbPortAtMinAndMaxBounds(): void
        {
            // Dödar:
            // - min 1 -> 2 (DB_PORT=1 måste passera)
            // - max 65535 -> 65534 (DB_PORT=65535 måste passera)
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_dbport_ok_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            $envMin = str_replace("DB_PORT=3306\n", "DB_PORT=1\n", $env);
            (new Dotenv($this->writeTempEnv($envMin), $basePath))->load();
            (new EnvValidator())->validate($basePath);

            $envMax = str_replace("DB_PORT=3306\n", "DB_PORT=65535\n", $env);
            (new Dotenv($this->writeTempEnv($envMax), $basePath))->load();
            (new EnvValidator())->validate($basePath);
        }

        public function testValidateFailsWhenDbPortIsZero(): void
        {
            // Dödar mutant 54 (min 1 -> 0). Om min blir 0 så skulle DB_PORT=0 passera.
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');
            $env = str_replace("DB_PORT=3306\n", "DB_PORT=0\n", $env);

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_dbport_zero_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            (new Dotenv($this->writeTempEnv($env), $basePath))->load();

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Invalid environment configuration:');

            (new EnvValidator())->validate($basePath);
        }

        public function testValidateFailsWhenDbPortIsAboveMax(): void
        {
            // Dödar mutant 57 (max 65535 -> 65536) + mutant 58 (MethodCallRemoval av DB_PORT-valideringen).
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');
            $env = str_replace("DB_PORT=3306\n", "DB_PORT=65536\n", $env);

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_dbport_over_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            (new Dotenv($this->writeTempEnv($env), $basePath))->load();

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Invalid environment configuration:');

            (new EnvValidator())->validate($basePath);
        }

        public function testValidateFailsWhenMailSecureIsInvalidEnumValue(): void
        {
            // Dödar mutant 49 (tar bort enum('MAIL_SECURE', ...)).
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');
            $env = str_replace("MAIL_SECURE=tls\n", "MAIL_SECURE=bogus\n", $env);

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_mailsecure_bad_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            (new Dotenv($this->writeTempEnv($env), $basePath))->load();

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Invalid environment configuration:');

            (new EnvValidator())->validate($basePath);
        }

        public function testValidateFailsWhenAppDebugIsNotBooleanLike(): void
        {
            // Dödar:
            // - 50 (tar bort APP_DEBUG ur listan)
            // - 51 (foreach([]))
            // - 53 (tar bort boolLike-anropet)
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');
            $env = str_replace("APP_DEBUG=1\n", "APP_DEBUG=maybe\n", $env);

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_appdebug_bad_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            (new Dotenv($this->writeTempEnv($env), $basePath))->load();

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Invalid environment configuration:');

            (new EnvValidator())->validate($basePath);
        }

        public function testValidateAllowsEmptyAppDebugBecauseBoolLikeAllowEmptyIsTrue(): void
        {
            // Dödar 52 (allowEmpty: true -> false).
            // APP_DEBUG är inte “required”, så tomt ska vara OK när boolLike() körs med allowEmpty=true.
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');
            $env = str_replace("APP_DEBUG=1\n", "APP_DEBUG=\n", $env);

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_appdebug_empty_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            (new Dotenv($this->writeTempEnv($env), $basePath))->load();

            (new EnvValidator())->validate($basePath);
        }

        public function testValidateFailsWhenAppEnvIsNotInAllowedEnum(): void
        {
            // Dödar mutant 44 (tar bort enum('APP_ENV', ...)).
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');
            $env = str_replace("APP_ENV=development\n", "APP_ENV=bogus\n", $env);

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_appenv_bad_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            (new Dotenv($this->writeTempEnv($env), $basePath))->load();

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Invalid environment configuration:');

            (new EnvValidator())->validate($basePath);
        }

        public function testValidateFailsWhenDbDriverIsNotInAllowedEnum(): void
        {
            // Dödar mutant 45 (tar bort enum('DB_DRIVER', ...)).
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');
            $env = str_replace("DB_DRIVER=mysql\n", "DB_DRIVER=bogus\n", $env);

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_dbdriver_bad_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            (new Dotenv($this->writeTempEnv($env), $basePath))->load();

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Invalid environment configuration:');

            (new EnvValidator())->validate($basePath);
        }

        public function testValidateFailsWhenSessionDriverIsNotInAllowedEnum(): void
        {
            // Dödar mutant 46 (tar bort enum('SESSION_DRIVER', ...)).
            // Viktigt: använd ett värde som INTE är "file", så vi inte triggar writablePath('SESSION_FILE_PATH', ...) senare.
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');
            $env = str_replace("SESSION_DRIVER=file\n", "SESSION_DRIVER=bogus\n", $env);

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_sessiondriver_bad_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            (new Dotenv($this->writeTempEnv($env), $basePath))->load();

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Invalid environment configuration:');

            (new EnvValidator())->validate($basePath);
        }

        public function testValidateAcceptsMailSecureNone(): void
        {
            // Dödar mutant 47 (tar bort "none" ur allowed-listan för MAIL_SECURE).
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');
            $env = str_replace("MAIL_SECURE=tls\n", "MAIL_SECURE=none\n", $env);

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_mailsecure_none_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            (new Dotenv($this->writeTempEnv($env), $basePath))->load();

            (new EnvValidator())->validate($basePath);
        }

        public function testValidateFailsWhenMailAuthIsMissing(): void
        {
            // Dödar mutant 40 (tar bort require('MAIL_AUTH')).
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');
            $env = str_replace("MAIL_AUTH=0\n", "MAIL_AUTH=\n", $env);

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_mailauth_missing_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            (new Dotenv($this->writeTempEnv($env), $basePath))->load();

            try {
                (new EnvValidator())->validate($basePath);
                $this->fail('Expected exception not thrown.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString(
                    'MAIL_AUTH is required',
                    $e->getMessage(),
                    'MAIL_AUTH ska vara required.'
                );
            }
        }

        public function testValidateFailsWhenMailAuthTrueButMailAccountMissing(): void
        {
            // Dödar mutant 41 (tar bort requireIfBoolTrue('MAIL_AUTH', 'MAIL_ACCOUNT')).
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');
            $env = str_replace("MAIL_AUTH=0\n", "MAIL_AUTH=1\n", $env);
            $env = str_replace("MAIL_ACCOUNT=\n", "MAIL_ACCOUNT=\n", $env); // explicit
            $env = str_replace("MAIL_PASSWORD=\n", "MAIL_PASSWORD=dummy\n", $env); // så bara ACCOUNT är problemet

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_mailauth_account_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            (new Dotenv($this->writeTempEnv($env), $basePath))->load();

            try {
                (new EnvValidator())->validate($basePath);
                $this->fail('Expected exception not thrown.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString(
                    'MAIL_ACCOUNT is required when MAIL_AUTH is true',
                    $e->getMessage(),
                    'När MAIL_AUTH=1 ska MAIL_ACCOUNT krävas.'
                );
            }
        }

        public function testValidateFailsWhenMailAuthTrueButMailPasswordMissing(): void
        {
            // Dödar mutant 42 (tar bort requireIfBoolTrue('MAIL_AUTH', 'MAIL_PASSWORD')).
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');
            $env = str_replace("MAIL_AUTH=0\n", "MAIL_AUTH=1\n", $env);
            $env = str_replace("MAIL_ACCOUNT=\n", "MAIL_ACCOUNT=dummy\n", $env); // så bara PASSWORD är problemet
            $env = str_replace("MAIL_PASSWORD=\n", "MAIL_PASSWORD=\n", $env); // explicit

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_mailauth_password_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            (new Dotenv($this->writeTempEnv($env), $basePath))->load();

            try {
                (new EnvValidator())->validate($basePath);
                $this->fail('Expected exception not thrown.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString(
                    'MAIL_PASSWORD is required when MAIL_AUTH is true',
                    $e->getMessage(),
                    'När MAIL_AUTH=1 ska MAIL_PASSWORD krävas.'
                );
            }
        }

        public function testValidateAcceptsAppEnvProdAlias(): void
        {
            // Dödar mutant 43 (tar bort "prod" ur allowed-listan för APP_ENV).
            // OBS: "prod" behandlas som production => prod-only värden måste vara satta.
            $env = $this->baseEnv(
                appEnv: 'prod',
                ormNamespace: 'Dummy\\Models\\',
                healthAllowlist: '127.0.0.1'
            );

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_appenv_prod_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            (new Dotenv($this->writeTempEnv($env), $basePath))->load();

            (new EnvValidator())->validate($basePath);
        }

        public function testValidateFailsWhenMailCharsetIsMissing(): void
        {
            // Dödar mutant 38 (MethodCallRemoval av require('MAIL_CHARSET')).
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');
            $env = str_replace("MAIL_CHARSET=UTF-8\n", "MAIL_CHARSET=\n", $env);

            $envFile = $this->writeTempEnv($env);

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_mailcharset_missing_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            (new Dotenv($envFile, $basePath))->load();

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Invalid environment configuration:');

            (new EnvValidator())->validate($basePath);
        }

        public function testValidateFailsWhenMailSecureIsMissing(): void
        {
            // Dödar mutant 39 (MethodCallRemoval av require('MAIL_SECURE')).
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');
            $env = str_replace("MAIL_SECURE=tls\n", "MAIL_SECURE=\n", $env);

            $envFile = $this->writeTempEnv($env);

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_mailsecure_missing_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            (new Dotenv($envFile, $basePath))->load();

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Invalid environment configuration:');

            (new EnvValidator())->validate($basePath);
        }

        public function testValidateWhenMailSecureIsEmptyDoesNotAddEnumErrorOnTopOfPresenceError(): void
        {
            // Dödar mutant 40 (allowEmpty: true -> false) i enum('MAIL_SECURE', ...).
            // Vi vill ha presence-felet, men INTE ett extra enum-fel när värdet är tomt.
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');
            $env = str_replace("MAIL_SECURE=tls\n", "MAIL_SECURE=\n", $env);

            $envFile = $this->writeTempEnv($env);

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_mailsecure_empty_enum_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            (new Dotenv($envFile, $basePath))->load();

            try {
                (new EnvValidator())->validate($basePath);
                $this->fail('Expected exception not thrown.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString(
                    'MAIL_SECURE is required',
                    $e->getMessage(),
                    'Tom MAIL_SECURE ska ge presence-fel.'
                );

                $this->assertStringNotContainsString(
                    'MAIL_SECURE must be one of:',
                    $e->getMessage(),
                    'Tom MAIL_SECURE ska inte också ge enum-fel när enum() körs med allowEmpty=true.'
                );
            }
        }

        public function testValidateFailsWhenMailHostIsMissingAndMessageMentionsRequired(): void
        {
            // Dödar mutant 33 (MethodCallRemoval av require('MAIL_HOST')).
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');
            $env = str_replace("MAIL_HOST=smtp.example.com\n", "MAIL_HOST=\n", $env);

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_mailhost_missing_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            (new Dotenv($this->writeTempEnv($env), $basePath))->load();

            try {
                (new EnvValidator())->validate($basePath);
                $this->fail('Expected exception not thrown.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString(
                    'MAIL_HOST is required',
                    $e->getMessage(),
                    'MAIL_HOST ska vara required; om require(' . "'MAIL_HOST'" . ') tas bort får vi inget presence-fel.'
                );
            }
        }

        public function testValidateWhenMailPortIsEmptyIncludesPresenceErrorNotOnlyIntegerError(): void
        {
            // Dödar mutant 34 (MethodCallRemoval av require('MAIL_PORT')).
            // OBS: intLike('MAIL_PORT') kommer fortfarande ge "must be integer" för tomt värde,
            // så vi kräver explicit presence-felraden.
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');
            $env = str_replace("MAIL_PORT=2525\n", "MAIL_PORT=\n", $env);

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_mailport_missing_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            (new Dotenv($this->writeTempEnv($env), $basePath))->load();

            try {
                (new EnvValidator())->validate($basePath);
                $this->fail('Expected exception not thrown.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString(
                    'MAIL_PORT is required',
                    $e->getMessage(),
                    'MAIL_PORT ska ge presence-fel när den är tom.'
                );
            }
        }

        public function testValidateWhenMailEmailIsEmptyIncludesPresenceError(): void
        {
            // Dödar mutant 35 (MethodCallRemoval av require('MAIL_EMAIL')).
            // email('MAIL_EMAIL') failar också när tomt, så vi kräver presence-felraden.
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');
            $env = str_replace("MAIL_EMAIL=noreply@example.com\n", "MAIL_EMAIL=\n", $env);

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_mailemail_missing_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            (new Dotenv($this->writeTempEnv($env), $basePath))->load();

            try {
                (new EnvValidator())->validate($basePath);
                $this->fail('Expected exception not thrown.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString(
                    'MAIL_EMAIL is required',
                    $e->getMessage(),
                    'MAIL_EMAIL ska ge presence-fel när den är tom.'
                );
            }
        }

        public function testValidateFailsWhenMailFromIsMissingAndMessageMentionsRequired(): void
        {
            // Dödar mutant 36 (MethodCallRemoval av require('MAIL_FROM')) + hjälper även 37-varianten.
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');
            $env = str_replace("MAIL_FROM=Radix System\n", "MAIL_FROM=\n", $env);

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_mailfrom_missing_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            (new Dotenv($this->writeTempEnv($env), $basePath))->load();

            try {
                (new EnvValidator())->validate($basePath);
                $this->fail('Expected exception not thrown.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString(
                    'MAIL_FROM is required',
                    $e->getMessage(),
                    'MAIL_FROM ska vara required; om raden tas bort ska testet falla.'
                );
            }
        }

        public function testValidateFailsWhenMailDebugIsMissingBecauseItIsRequiredEvenThoughBoolLikeAllowsEmpty(): void
        {
            // Dödar mutant 37 (MethodCallRemoval av require('MAIL_DEBUG')).
            // boolLike('MAIL_DEBUG', allowEmpty:true) skulle annars acceptera tomt.
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');
            $env = str_replace("MAIL_DEBUG=0\n", "MAIL_DEBUG=\n", $env);

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_maildebug_missing_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            (new Dotenv($this->writeTempEnv($env), $basePath))->load();

            try {
                (new EnvValidator())->validate($basePath);
                $this->fail('Expected exception not thrown.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString(
                    'MAIL_DEBUG is required',
                    $e->getMessage(),
                    'MAIL_DEBUG ska ge presence-fel även om boolLike() tillåter tomt.'
                );
            }
        }

        public function testValidateFailsWhenCacheRootIsMissingAndMessageMentionsRequired(): void
        {
            // Dödar mutant 28 (MethodCallRemoval av require('CACHE_ROOT')).
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');
            $env = str_replace("CACHE_ROOT=cache\n", "CACHE_ROOT=\n", $env);

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_cacheroot_missing_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            (new Dotenv($this->writeTempEnv($env), $basePath))->load();

            try {
                (new EnvValidator())->validate($basePath);
                $this->fail('Expected exception not thrown.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString(
                    'CACHE_ROOT is required',
                    $e->getMessage(),
                    'CACHE_ROOT ska vara required; om require(CACHE_ROOT) tas bort ska detta test falla.'
                );
            }
        }

        public function testValidateWhenViewsCachePathIsEmptyIncludesPresenceError(): void
        {
            // Dödar mutant 29 (MethodCallRemoval av require('VIEWS_CACHE_PATH')).
            // OBS: writablePath() kan annars ge "VIEWS_CACHE_PATH is required (path)".
            // Vi kräver presence-felraden för att skilja från writablePath-fel.
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');
            $env = str_replace("VIEWS_CACHE_PATH=cache/views\n", "VIEWS_CACHE_PATH=\n", $env);

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_viewscache_missing_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            (new Dotenv($this->writeTempEnv($env), $basePath))->load();

            try {
                (new EnvValidator())->validate($basePath);
                $this->fail('Expected exception not thrown.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString(
                    'VIEWS_CACHE_PATH is required',
                    $e->getMessage(),
                    'VIEWS_CACHE_PATH ska ge presence-fel när den är tom (inte bara writablePath-fel).'
                );
            }
        }

        public function testValidateWhenAppCachePathIsEmptyIncludesPresenceError(): void
        {
            // Dödar mutant 30 (MethodCallRemoval av require('APP_CACHE_PATH')).
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');
            $env = str_replace("APP_CACHE_PATH=cache/app\n", "APP_CACHE_PATH=\n", $env);

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_appcache_missing_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            (new Dotenv($this->writeTempEnv($env), $basePath))->load();

            try {
                (new EnvValidator())->validate($basePath);
                $this->fail('Expected exception not thrown.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString(
                    'APP_CACHE_PATH is required',
                    $e->getMessage(),
                    'APP_CACHE_PATH ska ge presence-fel när den är tom.'
                );
            }
        }

        public function testValidateWhenHealthCachePathIsEmptyIncludesPresenceError(): void
        {
            // Dödar mutant 31 (MethodCallRemoval av require('HEALTH_CACHE_PATH')).
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');
            $env = str_replace("HEALTH_CACHE_PATH=cache/health\n", "HEALTH_CACHE_PATH=\n", $env);

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_healthcache_missing_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            (new Dotenv($this->writeTempEnv($env), $basePath))->load();

            try {
                (new EnvValidator())->validate($basePath);
                $this->fail('Expected exception not thrown.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString(
                    'HEALTH_CACHE_PATH is required',
                    $e->getMessage(),
                    'HEALTH_CACHE_PATH ska ge presence-fel när den är tom.'
                );
            }
        }

        public function testValidateWhenRatelimitCachePathIsEmptyIncludesPresenceError(): void
        {
            // Dödar mutant 32 (MethodCallRemoval av require('RATELIMIT_CACHE_PATH')).
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');
            $env = str_replace("RATELIMIT_CACHE_PATH=cache/ratelimit\n", "RATELIMIT_CACHE_PATH=\n", $env);

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_ratelimitcache_missing_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            (new Dotenv($this->writeTempEnv($env), $basePath))->load();

            try {
                (new EnvValidator())->validate($basePath);
                $this->fail('Expected exception not thrown.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString(
                    'RATELIMIT_CACHE_PATH is required',
                    $e->getMessage(),
                    'RATELIMIT_CACHE_PATH ska ge presence-fel när den är tom.'
                );
            }
        }

        public function testValidateWhenViewsCachePathIsEmptyHasPresenceBulletNotOnlyPathBullet(): void
        {
            // Dödar mutant 28 (MethodCallRemoval av require('VIEWS_CACHE_PATH')):
            // "VIEWS_CACHE_PATH is required (path)" får INTE räcka.
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');
            $env = str_replace("VIEWS_CACHE_PATH=cache/views\n", "VIEWS_CACHE_PATH=\n", $env);

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_viewscache_missing_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            (new Dotenv($this->writeTempEnv($env), $basePath))->load();

            try {
                (new EnvValidator())->validate($basePath);
                $this->fail('Expected exception not thrown.');
            } catch (RuntimeException $e) {
                // Kräv en egen bullet-rad för presence (inte "(path)").
                $this->assertMatchesRegularExpression(
                    "/\n - VIEWS_CACHE_PATH is required(\n|$)/",
                    $e->getMessage()
                );
            }
        }

        public function testValidateWhenAppCachePathIsEmptyHasPresenceBulletNotOnlyPathBullet(): void
        {
            // Dödar mutant 29 (MethodCallRemoval av require('APP_CACHE_PATH')).
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');
            $env = str_replace("APP_CACHE_PATH=cache/app\n", "APP_CACHE_PATH=\n", $env);

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_appcache_missing_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            (new Dotenv($this->writeTempEnv($env), $basePath))->load();

            try {
                (new EnvValidator())->validate($basePath);
                $this->fail('Expected exception not thrown.');
            } catch (RuntimeException $e) {
                $this->assertMatchesRegularExpression(
                    "/\n - APP_CACHE_PATH is required(\n|$)/",
                    $e->getMessage()
                );
            }
        }

        public function testValidateWhenHealthCachePathIsEmptyHasPresenceBulletNotOnlyPathBullet(): void
        {
            // Dödar mutant 30 (MethodCallRemoval av require('HEALTH_CACHE_PATH')).
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');
            $env = str_replace("HEALTH_CACHE_PATH=cache/health\n", "HEALTH_CACHE_PATH=\n", $env);

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_healthcache_missing_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            (new Dotenv($this->writeTempEnv($env), $basePath))->load();

            try {
                (new EnvValidator())->validate($basePath);
                $this->fail('Expected exception not thrown.');
            } catch (RuntimeException $e) {
                $this->assertMatchesRegularExpression(
                    "/\n - HEALTH_CACHE_PATH is required(\n|$)/",
                    $e->getMessage()
                );
            }
        }

        public function testValidateWhenRatelimitCachePathIsEmptyHasPresenceBulletNotOnlyPathBullet(): void
        {
            // Dödar mutant 31 (MethodCallRemoval av require('RATELIMIT_CACHE_PATH')).
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');
            $env = str_replace("RATELIMIT_CACHE_PATH=cache/ratelimit\n", "RATELIMIT_CACHE_PATH=\n", $env);

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_ratelimitcache_missing_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            (new Dotenv($this->writeTempEnv($env), $basePath))->load();

            try {
                (new EnvValidator())->validate($basePath);
                $this->fail('Expected exception not thrown.');
            } catch (RuntimeException $e) {
                $this->assertMatchesRegularExpression(
                    "/\n - RATELIMIT_CACHE_PATH is required(\n|$)/",
                    $e->getMessage()
                );
            }
        }

        public function testValidateWhenSessionDriverIsEmptyHasPresenceBullet(): void
        {
            // Dödar mutant 23 (MethodCallRemoval av require('SESSION_DRIVER')).
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');
            $env = str_replace("SESSION_DRIVER=file\n", "SESSION_DRIVER=\n", $env);

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_sessiondriver_empty_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            (new Dotenv($this->writeTempEnv($env), $basePath))->load();

            try {
                (new EnvValidator())->validate($basePath);
                $this->fail('Expected exception not thrown.');
            } catch (RuntimeException $e) {
                $this->assertMatchesRegularExpression(
                    "/\n - SESSION_DRIVER is required(\n|$)/",
                    $e->getMessage()
                );
            }
        }

        public function testValidateWhenSessionDriverIsFileAndSessionFilePathIsEmptyHasRequireIfEqualsBullet(): void
        {
            // Dödar mutant 24 (MethodCallRemoval av requireIfEquals('SESSION_DRIVER','file','SESSION_FILE_PATH')).
            // Viktigt: writablePath() kan annars ge "SESSION_FILE_PATH is required (path)".
            // Vi kräver den mer specifika requireIfEquals-raden.
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');
            $env = str_replace("SESSION_DRIVER=file\n", "SESSION_DRIVER=file\n", $env);
            $env = str_replace("SESSION_FILE_PATH=cache/sessions\n", "SESSION_FILE_PATH=\n", $env);

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_sessionfilepath_empty_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            (new Dotenv($this->writeTempEnv($env), $basePath))->load();

            try {
                (new EnvValidator())->validate($basePath);
                $this->fail('Expected exception not thrown.');
            } catch (RuntimeException $e) {
                $this->assertMatchesRegularExpression(
                    "/\n - SESSION_FILE_PATH is required when SESSION_DRIVER=file(\n|$)/",
                    $e->getMessage()
                );
            }
        }

        public function testValidateWhenSessionDriverIsDatabaseAndSessionTableIsEmptyHasRequireIfEqualsBullet(): void
        {
            // Dödar mutant 25 (MethodCallRemoval av requireIfEquals('SESSION_DRIVER','database','SESSION_TABLE')).
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');
            $env = str_replace("SESSION_DRIVER=file\n", "SESSION_DRIVER=database\n", $env);
            $env = str_replace("SESSION_TABLE=sessions\n", "SESSION_TABLE=\n", $env);

            // SESSION_FILE_PATH är irrelevant här, men vi kan låta den vara kvar.
            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_sessiontable_empty_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            (new Dotenv($this->writeTempEnv($env), $basePath))->load();

            try {
                (new EnvValidator())->validate($basePath);
                $this->fail('Expected exception not thrown.');
            } catch (RuntimeException $e) {
                $this->assertMatchesRegularExpression(
                    "/\n - SESSION_TABLE is required when SESSION_DRIVER=database(\n|$)/",
                    $e->getMessage()
                );
            }
        }

        public function testValidateWhenSecureTokenHmacIsMissingHasPresenceBullet(): void
        {
            // Dödar mutant 26 (MethodCallRemoval av require('SECURE_TOKEN_HMAC')).
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');
            $env = str_replace("SECURE_TOKEN_HMAC=dummy-hmac-key-very-long\n", "SECURE_TOKEN_HMAC=\n", $env);

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_hmac_missing_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            (new Dotenv($this->writeTempEnv($env), $basePath))->load();

            try {
                (new EnvValidator())->validate($basePath);
                $this->fail('Expected exception not thrown.');
            } catch (RuntimeException $e) {
                $this->assertMatchesRegularExpression(
                    "/\n - SECURE_TOKEN_HMAC is required(\n|$)/",
                    $e->getMessage()
                );
            }
        }

        public function testValidateWhenSecureEncryptionKeyIsMissingHasPresenceBullet(): void
        {
            // Dödar mutant 27 (MethodCallRemoval av require('SECURE_ENCRYPTION_KEY')).
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');
            $env = str_replace("SECURE_ENCRYPTION_KEY=dummy-encryption-key-very-long-32-chars\n", "SECURE_ENCRYPTION_KEY=\n", $env);

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_enckey_missing_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            (new Dotenv($this->writeTempEnv($env), $basePath))->load();

            try {
                (new EnvValidator())->validate($basePath);
                $this->fail('Expected exception not thrown.');
            } catch (RuntimeException $e) {
                $this->assertMatchesRegularExpression(
                    "/\n - SECURE_ENCRYPTION_KEY is required(\n|$)/",
                    $e->getMessage()
                );
            }
        }

        public function testValidateWhenDbDriverIsEmptyHasPresenceBullet(): void
        {
            // Dödar mutant 17 (MethodCallRemoval av require('DB_DRIVER')).
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');
            $env = str_replace("DB_DRIVER=mysql\n", "DB_DRIVER=\n", $env);

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_dbdriver_empty_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            (new Dotenv($this->writeTempEnv($env), $basePath))->load();

            try {
                (new EnvValidator())->validate($basePath);
                $this->fail('Expected exception not thrown.');
            } catch (RuntimeException $e) {
                $this->assertMatchesRegularExpression(
                    "/\n - DB_DRIVER is required(\n|$)/",
                    $e->getMessage()
                );
            }
        }

        public function testValidateWhenDbHostIsEmptyHasPresenceBullet(): void
        {
            // Dödar mutant 18 (MethodCallRemoval av require('DB_HOST')).
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');
            $env = str_replace("DB_HOST=127.0.0.1\n", "DB_HOST=\n", $env);

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_dbhost_empty_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            (new Dotenv($this->writeTempEnv($env), $basePath))->load();

            try {
                (new EnvValidator())->validate($basePath);
                $this->fail('Expected exception not thrown.');
            } catch (RuntimeException $e) {
                $this->assertMatchesRegularExpression(
                    "/\n - DB_HOST is required(\n|$)/",
                    $e->getMessage()
                );
            }
        }

        public function testValidateWhenDbPortIsEmptyHasPresenceBullet(): void
        {
            // Dödar mutant 19 (MethodCallRemoval av require('DB_PORT')).
            // OBS: intLike('DB_PORT') kan också fela vid tomt värde, därför kräver vi presence-bulleten.
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');
            $env = str_replace("DB_PORT=3306\n", "DB_PORT=\n", $env);

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_dbport_empty_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            (new Dotenv($this->writeTempEnv($env), $basePath))->load();

            try {
                (new EnvValidator())->validate($basePath);
                $this->fail('Expected exception not thrown.');
            } catch (RuntimeException $e) {
                $this->assertMatchesRegularExpression(
                    "/\n - DB_PORT is required(\n|$)/",
                    $e->getMessage()
                );
            }
        }

        public function testValidateWhenDbNameIsEmptyHasPresenceBullet(): void
        {
            // Dödar mutant 20 (MethodCallRemoval av require('DB_NAME')).
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');
            $env = str_replace("DB_NAME=radix\n", "DB_NAME=\n", $env);

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_dbname_empty_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            (new Dotenv($this->writeTempEnv($env), $basePath))->load();

            try {
                (new EnvValidator())->validate($basePath);
                $this->fail('Expected exception not thrown.');
            } catch (RuntimeException $e) {
                $this->assertMatchesRegularExpression(
                    "/\n - DB_NAME is required(\n|$)/",
                    $e->getMessage()
                );
            }
        }

        public function testValidateWhenDbUsernameIsEmptyHasPresenceBullet(): void
        {
            // Dödar mutant 21 (MethodCallRemoval av require('DB_USERNAME')).
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');
            $env = str_replace("DB_USERNAME=root\n", "DB_USERNAME=\n", $env);

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_dbusername_empty_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            (new Dotenv($this->writeTempEnv($env), $basePath))->load();

            try {
                (new EnvValidator())->validate($basePath);
                $this->fail('Expected exception not thrown.');
            } catch (RuntimeException $e) {
                $this->assertMatchesRegularExpression(
                    "/\n - DB_USERNAME is required(\n|$)/",
                    $e->getMessage()
                );
            }
        }

        public function testValidateWhenDbCharsetIsEmptyHasPresenceBullet(): void
        {
            // Dödar mutant 22 (MethodCallRemoval av require('DB_CHARSET')).
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');
            $env = str_replace("DB_CHARSET=utf8mb4\n", "DB_CHARSET=\n", $env);

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_dbcharset_empty_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            (new Dotenv($this->writeTempEnv($env), $basePath))->load();

            try {
                (new EnvValidator())->validate($basePath);
                $this->fail('Expected exception not thrown.');
            } catch (RuntimeException $e) {
                $this->assertMatchesRegularExpression(
                    "/\n - DB_CHARSET is required(\n|$)/",
                    $e->getMessage()
                );
            }
        }

        public function testValidateWhenCorsAllowCredentialsIsEmptyHasPresenceBullet(): void
        {
            // Dödar mutant 13 (MethodCallRemoval av require('CORS_ALLOW_CREDENTIALS')).
            // boolLike(... allowEmpty:true) gör annars att tomt kan slinka igenom.
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');
            $env = str_replace("CORS_ALLOW_CREDENTIALS=1\n", "CORS_ALLOW_CREDENTIALS=\n", $env);

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_cors_creds_empty_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            (new Dotenv($this->writeTempEnv($env), $basePath))->load();

            try {
                (new EnvValidator())->validate($basePath);
                $this->fail('Expected exception not thrown.');
            } catch (RuntimeException $e) {
                $this->assertMatchesRegularExpression(
                    "/\n - CORS_ALLOW_CREDENTIALS is required(\n|$)/",
                    $e->getMessage()
                );
            }
        }

        public function testValidateWhenLocatorCountryIsEmptyHasPresenceBullet(): void
        {
            // Dödar mutant 14 (MethodCallRemoval av require('LOCATOR_COUNTRY')).
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');
            $env = str_replace("LOCATOR_COUNTRY=SE\n", "LOCATOR_COUNTRY=\n", $env);

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_locator_country_empty_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            (new Dotenv($this->writeTempEnv($env), $basePath))->load();

            try {
                (new EnvValidator())->validate($basePath);
                $this->fail('Expected exception not thrown.');
            } catch (RuntimeException $e) {
                $this->assertMatchesRegularExpression(
                    "/\n - LOCATOR_COUNTRY is required(\n|$)/",
                    $e->getMessage()
                );
            }
        }

        public function testValidateWhenLocatorCityIsEmptyHasPresenceBullet(): void
        {
            // Dödar mutant 15 (MethodCallRemoval av require('LOCATOR_CITY')).
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');
            $env = str_replace("LOCATOR_CITY=Stockholm\n", "LOCATOR_CITY=\n", $env);

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_locator_city_empty_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            (new Dotenv($this->writeTempEnv($env), $basePath))->load();

            try {
                (new EnvValidator())->validate($basePath);
                $this->fail('Expected exception not thrown.');
            } catch (RuntimeException $e) {
                $this->assertMatchesRegularExpression(
                    "/\n - LOCATOR_CITY is required(\n|$)/",
                    $e->getMessage()
                );
            }
        }

        public function testValidateWhenLocatorCityUrlIsEmptyHasPresenceBullet(): void
        {
            // Dödar mutant 16 (MethodCallRemoval av require('LOCATOR_CITY_URL')).
            // OBS: url('LOCATOR_CITY_URL') kan annars ge "must be a valid URL", så vi kräver presence-bulleten.
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');
            $env = str_replace("LOCATOR_CITY_URL=https://example.com/city\n", "LOCATOR_CITY_URL=\n", $env);

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_locator_cityurl_empty_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            (new Dotenv($this->writeTempEnv($env), $basePath))->load();

            try {
                (new EnvValidator())->validate($basePath);
                $this->fail('Expected exception not thrown.');
            } catch (RuntimeException $e) {
                $this->assertMatchesRegularExpression(
                    "/\n - LOCATOR_CITY_URL is required(\n|$)/",
                    $e->getMessage()
                );
            }
        }

        public function testValidateFailsWhenTrustedProxyIsInvalidIpOrCidr(): void
        {
            // Dödar MethodCallRemoval för ipList('TRUSTED_PROXY', allowEmpty:true)
            // genom att sätta ett icke-tomt men ogiltigt värde.
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');
            $env = str_replace("TRUSTED_PROXY=\n", "TRUSTED_PROXY=not-an-ip\n", $env);

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_trustedproxy_bad_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            (new Dotenv($this->writeTempEnv($env), $basePath))->load();

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Invalid environment configuration:');

            (new EnvValidator())->validate($basePath);
        }

        public function testValidateFailsWhenHealthIpAllowlistIsInvalidWhenPresent(): void
        {
            // Dödar MethodCallRemoval för ipList('HEALTH_IP_ALLOWLIST', allowEmpty:true).
            // Viktigt: allowEmpty=true betyder "tomt OK", inte "ogiltigt OK".
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: 'not-an-ip');

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_healthallowlist_bad_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            (new Dotenv($this->writeTempEnv($env), $basePath))->load();

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Invalid environment configuration:');

            (new EnvValidator())->validate($basePath);
        }

        public function testValidateWhenCorsAllowOriginIsEmptyIncludesPresenceError(): void
        {
            // Dödar MethodCallRemoval av require('CORS_ALLOW_ORIGIN').
            // url('CORS_ALLOW_ORIGIN') ger annars också fel, så vi kräver explicit presence-raden.
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');
            $env = str_replace("CORS_ALLOW_ORIGIN=http://localhost\n", "CORS_ALLOW_ORIGIN=\n", $env);

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_cors_origin_empty_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            (new Dotenv($this->writeTempEnv($env), $basePath))->load();

            try {
                (new EnvValidator())->validate($basePath);
                $this->fail('Expected exception not thrown.');
            } catch (RuntimeException $e) {
                $this->assertMatchesRegularExpression(
                    "/\n - CORS_ALLOW_ORIGIN is required(\n|$)/",
                    $e->getMessage(),
                    'CORS_ALLOW_ORIGIN ska ha en presence-bullet, inte bara URL-fel.'
                );
            }
        }

        public function testWritablePathReturnsAfterEmptyValueSoItDoesNotAddDirectoryError(): void
        {
            // Dödar ReturnRemoval i writablePath() när värdet är tomt:
            // Om return tas bort fortsätter metoden och kan lägga till extra fel beroende på basePath.
            // Vi gör basePath till en FIL för att göra skillnaden observerbar.
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');

            // Gör en av path-nycklarna tom så writablePath() går in i ($v === '')-grenen.
            $env = str_replace("VIEWS_CACHE_PATH=cache/views\n", "VIEWS_CACHE_PATH=\n", $env);

            $basePathFile = tempnam(sys_get_temp_dir(), 'radix_envvalidator_basefile_');
            $this->assertIsString($basePathFile);
            $this->assertFileExists($basePathFile);

            (new Dotenv($this->writeTempEnv($env), $basePathFile))->load();

            try {
                (new EnvValidator())->validate($basePathFile);
                $this->fail('Expected exception not thrown.');
            } catch (RuntimeException $e) {
                $msg = $e->getMessage();

                // Den här får finnas (writablePath-fel för tomt värde)
                $this->assertStringContainsString('VIEWS_CACHE_PATH is required (path)', $msg);

                // Den här ska INTE komma från samma nyckel; den kräver att metoden fortsätter efter tomt-värde.
                $this->assertStringNotContainsString(
                    'VIEWS_CACHE_PATH must be an existing writable directory',
                    $msg,
                    'writablePath() ska returnera direkt efter tomt värde och inte lägga "directory"-fel.'
                );
            } finally {
                @unlink($basePathFile);
            }
        }

        public function testValidateFailsWhenAppCopyIsMissing(): void
        {
            // Dödar MethodCallRemoval av require('APP_COPY'):
            // om require tas bort finns ingen annan validering som stoppar tom APP_COPY.
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');
            $env = str_replace("APP_COPY=Ditt Företag\n", "APP_COPY=\n", $env);

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_appcopy_missing_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            (new Dotenv($this->writeTempEnv($env), $basePath))->load();

            try {
                (new EnvValidator())->validate($basePath);
                $this->fail('Expected exception not thrown.');
            } catch (RuntimeException $e) {
                $this->assertMatchesRegularExpression(
                    "/\n - APP_COPY is required(\n|$)/",
                    $e->getMessage(),
                    'APP_COPY ska ge en presence-bullet.'
                );
            }
        }

        public function testValidateWhenAppCopyYearIsEmptyIncludesPresenceError(): void
        {
            // Dödar MethodCallRemoval av require('APP_COPY_YEAR'):
            // intLike() kommer också klaga ("must be integer") när tomt, så vi kräver presence-bulleten.
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');
            $env = str_replace("APP_COPY_YEAR=2026\n", "APP_COPY_YEAR=\n", $env);

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_appcopyyear_missing_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            (new Dotenv($this->writeTempEnv($env), $basePath))->load();

            try {
                (new EnvValidator())->validate($basePath);
                $this->fail('Expected exception not thrown.');
            } catch (RuntimeException $e) {
                $this->assertMatchesRegularExpression(
                    "/\n - APP_COPY_YEAR is required(\n|$)/",
                    $e->getMessage(),
                    'APP_COPY_YEAR ska ha presence-bullet, inte bara integer-fel.'
                );
            }
        }

        public function testValidateWhenHealthRequireTokenIsEmptyHasPresenceBullet(): void
        {
            // Dödar MethodCallRemoval av require('HEALTH_REQUIRE_TOKEN'):
            // requireIfBoolTrue() triggar inte när boolKey är tomt, så vi måste kräva presence-fel.
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');
            $env = str_replace("HEALTH_REQUIRE_TOKEN=0\n", "HEALTH_REQUIRE_TOKEN=\n", $env);

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_healthrequiretoken_missing_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            (new Dotenv($this->writeTempEnv($env), $basePath))->load();

            try {
                (new EnvValidator())->validate($basePath);
                $this->fail('Expected exception not thrown.');
            } catch (RuntimeException $e) {
                $this->assertMatchesRegularExpression(
                    "/\n - HEALTH_REQUIRE_TOKEN is required(\n|$)/",
                    $e->getMessage(),
                    'HEALTH_REQUIRE_TOKEN ska ge presence-bullet när den är tom.'
                );
            }
        }

        public function testValidateFailsInProductionWhenHealthIpAllowlistIsEmptyEvenIfOrmNamespaceIsSet(): void
        {
            // Dödar MethodCallRemoval av requireInProduction('HEALTH_IP_ALLOWLIST'):
            // ipList(... allowEmpty:true) accepterar tomt, så i production MÅSTE requireInProduction vara kvar.
            $env = $this->baseEnv(
                appEnv: 'production',
                ormNamespace: 'Dummy\\Models\\',
                healthAllowlist: ''
            );

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_healthallowlist_prod_missing_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            (new Dotenv($this->writeTempEnv($env), $basePath))->load();

            try {
                (new EnvValidator())->validate($basePath);
                $this->fail('Expected exception not thrown.');
            } catch (RuntimeException $e) {
                $this->assertMatchesRegularExpression(
                    "/\n - HEALTH_IP_ALLOWLIST is required in production(\n|$)/",
                    $e->getMessage(),
                    'I production ska HEALTH_IP_ALLOWLIST krävas.'
                );
            }
        }

        public function testValidateWhenAppEnvIsEmptyIncludesPresenceError(): void
        {
            // Dödar MethodCallRemoval av require('APP_ENV'):
            // enum('APP_ENV', ...) ger annars också fel, så vi kräver presence-bulleten.
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');
            $env = str_replace("APP_ENV=development\n", "APP_ENV=\n", $env);

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_appenv_empty_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            (new Dotenv($this->writeTempEnv($env), $basePath))->load();

            try {
                (new EnvValidator())->validate($basePath);
                $this->fail('Expected exception not thrown.');
            } catch (RuntimeException $e) {
                $this->assertMatchesRegularExpression(
                    "/\n - APP_ENV is required(\n|$)/",
                    $e->getMessage(),
                    'APP_ENV ska ha en presence-bullet.'
                );
            }
        }

        public function testValidateWhenAppUrlIsEmptyIncludesPresenceError(): void
        {
            // Dödar MethodCallRemoval av require('APP_URL'):
            // url('APP_URL') ger annars också fel, så vi kräver presence-bulleten.
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');
            $env = str_replace("APP_URL=http://localhost\n", "APP_URL=\n", $env);

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_appurl_empty_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            (new Dotenv($this->writeTempEnv($env), $basePath))->load();

            try {
                (new EnvValidator())->validate($basePath);
                $this->fail('Expected exception not thrown.');
            } catch (RuntimeException $e) {
                $this->assertMatchesRegularExpression(
                    "/\n - APP_URL is required(\n|$)/",
                    $e->getMessage(),
                    'APP_URL ska ha en presence-bullet, inte bara URL-fel.'
                );
            }
        }

        public function testValidateFailsWhenAppLangIsEmpty(): void
        {
            // Dödar MethodCallRemoval av require('APP_LANG'):
            // här finns ingen annan validering som fångar tomt värde.
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');
            $env = str_replace("APP_LANG=sv\n", "APP_LANG=\n", $env);

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_applang_empty_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            (new Dotenv($this->writeTempEnv($env), $basePath))->load();

            try {
                (new EnvValidator())->validate($basePath);
                $this->fail('Expected exception not thrown.');
            } catch (RuntimeException $e) {
                $this->assertMatchesRegularExpression(
                    "/\n - APP_LANG is required(\n|$)/",
                    $e->getMessage()
                );
            }
        }

        public function testValidateFailsWhenAppNameIsEmpty(): void
        {
            // Dödar MethodCallRemoval av require('APP_NAME'):
            // ingen annan validering för APP_NAME.
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');
            $env = str_replace("APP_NAME=Radix System\n", "APP_NAME=\n", $env);

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_appname_empty_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            (new Dotenv($this->writeTempEnv($env), $basePath))->load();

            try {
                (new EnvValidator())->validate($basePath);
                $this->fail('Expected exception not thrown.');
            } catch (RuntimeException $e) {
                $this->assertMatchesRegularExpression(
                    "/\n - APP_NAME is required(\n|$)/",
                    $e->getMessage()
                );
            }
        }

        public function testValidateWhenAppTimezoneIsEmptyIncludesPresenceError(): void
        {
            // Dödar MethodCallRemoval av require('APP_TIMEZONE'):
            // timezone('APP_TIMEZONE') ger annars också fel, så vi kräver presence-bulleten.
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');
            $env = str_replace("APP_TIMEZONE=Europe/Stockholm\n", "APP_TIMEZONE=\n", $env);

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_apptimezone_empty_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            (new Dotenv($this->writeTempEnv($env), $basePath))->load();

            try {
                (new EnvValidator())->validate($basePath);
                $this->fail('Expected exception not thrown.');
            } catch (RuntimeException $e) {
                $this->assertMatchesRegularExpression(
                    "/\n - APP_TIMEZONE is required(\n|$)/",
                    $e->getMessage(),
                    'APP_TIMEZONE ska ha presence-bullet, inte bara timezone-fel.'
                );
            }
        }

        public function testValidateFailsWhenSessionSameSiteNoneWithoutSecureTrue(): void
        {
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');
            $env = str_replace("SESSION_COOKIE_SECURE=auto\n", "SESSION_COOKIE_SECURE=false\n", $env);
            $env = str_replace("SESSION_COOKIE_SAMESITE=Lax\n", "SESSION_COOKIE_SAMESITE=None\n", $env);

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_session_cookie_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            (new Dotenv($this->writeTempEnv($env), $basePath))->load();

            try {
                (new EnvValidator())->validate($basePath);
                $this->fail('Expected exception not thrown.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString(
                    'SESSION_COOKIE_SECURE must be true when SESSION_COOKIE_SAMESITE=None',
                    $e->getMessage()
                );
            }
        }

        public function testValidateAcceptsSessionSameSiteNoneWithSecureTrue(): void
        {
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');
            $env = str_replace("SESSION_COOKIE_SECURE=auto\n", "SESSION_COOKIE_SECURE=true\n", $env);
            $env = str_replace("SESSION_COOKIE_SAMESITE=Lax\n", "SESSION_COOKIE_SAMESITE=None\n", $env);

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_session_cookie_ok_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            (new Dotenv($this->writeTempEnv($env), $basePath))->load();

            (new EnvValidator())->validate($basePath);

            $this->assertSame('None', getenv('SESSION_COOKIE_SAMESITE'));
        }

        public function testValidateFailsWhenSessionCookieSecureIsInvalid(): void
        {
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');
            $env = str_replace("SESSION_COOKIE_SECURE=auto\n", "SESSION_COOKIE_SECURE=maybe\n", $env);

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_session_cookie_secure_bad_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            (new Dotenv($this->writeTempEnv($env), $basePath))->load();

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Invalid environment configuration:');

            (new EnvValidator())->validate($basePath);
        }

        public function testValidateFailsWhenSessionCookieSameSiteIsInvalid(): void
        {
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');
            $env = str_replace("SESSION_COOKIE_SAMESITE=Lax\n", "SESSION_COOKIE_SAMESITE=Invalid\n", $env);

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_session_cookie_samesite_bad_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            (new Dotenv($this->writeTempEnv($env), $basePath))->load();

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Invalid environment configuration:');

            (new EnvValidator())->validate($basePath);
        }

        public function testValidateFailsWhenSessionCookieHttpOnlyIsInvalid(): void
        {
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');
            $env = str_replace("SESSION_COOKIE_HTTPONLY=true\n", "SESSION_COOKIE_HTTPONLY=maybe\n", $env);

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_session_cookie_httponly_bad_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            (new Dotenv($this->writeTempEnv($env), $basePath))->load();

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Invalid environment configuration:');

            (new EnvValidator())->validate($basePath);
        }

        public function testValidateWhenSessionCookieSecureIsEmptyHasPresenceBullet(): void
        {
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');
            $env = str_replace("SESSION_COOKIE_SECURE=auto\n", "SESSION_COOKIE_SECURE=\n", $env);

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_session_cookie_secure_empty_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            (new Dotenv($this->writeTempEnv($env), $basePath))->load();

            try {
                (new EnvValidator())->validate($basePath);
                $this->fail('Expected exception not thrown.');
            } catch (RuntimeException $e) {
                $this->assertMatchesRegularExpression(
                    "/\n - SESSION_COOKIE_SECURE is required(\n|$)/",
                    $e->getMessage(),
                    'SESSION_COOKIE_SECURE ska ha en egen presence-bullet när den är tom.'
                );
            }
        }

        public function testValidateWhenSessionCookieHttpOnlyIsEmptyHasPresenceBullet(): void
        {
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');
            $env = str_replace("SESSION_COOKIE_HTTPONLY=true\n", "SESSION_COOKIE_HTTPONLY=\n", $env);

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_session_cookie_httponly_empty_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            (new Dotenv($this->writeTempEnv($env), $basePath))->load();

            try {
                (new EnvValidator())->validate($basePath);
                $this->fail('Expected exception not thrown.');
            } catch (RuntimeException $e) {
                $this->assertMatchesRegularExpression(
                    "/\n - SESSION_COOKIE_HTTPONLY is required(\n|$)/",
                    $e->getMessage(),
                    'SESSION_COOKIE_HTTPONLY ska ha en egen presence-bullet när den är tom.'
                );
            }
        }

        public function testValidateWhenSessionCookieSameSiteIsEmptyHasPresenceBullet(): void
        {
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');
            $env = str_replace("SESSION_COOKIE_SAMESITE=Lax\n", "SESSION_COOKIE_SAMESITE=\n", $env);

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_session_cookie_samesite_empty_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            (new Dotenv($this->writeTempEnv($env), $basePath))->load();

            try {
                (new EnvValidator())->validate($basePath);
                $this->fail('Expected exception not thrown.');
            } catch (RuntimeException $e) {
                $this->assertMatchesRegularExpression(
                    "/\n - SESSION_COOKIE_SAMESITE is required(\n|$)/",
                    $e->getMessage(),
                    'SESSION_COOKIE_SAMESITE ska ha en egen presence-bullet när den är tom.'
                );
            }
        }

        public function testValidateAcceptsSessionSameSiteNoneWithUppercaseSecureTrue(): void
        {
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');
            $env = str_replace("SESSION_COOKIE_SECURE=auto\n", "SESSION_COOKIE_SECURE=TRUE\n", $env);
            $env = str_replace("SESSION_COOKIE_SAMESITE=Lax\n", "SESSION_COOKIE_SAMESITE=None\n", $env);

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_session_cookie_upper_true_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            (new Dotenv($this->writeTempEnv($env), $basePath))->load();

            (new EnvValidator())->validate($basePath);

            $this->assertSame('TRUE', getenv('SESSION_COOKIE_SECURE'));
        }

        public function testValidateFailsWhenSessionSameSiteNoneWithUppercaseFalse(): void
        {
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');
            $env = str_replace("SESSION_COOKIE_SECURE=auto\n", "SESSION_COOKIE_SECURE=FALSE\n", $env);
            $env = str_replace("SESSION_COOKIE_SAMESITE=Lax\n", "SESSION_COOKIE_SAMESITE=None\n", $env);

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_session_cookie_upper_false_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            (new Dotenv($this->writeTempEnv($env), $basePath))->load();

            try {
                (new EnvValidator())->validate($basePath);
                $this->fail('Expected exception not thrown.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString(
                    'SESSION_COOKIE_SECURE must be true when SESSION_COOKIE_SAMESITE=None',
                    $e->getMessage()
                );
            }
        }

        public function testValidateFailsWhenSessionSameSiteNoneWithSecureAuto(): void
        {
            $env = $this->baseEnv(appEnv: 'development', ormNamespace: '', healthAllowlist: '');
            $env = str_replace("SESSION_COOKIE_SAMESITE=Lax\n", "SESSION_COOKIE_SAMESITE=None\n", $env);

            $basePath = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'radix_envvalidator_session_cookie_none_auto_' . uniqid('', true);
            @mkdir($basePath, 0o755, true);

            (new Dotenv($this->writeTempEnv($env), $basePath))->load();

            try {
                (new EnvValidator())->validate($basePath);
                $this->fail('Expected exception not thrown.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString(
                    'SESSION_COOKIE_SECURE must be true when SESSION_COOKIE_SAMESITE=None',
                    $e->getMessage()
                );
            }
        }
    }
}
