<?php

declare(strict_types=1);

namespace {
    require_once __DIR__ . '/RadixSupportFsOverrides.php';
}

namespace Radix\Support {
    /**
     * Spy för HTTP-anrop i GeoLocator–tester.
     */
    class GeoLocatorHttpSpy
    {
        public static bool $useFake = false;
        public static ?string $fakeBody = null;
        public static ?string $lastFilename = null;
        public static ?bool $lastUseIncludePath = null;
        public static mixed $lastContext = null;

        public static function reset(): void
        {
            self::$useFake = false;
            self::$fakeBody = null;
            self::$lastFilename = null;
            self::$lastUseIncludePath = null;
            self::$lastContext = null;
        }
    }

    // OBS: Ta bort den lokala file_get_contents()-override:n härifrån.
    // Den ligger nu i tests/Support/RadixSupportFsOverrides.php
}

namespace Radix\Tests\Support {

    use PHPUnit\Framework\TestCase;
    use Radix\Http\Exception\GeoLocatorException;
    use Radix\Support\GeoLocator;
    use Radix\Support\GeoLocatorHttpSpy;

    final class GeoLocatorTest extends TestCase
    {
        protected function setUp(): void
        {
            parent::setUp();

            putenv('GEOLOCATOR_ENABLED');
            putenv('GEOLOCATOR_BASE_URL');
            putenv('GEOLOCATOR_TIMEOUT');
            unset($_ENV['GEOLOCATOR_ENABLED'], $_ENV['GEOLOCATOR_BASE_URL'], $_ENV['GEOLOCATOR_TIMEOUT']);
            unset($_SERVER['GEOLOCATOR_ENABLED'], $_SERVER['GEOLOCATOR_BASE_URL'], $_SERVER['GEOLOCATOR_TIMEOUT']);

            GeoLocatorHttpSpy::reset();
        }

        protected function tearDown(): void
        {
            GeoLocatorHttpSpy::reset();

            putenv('GEOLOCATOR_ENABLED');
            putenv('GEOLOCATOR_BASE_URL');
            putenv('GEOLOCATOR_TIMEOUT');
            unset($_ENV['GEOLOCATOR_ENABLED'], $_ENV['GEOLOCATOR_BASE_URL'], $_ENV['GEOLOCATOR_TIMEOUT']);
            unset($_SERVER['GEOLOCATOR_ENABLED'], $_SERVER['GEOLOCATOR_BASE_URL'], $_SERVER['GEOLOCATOR_TIMEOUT']);

            parent::tearDown();
        }


        public function testGetLocationThrowsExceptionOnInvalidIp(): void
        {
            $geoLocator = new GeoLocator();

            $this->expectException(GeoLocatorException::class);
            $this->expectExceptionMessage('Ogiltig IP-adress för geolocation: 256.256.256.256');

            $geoLocator->getLocation('256.256.256.256');
        }

        public function testGetLocationThrowsExceptionOnApiErrorResponse(): void
        {
            GeoLocatorHttpSpy::reset();
            GeoLocatorHttpSpy::$useFake = true;

            $encoded = json_encode([
                'status' => 'fail',
                'message' => 'invalid query',
            ]);

            $this->assertNotFalse($encoded);
            GeoLocatorHttpSpy::$fakeBody = $encoded;

            $geoLocator = new GeoLocator();

            $this->expectException(GeoLocatorException::class);
            $this->expectExceptionMessage('GeoLocator API fel: invalid query');

            $geoLocator->getLocation('8.8.8.8');
        }

        public function testGetLocationThrowsExceptionOnNetworkError(): void
        {
            $geoLocator = new GeoLocator();

            // Mocka ett nätverksfel genom att ändra baseUrl
            $reflection = new \ReflectionClass($geoLocator);
            $property = $reflection->getProperty('baseUrl');
            $property->setValue($geoLocator, 'http://nonexistent-domain.test');

            $this->expectException(GeoLocatorException::class);
            $this->expectExceptionMessage('Kunde inte nå GeoLocator API');

            // Testa med en giltig IP men ogiltigt nätverk
            $geoLocator->getLocation('8.8.8.8');
        }

        public function testGetSpecificValue(): void
        {
            $geoLocator = new GeoLocator();

            // Hämta land för 8.8.8.8
            $country = $geoLocator->get('country', '8.8.8.8');

            $this->assertIsString($country);
            $this->assertEquals('United States', $country);
        }

        public function testGetLocationSuccess(): void
        {
            $geoLocator = new GeoLocator();

            $location = $geoLocator->getLocation('8.8.8.8');

            $this->assertNotEmpty($location);
            $this->assertArrayHasKey('country', $location);
            $this->assertEquals('United States', $location['country']);
        }

        /**
         * Dödar LogicalOr-mutanten i GeoLocator::getLocation genom att simulera
         * en API-respons som är en array men saknar 'status'-nyckeln.
         */
        public function testGetLocationThrowsOnArrayWithoutStatusKey(): void
        {
            GeoLocatorHttpSpy::reset();
            GeoLocatorHttpSpy::$useFake = true;

            $encoded = json_encode(['foo' => 'bar']);
            // json_encode ska inte returnera false här, men typmässigt kan den det
            $this->assertNotFalse($encoded);
            GeoLocatorHttpSpy::$fakeBody = $encoded;

            $geoLocator = new GeoLocator();

            $this->expectException(GeoLocatorException::class);
            $this->expectExceptionMessage('Ogiltig GeoLocator API-respons');

            // IP spelar ingen roll, då vi använder fake-HTTP-svar
            $geoLocator->getLocation('1.2.3.4');
        }



        public function testConstructorEnabledArgumentOverridesEnvironment(): void
        {
            putenv('GEOLOCATOR_ENABLED=true');
            $_ENV['GEOLOCATOR_ENABLED'] = 'true';
            $_SERVER['GEOLOCATOR_ENABLED'] = 'true';

            $geoLocator = new GeoLocator(enabled: false);

            $this->expectException(GeoLocatorException::class);
            $this->expectExceptionMessage('GeoLocator är avstängd via konfiguration.');

            $geoLocator->getLocation('8.8.8.8');
        }

        public function testRequestUsesConfiguredStreamContextAndDoesNotUseIncludePath(): void
        {
            GeoLocatorHttpSpy::reset();
            GeoLocatorHttpSpy::$useFake = true;

            $encoded = json_encode([
                'status' => 'success',
                'country' => 'United States',
            ]);

            $this->assertNotFalse($encoded);
            GeoLocatorHttpSpy::$fakeBody = $encoded;

            $geoLocator = new GeoLocator(
                baseUrl: 'http://example.test/json',
                timeoutSeconds: 3,
                enabled: true
            );

            $location = $geoLocator->getLocation('8.8.8.8');

            $this->assertSame('United States', $location['country']);
            $this->assertSame('http://example.test/json/8.8.8.8', GeoLocatorHttpSpy::$lastFilename);
            $this->assertFalse(
                GeoLocatorHttpSpy::$lastUseIncludePath,
                'GeoLocator ska anropa file_get_contents() med use_include_path=false.'
            );

            $this->assertIsResource(GeoLocatorHttpSpy::$lastContext);

            /** @var resource $context */
            $context = GeoLocatorHttpSpy::$lastContext;
            $options = stream_context_get_options($context);

            $this->assertSame(3, $options['http']['timeout'] ?? null);
            $this->assertTrue($options['http']['ignore_errors'] ?? false);
            $this->assertSame("Accept: application/json\r\n", $options['http']['header'] ?? null);
        }

        public function testResolveIpTrimsExplicitIpBeforeValidationAndRequest(): void
        {
            GeoLocatorHttpSpy::reset();
            GeoLocatorHttpSpy::$useFake = true;

            $encoded = json_encode([
                'status' => 'success',
                'country' => 'United States',
            ]);

            $this->assertNotFalse($encoded);
            GeoLocatorHttpSpy::$fakeBody = $encoded;

            $geoLocator = new GeoLocator(baseUrl: 'http://example.test/json', enabled: true);

            $geoLocator->getLocation(' 8.8.8.8 ');

            $this->assertSame(
                'http://example.test/json/8.8.8.8',
                GeoLocatorHttpSpy::$lastFilename,
                'IP-adressen ska trimmas innan URL byggs.'
            );
        }

        public function testResolveIpFallsBackToServerRemoteAddrWhenExplicitIpIsNull(): void
        {
            GeoLocatorHttpSpy::reset();
            GeoLocatorHttpSpy::$useFake = true;

            $encoded = json_encode([
                'status' => 'success',
                'country' => 'United States',
            ]);

            $this->assertNotFalse($encoded);
            GeoLocatorHttpSpy::$fakeBody = $encoded;

            $_SERVER['REMOTE_ADDR'] = '8.8.4.4';

            $geoLocator = new GeoLocator(baseUrl: 'http://example.test/json', enabled: true);

            $geoLocator->getLocation();

            $this->assertSame('http://example.test/json/8.8.4.4', GeoLocatorHttpSpy::$lastFilename);
        }

        public function testConstructorBaseUrlIsTrimmedAndTrailingSlashIsRemoved(): void
        {
            GeoLocatorHttpSpy::reset();
            GeoLocatorHttpSpy::$useFake = true;

            $encoded = json_encode([
                'status' => 'success',
                'country' => 'United States',
            ]);

            $this->assertNotFalse($encoded);
            GeoLocatorHttpSpy::$fakeBody = $encoded;

            $geoLocator = new GeoLocator(baseUrl: '  http://example.test/json/  ', enabled: true);

            $geoLocator->getLocation('8.8.8.8');

            $this->assertSame('http://example.test/json/8.8.8.8', GeoLocatorHttpSpy::$lastFilename);
        }

        public function testWhitespaceConstructorBaseUrlFallsBackToEnvironmentBaseUrl(): void
        {
            GeoLocatorHttpSpy::reset();
            GeoLocatorHttpSpy::$useFake = true;

            putenv('GEOLOCATOR_BASE_URL=http://env.example.test/json');
            $_ENV['GEOLOCATOR_BASE_URL'] = 'http://env.example.test/json';
            $_SERVER['GEOLOCATOR_BASE_URL'] = 'http://env.example.test/json';

            $encoded = json_encode([
                'status' => 'success',
                'country' => 'United States',
            ]);

            $this->assertNotFalse($encoded);
            GeoLocatorHttpSpy::$fakeBody = $encoded;

            $geoLocator = new GeoLocator(baseUrl: '   ', enabled: true);

            $geoLocator->getLocation('8.8.8.8');

            $this->assertSame('http://env.example.test/json/8.8.8.8', GeoLocatorHttpSpy::$lastFilename);
        }

        public function testEnvironmentBaseUrlIsTrimmedAndTrailingSlashIsRemoved(): void
        {
            GeoLocatorHttpSpy::reset();
            GeoLocatorHttpSpy::$useFake = true;

            putenv('GEOLOCATOR_BASE_URL=  http://env.example.test/json/  ');
            $_ENV['GEOLOCATOR_BASE_URL'] = '  http://env.example.test/json/  ';
            $_SERVER['GEOLOCATOR_BASE_URL'] = '  http://env.example.test/json/  ';

            $encoded = json_encode([
                'status' => 'success',
                'country' => 'United States',
            ]);

            $this->assertNotFalse($encoded);
            GeoLocatorHttpSpy::$fakeBody = $encoded;

            $geoLocator = new GeoLocator(enabled: true);

            $geoLocator->getLocation('8.8.8.8');

            $this->assertSame('http://env.example.test/json/8.8.8.8', GeoLocatorHttpSpy::$lastFilename);
        }

        public function testWhitespaceEnvironmentBaseUrlFallsBackToDefaultBaseUrl(): void
        {
            GeoLocatorHttpSpy::reset();
            GeoLocatorHttpSpy::$useFake = true;

            putenv('GEOLOCATOR_BASE_URL=   ');
            $_ENV['GEOLOCATOR_BASE_URL'] = '   ';
            $_SERVER['GEOLOCATOR_BASE_URL'] = '   ';

            $encoded = json_encode([
                'status' => 'success',
                'country' => 'United States',
            ]);

            $this->assertNotFalse($encoded);
            GeoLocatorHttpSpy::$fakeBody = $encoded;

            $geoLocator = new GeoLocator(enabled: true);

            $geoLocator->getLocation('8.8.8.8');

            $this->assertSame('http://ip-api.com/json/8.8.8.8', GeoLocatorHttpSpy::$lastFilename);
        }

        public function testConstructorTimeoutMustBePositiveOtherwiseEnvironmentTimeoutIsUsed(): void
        {
            GeoLocatorHttpSpy::reset();
            GeoLocatorHttpSpy::$useFake = true;

            putenv('GEOLOCATOR_TIMEOUT=4');
            $_ENV['GEOLOCATOR_TIMEOUT'] = '4';
            $_SERVER['GEOLOCATOR_TIMEOUT'] = '4';

            $encoded = json_encode([
                'status' => 'success',
                'country' => 'United States',
            ]);

            $this->assertNotFalse($encoded);
            GeoLocatorHttpSpy::$fakeBody = $encoded;

            $geoLocator = new GeoLocator(timeoutSeconds: 0, enabled: true);

            $geoLocator->getLocation('8.8.8.8');

            $this->assertIsResource(GeoLocatorHttpSpy::$lastContext);

            /** @var resource $context */
            $context = GeoLocatorHttpSpy::$lastContext;
            $options = stream_context_get_options($context);

            $this->assertSame(4, $options['http']['timeout'] ?? null);
        }

        public function testInvalidEnvironmentTimeoutFallsBackToDefaultTimeout(): void
        {
            GeoLocatorHttpSpy::reset();
            GeoLocatorHttpSpy::$useFake = true;

            putenv('GEOLOCATOR_TIMEOUT=not-an-int');
            $_ENV['GEOLOCATOR_TIMEOUT'] = 'not-an-int';
            $_SERVER['GEOLOCATOR_TIMEOUT'] = 'not-an-int';

            $encoded = json_encode([
                'status' => 'success',
                'country' => 'United States',
            ]);

            $this->assertNotFalse($encoded);
            GeoLocatorHttpSpy::$fakeBody = $encoded;

            $geoLocator = new GeoLocator(enabled: true);

            $geoLocator->getLocation('8.8.8.8');

            $this->assertIsResource(GeoLocatorHttpSpy::$lastContext);

            /** @var resource $context */
            $context = GeoLocatorHttpSpy::$lastContext;
            $options = stream_context_get_options($context);

            $this->assertSame(2, $options['http']['timeout'] ?? null);
        }

        public function testEnvironmentTimeoutIsCappedAtTenSeconds(): void
        {
            GeoLocatorHttpSpy::reset();
            GeoLocatorHttpSpy::$useFake = true;

            putenv('GEOLOCATOR_TIMEOUT=99');
            $_ENV['GEOLOCATOR_TIMEOUT'] = '99';
            $_SERVER['GEOLOCATOR_TIMEOUT'] = '99';

            $encoded = json_encode([
                'status' => 'success',
                'country' => 'United States',
            ]);

            $this->assertNotFalse($encoded);
            GeoLocatorHttpSpy::$fakeBody = $encoded;

            $geoLocator = new GeoLocator(enabled: true);

            $geoLocator->getLocation('8.8.8.8');

            $this->assertIsResource(GeoLocatorHttpSpy::$lastContext);

            /** @var resource $context */
            $context = GeoLocatorHttpSpy::$lastContext;
            $options = stream_context_get_options($context);

            $this->assertSame(10, $options['http']['timeout'] ?? null);
        }
    }
}
