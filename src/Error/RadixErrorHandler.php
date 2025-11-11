<?php

declare(strict_types=1);

namespace Radix\Error;

use ErrorException;
use Radix\Http\Exception\HttpException;
use Radix\Http\JsonResponse;
use Throwable;

class RadixErrorHandler
{
    public static function handleError(
        int $errno,
        string $errstr,
        string $errfile,
        int $errline,
    ): bool {
        if (!(error_reporting() & $errno)) {
            return true;
        }
        error_log("Error [$errno]: $errstr in $errfile on line $errline");
        throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
    }

    public static function handleException(Throwable $exception): void
    {
        $appEnv = strtolower((string) (getenv('APP_ENV') ?: 'production'));
        $isDev = in_array($appEnv, ['dev','development'], true);

        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $isApiRequest = str_contains($requestUri, '/api/') || str_contains($accept, 'application/json');

        $statusCode = $exception instanceof HttpException ? $exception->getStatusCode() : 500;

        $logMessage = sprintf(
            "Exception [%s]: %s in %s on line %d\nStack trace:\n%s",
            get_class($exception),
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine(),
            $exception->getTraceAsString()
        );
        error_log($logMessage);

        if ($isApiRequest) {
            $body = [
                'success' => false,
                'errors' => [
                    ['field' => 'Exception', 'messages' => [$exception->getMessage()]],
                ],
            ];
            if ($isDev) {
                $body['debug'] = [
                    'exception_class' => get_class($exception),
                    'stack_trace' => $exception->getTraceAsString(),
                ];
            }

            $response = new JsonResponse();
            $response
                ->setStatusCode($statusCode)
                ->setHeader('Content-Type', 'application/json; charset=utf-8')
                ->setBody($method === 'HEAD' ? '' : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            $response->send();
            exit;
        }

        if ($isDev) {
            ini_set('display_errors', '1');
            echo '<pre>' . htmlspecialchars($logMessage, ENT_QUOTES, 'UTF-8') . '</pre>';
        } else {
            ini_set('display_errors', '0');
            ini_set('log_errors', '1');

            $root = defined('ROOT_PATH') ? (string) ROOT_PATH : (string) dirname(__DIR__, 3);
            $viewFile = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'errors' . DIRECTORY_SEPARATOR . $statusCode . '.php';
            if (!is_file($viewFile)) {
                $fallback = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'errors' . DIRECTORY_SEPARATOR . '500.php';
                if (is_file($fallback)) {
                    $viewFile = $fallback;
                } else {
                    header('Content-Type: text/plain; charset=utf-8', true, $statusCode);
                    echo "An error occurred. HTTP {$statusCode}";
                    exit;
                }
            }
            require $viewFile;
        }

        exit;
    }
}