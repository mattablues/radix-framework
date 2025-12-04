<?php

declare(strict_types=1);

namespace Radix\Middleware\Middlewares;

use Radix\Http\Request;
use Radix\Http\RequestHandlerInterface;
use Radix\Http\Response;
use Radix\Middleware\MiddlewareInterface;

class LimitRequestSize implements MiddlewareInterface
{
    private const int BYTES_IN_MB = 1048576; // 1024 * 1024

    /**
     * @param int $maxBytes Maxstorlek i bytes (default: 2 MB).
     */
    public function __construct(
        private readonly int $maxBytes = 2 * self::BYTES_IN_MB
    ) {}

    public function process(Request $request, RequestHandlerInterface $next): Response
    {
        $contentLength = $this->getContentLengthFromServer($request->server);

        if ($contentLength !== null && $this->isTooLarge($contentLength, $this->maxBytes)) {
            return $this->tooLargeResponse($this->maxBytes);
        }

        // Allt ok → skicka vidare
        return $next->handle($request);
    }

    /**
     * @param array<string, mixed> $server
     */
    private function getContentLengthFromServer(array $server): ?int
    {
        $value = $server['CONTENT_LENGTH'] ?? $server['HTTP_CONTENT_LENGTH'] ?? null;

        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            return null;
        }

        // Content-Length ska vara heltal i textform
        if ($value === '' || !ctype_digit($value)) {
            return null;
        }

        /** @var int $length */
        $length = (int) $value;

        if ($length < 0) {
            return null;
        }

        return $length;
    }

    private function isTooLarge(int $size, int $max): bool
    {
        return $size > $max;
    }

    private function tooLargeResponse(int $maxBytes): Response
    {
        // Skapa svar via din DI/container om du vill – här gör vi det manuellt
        $response = new Response();

        $response->setStatusCode(413); // Payload Too Large
        $response->setHeader('Content-Type', 'application/json; charset=utf-8');

        $maxMb = $this->bytesToMegabytes($maxBytes);

        $payload = [
            'error' => 'Payload Too Large',
            'message' => sprintf('Max %d MB tillåts.', $maxMb),
            'max_bytes' => $maxBytes,
            'doc' => 'https://httpstatuses.com/413',
        ];

        $json = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        $response->setBody($json === false ? '' : $json);

        return $response;
    }

    private function bytesToMegabytes(int $bytes): int
    {
        // Heltalsavrundning uppåt om det inte delar jämnt
        $mb = (int) intdiv($bytes + self::BYTES_IN_MB - 1, self::BYTES_IN_MB);

        return $mb > 0 ? $mb : 1;
    }
}
