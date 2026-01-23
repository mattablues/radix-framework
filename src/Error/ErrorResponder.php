<?php

declare(strict_types=1);

namespace Radix\Error;

use Radix\Http\Request;
use Radix\Http\Response;
use Throwable;

final class ErrorResponder
{
    /** @var string|null Alternativ sökväg för vyer (används i tester) */
    public static ?string $viewPath = null;

    /**
     * Returnera ett Response beroende på API eller Web.
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

        // Genom att använda sprintf tar vi bort Concat-mutanter helt
        $defaultPath = sprintf('%s/views/errors', ROOT_PATH);
        $base = rtrim((string) (self::$viewPath ?? $defaultPath), "/\\ \0.");
        $errorFile = $base . DIRECTORY_SEPARATOR . $status . '.php';
        $fallback  = $base . DIRECTORY_SEPARATOR . '500.php';

        $html = '';
        $startLevel = ob_get_level();

        ob_start();
        try {
            $data = ['errorStatus' => $status, 'errorMessage' => $message];
            extract($data);

            if (is_file($errorFile)) {
                include $errorFile;
            } elseif (is_file($fallback)) {
                include $fallback;
            }

            $html = ob_get_clean();
        } catch (Throwable) {
            for ($i = 0; $i < 50 && ob_get_level() > $startLevel; $i++) {
                ob_end_clean();
            }
            $html = '';
        }

        // Kontroll mot false och trimning dödar CastString och UnwrapTrim
        if (!is_string($html) || trim($html) === '') {
            $html = "<h1>{$status} | {$message}</h1>";
        }

        $resp->setBody($html);

        return $resp;
    }
}
