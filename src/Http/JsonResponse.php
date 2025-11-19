<?php

declare(strict_types=1);

namespace Radix\Http;

class JsonResponse extends Response
{
    /**
     * Skicka JSON-svaret.
     */
    public function send(): void
    {
        ob_start();

        // 1. Skicka alla headers som tidigare satts
        foreach ($this->getHeaders() as $key => $value) {
            header(sprintf('%s: %s', $key, $value), true, $this->getStatusCode());
        }

        // 2. Beräkna längd på bodyn (sätt Content-Length om saknas)
        $body = $this->getBody();
        $bodyLength = strlen($body);
        if (empty($this->header('Content-Length'))) {
            $this->setHeader('Content-Length', $bodyLength); // Lagra Content-Length
        }

        // 3. Skicka det formaterade JSON-svaret
        echo json_encode([
            'status' => $this->getStatusCode(), // Statuskod som anges av kontrollen
            'headers' => $this->getHeaders(),   // Alla headers
            'body' => json_decode($body, true), // Dekodad kropp (om JSON-innehåll)
        ], JSON_UNESCAPED_UNICODE);             // Utför utan att escapa unicode-tecken

        ob_end_flush(); // Töm och avsluta output cache
    }
}
