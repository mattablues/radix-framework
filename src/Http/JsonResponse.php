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

        // 1. Sätt Content-Type automatiskt för JSON om den inte redan finns
        if (empty($this->getHeaders()['Content-Type'])) {
            $this->setHeader('Content-Type', 'application/json; charset=utf-8');
        }

        // 2. Skicka HTTP-statuskod
        http_response_code($this->getStatusCode());

        // 3. Skicka alla headers till webbläsaren/Postman
        foreach ($this->getHeaders() as $key => $value) {
            header(sprintf('%s: %s', $key, $value));
        }

        // 4. Skicka ENDAST bodyn (som redan är en JSON-sträng från ApiController)
        echo $this->getBody();

        ob_end_flush();
    }
}
