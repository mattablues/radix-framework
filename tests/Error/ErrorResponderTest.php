<?php

declare(strict_types=1);

namespace Radix\Tests\Error;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Radix\Error\ErrorResponder;
use Radix\Http\Request;

final class ErrorResponderTest extends TestCase
{
    private string $testViewDir;
    private int $startObLevel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->startObLevel = ob_get_level();

        $this->testViewDir = __DIR__ . '/temp_error_views';
        if (!is_dir($this->testViewDir)) {
            mkdir($this->testViewDir, 0o777, true);
        }

        ErrorResponder::$viewPath = $this->testViewDir;
        file_put_contents($this->testViewDir . '/404.php', '<html>Test 404: <?php echo $errorMessage; ?></html>');
        file_put_contents($this->testViewDir . '/500.php', '<html>Test 500: <?php echo $errorStatus; ?></html>');

        if (!defined('ROOT_PATH')) {
            define('ROOT_PATH', dirname(__DIR__, 2));
        }
    }

    protected function tearDown(): void
    {
        while (ob_get_level() > $this->startObLevel) {
            ob_end_clean();
        }

        ErrorResponder::$viewPath = null;
        if (is_dir($this->testViewDir)) {
            $files = glob($this->testViewDir . '/*');
            if ($files !== false) {
                foreach ($files as $file) {
                    if (is_file($file)) {
                        unlink($file);
                    }
                }
            }
            rmdir($this->testViewDir);
        }
        parent::tearDown();
    }

    public function testRespondCleansUpTo51NestedOutputBuffersOnThrowable(): void
    {
        $request = $this->makeRequest('/test');
        $levelBefore = ob_get_level();

        // Skapa en vy som lägger 51 buffertnivåer ovanpå respond():s egen ob_start(),
        // och sedan kastar → fångas i ErrorResponder::respond().
        file_put_contents(
            $this->testViewDir . '/404.php',
            <<<'PHP'
                <?php
                for ($i = 0; $i < 51; $i++) {
                    ob_start();
                }
                throw new \Exception('boom');
                PHP
        );

        $response = ErrorResponder::respond($request, 404, 'DeepBuffers');

        $this->assertSame('<h1>404 | DeepBuffers</h1>', (string) $response->getBody());
        $this->assertSame($levelBefore, ob_get_level(), 'Output buffer-nivån ska återställas även vid djupa nästlade buffertar.');
    }

    /**
     * Hjälpare för att skapa Request med korrekt signatur.
     *
     * @param array<string, mixed> $server
     */
    private function makeRequest(string $uri, string $method = 'GET', array $server = []): Request
    {
        /** @var array<string, mixed> $get */
        $get = [];
        /** @var array<string, mixed> $post */
        $post = [];
        /** @var array<string, mixed> $files */
        $files = [];
        /** @var array<string, mixed> $cookie */
        $cookie = [];

        return new Request(
            uri: $uri,
            method: $method,
            get: $get,
            post: $post,
            files: $files,
            cookie: $cookie,
            server: $server,
        );
    }

    public function testRespondForApiRequestReturnsJsonResponse(): void
    {
        $request   = $this->makeRequest('/api/v1/users', 'GET');
        $status    = 400;
        $message   = 'Bad request';
        $jsonExtra = ['foo' => 'bar'];

        $response = ErrorResponder::respond($request, $status, $message, $jsonExtra);

        $this->assertSame($status, $response->getStatusCode());
        $this->assertSame('application/json; charset=UTF-8', $response->headers()['Content-Type'] ?? null);

        $body    = (string) $response->getBody();
        $decoded = json_decode($body, true);

        $this->assertIsArray($decoded);
        $this->assertSame($message, $decoded['error'] ?? null);
        $this->assertSame('bar', $decoded['foo'] ?? null);
    }

    public function testRespondDoesNotTreatNonRootedApiPathAsApi(): void
    {
        $request = $this->makeRequest('/foo/api/v1/users', 'GET');
        $response = ErrorResponder::respond($request, 404, 'Not found');
        $this->assertSame('text/html; charset=UTF-8', $response->headers()['Content-Type'] ?? null);
    }

    public function testRespondWebUsesSpecificErrorViewWhenItExists(): void
    {
        $request = $this->makeRequest('/some/normal/page', 'GET');
        $status  = 404;
        $message = 'Page not found';

        $response = ErrorResponder::respond($request, $status, $message);

        $this->assertSame($status, $response->getStatusCode());
        $this->assertSame('text/html; charset=UTF-8', $response->headers()['Content-Type'] ?? null);

        $body = (string) $response->getBody();
        $this->assertStringContainsString('Test 404: Page not found', $body);
    }

    public function testRespondWebFallsBackTo500ViewWhenSpecificViewDoesNotExist(): void
    {
        $request = $this->makeRequest('/another/normal/page', 'GET');
        $response = ErrorResponder::respond($request, 599, 'Unexpected error');
        $this->assertStringContainsString('Test 500: 599', (string) $response->getBody());
    }

    // Om du vill behålla vissa tester som "slow", markera dem individuellt:
    #[Group('slow')]
    public function testRespondUsesCorrectDefaultPathLogic(): void
    {
        $request = $this->makeRequest('/test');
        $oldPath = ErrorResponder::$viewPath;

        // Vi tvingar ErrorResponder att använda standard-logiken
        ErrorResponder::$viewPath = null;

        // För att döda Concat-mutanten måste vi verifiera att den letar på RÄTT ställe.
        // Vi skapar en fil i den faktiska förväntade mappen.
        $realErrorDir = ROOT_PATH . '/views/errors';
        if (!is_dir($realErrorDir)) {
            mkdir($realErrorDir, 0o777, true);
        }
        $testFile = $realErrorDir . '/999.php';
        file_put_contents($testFile, 'DEFAULT_PATH_MATCH');

        try {
            $response = ErrorResponder::respond($request, 999, 'msg');
            // Om Concat-mutanten ändrar ordning kommer den inte hitta filen och returnera <h1> istället.
            $this->assertSame('DEFAULT_PATH_MATCH', (string) $response->getBody(), 'Sökvägsbygget (Concat) är felaktigt!');
        } finally {
            if (file_exists($testFile)) {
                unlink($testFile);
            }
            ErrorResponder::$viewPath = $oldPath;
        }
    }

    public function testRespondStateAndBufferIntegrityModern(): void
    {
        $request = $this->makeRequest('/test');
        $levelBefore = ob_get_level();

        // 1. Döda UnwrapRtrim (Rad 41) via space suffix
        $oldPath = ErrorResponder::$viewPath;
        ErrorResponder::$viewPath = $this->testViewDir . ' ';
        file_put_contents($this->testViewDir . '/404.php', 'RTRIM_MATCH');

        $response = ErrorResponder::respond($request, 404, 'msg');
        $this->assertSame('RTRIM_MATCH', (string) $response->getBody(), 'rtrim() muterades bort!');
        ErrorResponder::$viewPath = $oldPath;

        // 2. Döda CastString (Rad 60) via tom vy
        file_put_contents($this->testViewDir . '/404.php', '<?php /* Tom */ ?>');
        $response = ErrorResponder::respond($request, 404, 'EmptyTest');
        $this->assertSame('<h1>404 | EmptyTest</h1>', (string) $response->getBody());

        // 3. Döda UnwrapTrim (Rad 68) via whitespace
        file_put_contents($this->testViewDir . '/404.php', '   ');
        $response = ErrorResponder::respond($request, 404, 'Whitespace');
        $this->assertSame('<h1>404 | Whitespace</h1>', (string) $response->getBody());

        // 4. Verifiera krasch-hantering
        file_put_contents($this->testViewDir . '/404.php', '<?php throw new \Exception("Fail"); ?>');
        $response = ErrorResponder::respond($request, 404, 'Crash');
        $this->assertSame('<h1>404 | Crash</h1>', (string) $response->getBody());
        $this->assertSame($levelBefore, ob_get_level());
    }

    public function testRespondHandlesOutputBufferAsExplicitString(): void
    {
        $request = $this->makeRequest('/test');
        file_put_contents($this->testViewDir . '/404.php', '12345');
        $response = ErrorResponder::respond($request, 404, 'msg');
        $this->assertSame('12345', (string) $response->getBody());
    }

    public function testRespondBufferAndStateIntegrity(): void
    {
        $request = $this->makeRequest('/test');
        $levelBefore = ob_get_level();

        // Verifiera att rtrim fungerar för punkt-suffix (CI fix)
        $oldPath = ErrorResponder::$viewPath;
        ErrorResponder::$viewPath = $this->testViewDir . DIRECTORY_SEPARATOR . '.';
        file_put_contents($this->testViewDir . '/404.php', 'RTRIM_MATCH');

        $response = ErrorResponder::respond($request, 404, 'msg');
        $this->assertSame('RTRIM_MATCH', (string) $response->getBody());
        ErrorResponder::$viewPath = $oldPath;
        $this->assertSame($levelBefore, ob_get_level(), 'Buffert läckte!');
    }

    public function testRespondHandlesEmptyFileWithFallback(): void
    {
        $request = $this->makeRequest('/test');
        file_put_contents($this->testViewDir . '/404.php', '');
        $response = ErrorResponder::respond($request, 404, 'Empty');
        $this->assertSame('<h1>404 | Empty</h1>', (string) $response->getBody());
    }
}
