<?php

declare(strict_types=1);

namespace Radix\Error;

use Radix\Http\Request;
use Radix\Http\Response;

final class ErrorResponder
{
    /**
     * Returnera ett Response beroende på API eller Web.
     * - API: JSON { error, details? } med status
     * - Web: inkluderar views/errors/{status}.php, fallback till 500.php
     *
     * @param array<string, mixed> $jsonExtra
     */
    public static function respond(Request $request, int $status, string $message, array $jsonExtra = []): Response
    {
        $isApi = preg_match('#^/api/v\d+(/|$)#', $request->uri) === 1;

        $resp = new Response();
        $resp->setStatusCode($status);

        if ($isApi) {
            $payload = array_merge(['error' => $message, 'status' => $status], $jsonExtra);
            $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
            $resp->setHeader('Content-Type', 'application/json; charset=UTF-8')
                 ->setBody($body !== false ? $body : '{"error":"Unexpected error"}');
            return $resp;
        }

        $resp->setHeader('Content-Type', 'text/html; charset=UTF-8');

        ob_start();
        $errorFile = ROOT_PATH . "/views/errors/{$status}.php";
        $fallback = ROOT_PATH . '/views/errors/500.php';
        if (is_file($errorFile)) {
            include $errorFile;
        } else {
            include $fallback;
        }
        $html = ob_get_clean();
        $resp->setBody(is_string($html) ? $html : "<h1>{$status} | {$message}</h1>");

        return $resp;
    }
}