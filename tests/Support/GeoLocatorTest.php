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
        public static ?bool $lastUseIncludePath = null;
        /** @var array<string,mixed>|null */
        public static ?array $lastContextOptions = null;

        public static function reset(): void
        {
            self::$useFake = false;
            self::$fakeBody = null;
            self::$lastFilename = null;
            self::$lastUseIncludePath = null;
            self::$lastContextOptions = null;
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
            putenv('GEOLOCATOR_BASE_URL');
            putenv('GEOLOCATOR_TIMEOUT');
            unset($_SERVER['REMOTE_ADDR']);
        }

        protected function tearDown(): void
        {
            GeoLocatorHttpSpy::reset();
            unset($_SERVER['REMOTE_ADDR']);
            putenv('GEOLOCATOR_BASE_URL');
            putenv('GEOLOCATOR_TIMEOUT');

            parent::tearDown();
        }

        /**
         * @param array<string,mixed> $response
         */
        private function fakeGeoLocatorResponse(array $response): void
        {
            GeoLocatorHttpSpy::reset();
            GeoLocatorHttpSpy::$useFake = true;

            $encoded = json_encode($response);

            $this->assertNotFalse($encoded);

            GeoLocatorHttpSpy::$fakeBody = $encoded;
        }

        private function lastHttpTimeout(): mixed
        {
            $options = GeoLocatorHttpSpy::$lastContextOptions;

            if (!is_array($options)) {
                return null;
            }

            $httpOptions = $options['http'] ?? null;

            if (!is_array($httpOptions)) {
                return null;
            }

            return $httpOptions['timeout'] ?? null;
        }

        private function fakeSuccessfulGeoLocatorResponse(): void
        {
            $this->fakeGeoLocatorResponse([
                'status' => 'success',
                'country' => 'Sweden',
                'city' => 'Stockholm',
            ]);
        }

        public function testGetLocationThrowsExceptionOnApiError(): void
        {
            $this->fakeGeoLocatorResponse([
                'status' => 'fail',
                'message' => 'invalid query',
            ]);

            $geoLocator = new GeoLocator();

            $this->expectException(GeoLocatorException::class);
            $this->expectExceptionMessage('API fel: invalid query');

            $geoLocator->getLocation('256.256.256.256');
        }

        public function testGetLocationThrowsExceptionOnNetworkError(): void
        {
            GeoLocatorHttpSpy::reset();
            GeoLocatorHttpSpy::$useFake = true;
            GeoLocatorHttpSpy::$fakeBody = null;

            $geoLocator = new GeoLocator('http://nonexistent-domain.test');

            $this->expectException(GeoLocatorException::class);
            $this->expectExceptionMessage('Ogiltig API-respons');

            $geoLocator->getLocation('8.8.8.8');
        }

        public function testGetSpecificValue(): void
        {
            $this->fakeSuccessfulGeoLocatorResponse();

            $geoLocator = new GeoLocator();

            $country = $geoLocator->get('country', '8.8.8.8');

            $this->assertSame('Sweden', $country);
        }

        public function testGetReturnsNullForMissingKey(): void
        {
            $this->fakeSuccessfulGeoLocatorResponse();

            $geoLocator = new GeoLocator();

            $value = $geoLocator->get('missing-key', '8.8.8.8');

            $this->assertNull($value);
        }

        public function testGetLocationSuccess(): void
        {
            $this->fakeSuccessfulGeoLocatorResponse();

            $geoLocator = new GeoLocator();

            $location = $geoLocator->getLocation('8.8.8.8');

            $this->assertSame('success', $location['status']);
            $this->assertSame('Sweden', $location['country']);
            $this->assertSame('Stockholm', $location['city']);
        }

        public function testGetLocationTrimsServerRemoteAddrWhenIpIsNull(): void
        {
            $this->fakeSuccessfulGeoLocatorResponse();

            $_SERVER['REMOTE_ADDR'] = ' 8.8.4.4 ';

            $geoLocator = new GeoLocator('http://example.test/json');

            $geoLocator->getLocation();

            $this->assertSame(
                'http://example.test/json/8.8.4.4',
                GeoLocatorHttpSpy::$lastFilename,
                'REMOTE_ADDR ska trimmas innan URL byggs när explicit IP saknas.'
            );
        }

        public function testGetLocationUsesEmptyIpWhenRemoteAddrIsMissing(): void
        {
            $this->fakeSuccessfulGeoLocatorResponse();

            $geoLocator = new GeoLocator('http://example.test/json');

            $geoLocator->getLocation();

            $this->assertSame(
                'http://example.test/json/',
                GeoLocatorHttpSpy::$lastFilename
            );
        }

        public function testConstructorBaseUrlArgumentOverridesEnvironmentBaseUrl(): void
        {
            $this->fakeSuccessfulGeoLocatorResponse();

            putenv('GEOLOCATOR_BASE_URL=http://env.example.test/json');

            $geoLocator = new GeoLocator('http://constructor.example.test/json');
            $geoLocator->getLocation('1.2.3.4');

            $this->assertSame(
                'http://constructor.example.test/json/1.2.3.4',
                GeoLocatorHttpSpy::$lastFilename
            );
        }

        public function testConstructorTimeoutArgumentOverridesEnvironmentTimeout(): void
        {
            $this->fakeSuccessfulGeoLocatorResponse();

            putenv('GEOLOCATOR_TIMEOUT=9');

            $geoLocator = new GeoLocator(timeout: 4);
            $geoLocator->getLocation('1.2.3.4');

            $this->assertSame(
                4,
                $this->lastHttpTimeout()
            );
        }

        public function testEnvironmentBaseUrlIsUsedWhenConstructorBaseUrlIsMissing(): void
        {
            $this->fakeSuccessfulGeoLocatorResponse();

            putenv('GEOLOCATOR_BASE_URL=http://env.example.test/json');

            $geoLocator = new GeoLocator();
            $geoLocator->getLocation('5.6.7.8');

            $this->assertSame(
                'http://env.example.test/json/5.6.7.8',
                GeoLocatorHttpSpy::$lastFilename
            );
        }

        public function testEnvironmentBaseUrlIsTrimmedAndTrailingSlashIsRemoved(): void
        {
            $this->fakeSuccessfulGeoLocatorResponse();

            putenv('GEOLOCATOR_BASE_URL=  http://env.example.test/json/  ');

            $geoLocator = new GeoLocator();
            $geoLocator->getLocation('5.6.7.8');

            $this->assertSame(
                'http://env.example.test/json/5.6.7.8',
                GeoLocatorHttpSpy::$lastFilename
            );
        }

        public function testWhitespaceOnlyEnvironmentBaseUrlIsIgnored(): void
        {
            $this->fakeSuccessfulGeoLocatorResponse();

            putenv('GEOLOCATOR_BASE_URL=   ');

            $geoLocator = new GeoLocator();
            $geoLocator->getLocation('5.6.7.8');

            $this->assertSame(
                'http://ip-api.com/json/5.6.7.8',
                GeoLocatorHttpSpy::$lastFilename
            );
        }

        public function testPositiveIntegerConstructorTimeoutIsUsed(): void
        {
            $this->fakeSuccessfulGeoLocatorResponse();

            $geoLocator = new GeoLocator(timeout: 3);
            $geoLocator->getLocation('1.2.3.4');

            $this->assertSame(
                3,
                $this->lastHttpTimeout()
            );
        }

        public function testZeroConstructorTimeoutIsIgnored(): void
        {
            $this->fakeSuccessfulGeoLocatorResponse();

            $geoLocator = new GeoLocator(timeout: 0);
            $geoLocator->getLocation('1.2.3.4');

            $this->assertSame(
                2,
                $this->lastHttpTimeout()
            );
        }

        public function testNegativeConstructorTimeoutIsIgnored(): void
        {
            $this->fakeSuccessfulGeoLocatorResponse();

            $geoLocator = new GeoLocator(timeout: -1);
            $geoLocator->getLocation('1.2.3.4');

            $this->assertSame(
                2,
                $this->lastHttpTimeout()
            );
        }

        public function testPositiveNumericEnvironmentTimeoutIsUsed(): void
        {
            $this->fakeSuccessfulGeoLocatorResponse();

            putenv('GEOLOCATOR_TIMEOUT=7');

            $geoLocator = new GeoLocator();
            $geoLocator->getLocation('1.2.3.4');

            $this->assertSame(
                7,
                $this->lastHttpTimeout()
            );
        }

        public function testZeroEnvironmentTimeoutIsIgnored(): void
        {
            $this->fakeSuccessfulGeoLocatorResponse();

            putenv('GEOLOCATOR_TIMEOUT=0');

            $geoLocator = new GeoLocator();
            $geoLocator->getLocation('1.2.3.4');

            $this->assertSame(
                2,
                $this->lastHttpTimeout()
            );
        }

        public function testNegativeEnvironmentTimeoutIsIgnored(): void
        {
            $this->fakeSuccessfulGeoLocatorResponse();

            putenv('GEOLOCATOR_TIMEOUT=-1');

            $geoLocator = new GeoLocator();
            $geoLocator->getLocation('1.2.3.4');

            $this->assertSame(
                2,
                $this->lastHttpTimeout()
            );
        }

        public function testNonNumericEnvironmentTimeoutIsIgnored(): void
        {
            $this->fakeSuccessfulGeoLocatorResponse();

            putenv('GEOLOCATOR_TIMEOUT=abc');

            $geoLocator = new GeoLocator();
            $geoLocator->getLocation('1.2.3.4');

            $this->assertSame(
                2,
                $this->lastHttpTimeout()
            );
        }

        public function testWhitespaceEnvironmentTimeoutIsIgnored(): void
        {
            $this->fakeSuccessfulGeoLocatorResponse();

            putenv('GEOLOCATOR_TIMEOUT=   ');

            $geoLocator = new GeoLocator();
            $geoLocator->getLocation('1.2.3.4');

            $this->assertSame(
                2,
                $this->lastHttpTimeout()
            );
        }

        public function testDecimalEnvironmentTimeoutIsIgnored(): void
        {
            $this->fakeSuccessfulGeoLocatorResponse();

            putenv('GEOLOCATOR_TIMEOUT=2.5');

            $geoLocator = new GeoLocator();
            $geoLocator->getLocation('1.2.3.4');

            $this->assertSame(
                2,
                $this->lastHttpTimeout()
            );
        }

        public function testDecimalEnvironmentTimeoutGreaterThanDefaultIsIgnored(): void
        {
            $this->fakeSuccessfulGeoLocatorResponse();

            putenv('GEOLOCATOR_TIMEOUT=3.5');

            $geoLocator = new GeoLocator();
            $geoLocator->getLocation('1.2.3.4');

            $this->assertSame(
                2,
                $this->lastHttpTimeout()
            );
        }

        public function testFileGetContentsDoesNotUseIncludePath(): void
        {
            $this->fakeSuccessfulGeoLocatorResponse();

            $geoLocator = new GeoLocator();
            $geoLocator->getLocation('1.2.3.4');

            $this->assertFalse(GeoLocatorHttpSpy::$lastUseIncludePath);
        }

        public function testGetLocationUsesUnescapedJsonForNonStringApiErrorMessage(): void
        {
            $this->fakeGeoLocatorResponse([
                'status' => 'fail',
                'message' => [
                    'url' => 'https://example.test/väg',
                ],
            ]);

            $geoLocator = new GeoLocator();

            $this->expectException(GeoLocatorException::class);
            $this->expectExceptionMessage('API fel: {"url":"https://example.test/väg"}');

            $geoLocator->getLocation('256.256.256.256');
        }

        public function testHttpTimeoutContextIsPassedToFileGetContents(): void
        {
            $this->fakeSuccessfulGeoLocatorResponse();

            $geoLocator = new GeoLocator(timeout: 6);
            $geoLocator->getLocation('1.2.3.4');

            $this->assertSame(
                [
                    'http' => [
                        'timeout' => 6,
                    ],
                ],
                GeoLocatorHttpSpy::$lastContextOptions
            );
        }

        /**
         * Dödar LogicalOr-mutanten i GeoLocator::getLocation genom att simulera
         * en API-respons som är en array men saknar 'status'-nyckeln.
         */
        public function testGetLocationThrowsOnArrayWithoutStatusKey(): void
        {
            $this->fakeGeoLocatorResponse(['foo' => 'bar']);

            $geoLocator = new GeoLocator();

            $this->expectException(GeoLocatorException::class);
            $this->expectExceptionMessage('Ogiltig API-respons');

            $geoLocator->getLocation('1.2.3.4');
        }

        public function testGetLocationThrowsOnNonArrayJsonResponse(): void
        {
            GeoLocatorHttpSpy::reset();
            GeoLocatorHttpSpy::$useFake = true;
            GeoLocatorHttpSpy::$fakeBody = '"not an array"';

            $geoLocator = new GeoLocator();

            $this->expectException(GeoLocatorException::class);
            $this->expectExceptionMessage('Ogiltig API-respons');

            $geoLocator->getLocation('1.2.3.4');
        }

        public function testGetLocationUsesJsonEncodedMessageWhenApiErrorMessageIsNotString(): void
        {
            $this->fakeGeoLocatorResponse([
                'status' => 'fail',
                'message' => [
                    'code' => 'invalid query',
                ],
            ]);

            $geoLocator = new GeoLocator();

            $this->expectException(GeoLocatorException::class);
            $this->expectExceptionMessage('API fel: {"code":"invalid query"}');

            $geoLocator->getLocation('256.256.256.256');
        }

        public function testGetLocationUsesDefaultMessageWhenApiErrorMessageIsMissing(): void
        {
            $this->fakeGeoLocatorResponse([
                'status' => 'fail',
            ]);

            $geoLocator = new GeoLocator();

            $this->expectException(GeoLocatorException::class);
            $this->expectExceptionMessage('API fel: okänt fel');

            $geoLocator->getLocation('256.256.256.256');
        }
    }
}
