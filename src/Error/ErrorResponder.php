<?php

declare(strict_types=1);

namespace Radix\Error;

use Radix\Http\Request;
use Radix\Http\Response;
use Throwable;

final class ErrorResponder
{
    /** @var string|null Alternativ sökväg för vyer (används främst i tester) */
    public static ?string $viewPath = null;
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
        try {
            $basePath = self::$viewPath ?? ROOT_PATH . '/views/errors';
            $errorFile = rtrim($basePath, '/\\') . "/{$status}.php";
            $fallback = rtrim($basePath, '/\\') . '/500.php';


            // Vi använder unika nycklar för att särskilja dem från metodens argument
            $data = ['errorStatus' => $status, 'errorMessage' => $message];
            extract($data);

            if (is_file($errorFile)) {
                include $errorFile;
            } else {
                include $fallback;
            }
        } catch (Throwable $e) {
            // Om vyn kraschar i testmiljön, stäng bufferten och kör fallback-text
            ob_end_clean();
            $resp->setBody("<h1>{$status} | {$message}</h1>");
            return $resp;
        }

        $html = ob_get_clean();
        $resp->setBody(is_string($html) && $html !== '' ? $html : "<h1>{$status} | {$message}</h1>");

        return $resp;
    }
}
