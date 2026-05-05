<?php

declare(strict_types=1);

namespace Radix\Tests\Error;

use PHPUnit\Framework\TestCase;
use Radix\Error\RadixErrorHandler;
use Radix\Http\Exception\HttpException;
use Radix\Http\Exception\PageNotFoundException;
use ReflectionMethod;
use RuntimeException;
use Throwable;

final class RadixErrorHandlerTest extends TestCase
{
    private string $logFile;
    private ?string $originalLogContents = null;
    private bool $logFileExisted = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('ROOT_PATH')) {
            define('ROOT_PATH', dirname(__DIR__, 2));
        }

        $logDir = rtrim((string) ROOT_PATH, '/\\')
            . DIRECTORY_SEPARATOR
            . 'storage'
            . DIRECTORY_SEPARATOR
            . 'logs';

        if (!is_dir($logDir)) {
            mkdir($logDir, 0o755, true);
        }

        $this->logFile = $logDir
            . DIRECTORY_SEPARATOR
            . 'error-' . date('Y-m-d') . '.log';

        $this->logFileExisted = is_file($this->logFile);
        $this->originalLogContents = $this->logFileExisted
            ? (string) file_get_contents($this->logFile)
            : null;

        file_put_contents($this->logFile, '');
    }

    protected function tearDown(): void
    {
        if ($this->logFileExisted) {
            file_put_contents($this->logFile, (string) $this->originalLogContents);
        } elseif (is_file($this->logFile)) {
            unlink($this->logFile);
        }

        parent::tearDown();
    }

    public function testPageNotFoundExceptionIsNotLoggedAsErrorOrWarning(): void
    {
        $this->logException(
            new PageNotFoundException("No route matched for '/wp-admin/install.php' with method 'GET'"),
            404,
            '/wp-admin/install.php',
            'GET',
            'text/html'
        );

        $logContents = $this->readLogContents();

        $this->assertSame('', $logContents);
        $this->assertStringNotContainsString('.ERROR', $logContents);
        $this->assertStringNotContainsString('.WARNING', $logContents);
        $this->assertStringNotContainsString('PageNotFoundException', $logContents);
    }

    public function testRuntimeExceptionIsLoggedAsError(): void
    {
        $this->logException(
            new RuntimeException('Something exploded'),
            500,
            '/broken',
            'GET',
            'text/html'
        );

        $logContents = $this->readLogContents();

        $this->assertStringContainsString('.ERROR', $logContents);
        $this->assertStringContainsString('Exception [RuntimeException]: Something exploded', $logContents);
        $this->assertStringContainsString(__FILE__, $logContents);
        $this->assertStringContainsString(' on line ', $logContents);
        $this->assertStringContainsString('Stack trace:', $logContents);
        $this->assertStringNotContainsString('{file}', $logContents);
        $this->assertStringNotContainsString('{line}', $logContents);
        $this->assertStringNotContainsString('{trace}', $logContents);
        $this->assertStringNotContainsString('.WARNING', $logContents);
    }

    public function testNon404ClientHttpExceptionIsLoggedAsWarningNotError(): void
    {
        $this->logException(
            new HttpException('Forbidden', 403),
            403,
            '/admin',
            'POST',
            'text/html'
        );

        $logContents = $this->readLogContents();

        $this->assertStringContainsString('.WARNING', $logContents);
        $this->assertStringContainsString('HTTP exception [403]: Forbidden for POST /admin', $logContents);
        $this->assertStringNotContainsString('.ERROR', $logContents);
    }

    public function testServerHttpExceptionIsLoggedAsError(): void
    {
        $this->logException(
            new HttpException('Maintenance', 503),
            503,
            '/maintenance',
            'GET',
            'text/html'
        );

        $logContents = $this->readLogContents();

        $this->assertStringContainsString('.ERROR', $logContents);
        $this->assertStringContainsString('Exception [Radix\Http\Exception\HttpException]: Maintenance', $logContents);
        $this->assertStringNotContainsString('.WARNING', $logContents);
    }

    public function testBadRequestHttpExceptionIsLoggedAsWarning(): void
    {
        $this->logException(
            new HttpException('Bad request', 400),
            400,
            '/bad-request',
            'GET',
            'text/html'
        );

        $logContents = $this->readLogContents();

        $this->assertStringContainsString('.WARNING', $logContents);
        $this->assertStringContainsString('HTTP exception [400]: Bad request for GET /bad-request', $logContents);
        $this->assertStringNotContainsString('.ERROR', $logContents);
    }

    private function logException(
        Throwable $exception,
        int $statusCode,
        string $requestUri,
        string $method,
        string $accept,
    ): void {
        $methodReflection = new ReflectionMethod(RadixErrorHandler::class, 'logException');
        $methodReflection->setAccessible(true);

        $methodReflection->invoke(
            null,
            $exception,
            $statusCode,
            $requestUri,
            $method,
            $accept
        );
    }

    private function readLogContents(): string
    {
        if (!is_file($this->logFile)) {
            return '';
        }

        return (string) file_get_contents($this->logFile);
    }
}
