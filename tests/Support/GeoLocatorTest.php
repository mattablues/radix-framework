<?php

declare(strict_types=1);

namespace {
    require_once __DIR__ . '/RadixSupportFsOverrides.php';
}

namespace Radix\Support {
    /**
     * Spy för HTTP-anrop i GeoLocator-tester.
     */
    class GeoLocatorHttpSpy
    {
        public static bool $useFake = false;
        public static ?string $fakeBody = null;
        public static ?string $lastFilename = null;

        public static function reset(): void
        {
            self::$useFake = false;
            self::$fakeBody = null;
            self::$lastFilename = null;
        }
    }

    // OBS: file_get_contents()-override ligger i tests/Support/RadixSupportFsOverrides.php
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

            GeoLocatorHttpSpy::reset();
        }

        protected function tearDown(): void
        {
            GeoLocatorHttpSpy::reset();
            unset($_SERVER['REMOTE_ADDR']);

            parent::tearDown();
        }

        public function testGetLocationThrowsExceptionOnApiError(): void
        {
            $geoLocator = new GeoLocator();

            // Förvänta undantag för API-fel
            $this->expectException(GeoLocatorException::class);
            $this->expectExceptionMessage('API fel: invalid query');

            // Testa med en IP som leder till API-fel
            $geoLocator->getLocation('256.256.256.256');
        }

        public function testGetLocationThrowsExceptionOnNetworkError(): void
        {
            $geoLocator = new GeoLocator();

            // Mocka ett nätverksfel genom att ändra baseUrl
            $reflection = new \ReflectionClass($geoLocator);
            $property = $reflection->getProperty('baseUrl');
            $property->setValue($geoLocator, 'http://nonexistent-domain.test');

            $this->expectException(GeoLocatorException::class);
            $this->expectExceptionMessage('Kunde inte nå API');

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

        public function testGetLocationTrimsServerRemoteAddrWhenIpIsNull(): void
        {
            GeoLocatorHttpSpy::reset();
            GeoLocatorHttpSpy::$useFake = true;

            $encoded = json_encode([
                'status' => 'success',
                'country' => 'United States',
            ]);

            $this->assertNotFalse($encoded);
            GeoLocatorHttpSpy::$fakeBody = $encoded;

            $_SERVER['REMOTE_ADDR'] = ' 8.8.4.4 ';

            $geoLocator = new GeoLocator();

            $reflection = new \ReflectionClass($geoLocator);
            $property = $reflection->getProperty('baseUrl');
            $property->setValue($geoLocator, 'http://example.test/json');

            $geoLocator->getLocation();

            $this->assertSame(
                'http://example.test/json/8.8.4.4',
                GeoLocatorHttpSpy::$lastFilename,
                'REMOTE_ADDR ska trimmas innan URL byggs när explicit IP saknas.'
            );
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
            $this->expectExceptionMessage('Ogiltig API-respons');

            // IP spelar ingen roll, då vi använder fake-HTTP-svar
            $geoLocator->getLocation('1.2.3.4');
        }
    }
}
