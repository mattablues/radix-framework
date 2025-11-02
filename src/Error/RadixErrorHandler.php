<?php

declare(strict_types=1);

namespace Radix\Error;

use ErrorException;
use JetBrains\PhpStorm\NoReturn;
use Radix\Http\Exception\HttpException;
use Radix\Http\Exception\MaintenanceException;
use Radix\Http\Exception\PageNotFoundException;
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
        // Logga felet till PHP:s error_log
        error_log("Error [$errno]: $errstr in $errfile on line $errline");

        // Konvertera felet till en ErrorException och kasta det
        throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
    }

    public static function handleException(Throwable $exception): void
    {
        // Identifiera om det är ett API-anrop (baserat på URI)
        $isApiRequest = (!empty($_SERVER['REQUEST_URI']) && str_contains($_SERVER['REQUEST_URI'], '/api/'));

        // Fastställ korrekt statuskod
        $statusCode = $exception instanceof HttpException ? $exception->getStatusCode() : 500;

        // Samla loggningsinformation
        $logMessage = sprintf(
            "Exception [%s]: %s in %s on line %d\nStack trace:\n%s",
            get_class($exception),
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine(),
            $exception->getTraceAsString()
        );

        // Logga till systemets error_log
        error_log($logMessage);

        if ($isApiRequest) {
            // Skicka respons för API:er
            $body = [
                "success" => false,
                "errors" => [
                    [
                        "field" => "Exception",
                        "messages" => [$exception->getMessage()],
                    ],
                ],
            ];

            // För utvecklingsläge - inkludera stack trace
            if (getenv('APP_ENV') === 'development') {
                $body['debug'] = [
                    "exception_class" => get_class($exception),
                    "stack_trace" => $exception->getTraceAsString(),
                ];
            }

            $response = new JsonResponse();
            $response
                ->setStatusCode($statusCode)
                ->setHeader('Content-Type', 'application/json')
                ->setBody(json_encode($body));

            $response->send();
            exit;
        }

        // Hantering för vanliga rutter (icke-API)
        if (getenv('APP_ENV') === 'development') {
            ini_set('display_errors', '1');   // Visa detaljerat felmeddelande
            echo '<pre>' . htmlspecialchars($logMessage, ENT_QUOTES, 'UTF-8') . '</pre>';
        } else {
            ini_set('display_errors', '0');
            ini_set('log_errors', '1');      // Logga fel
            require dirname(__DIR__, 3) . "/views/errors/{$statusCode}.php";
        }

        exit;
    }
}