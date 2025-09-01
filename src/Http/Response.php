<?php

declare(strict_types=1);

namespace Radix\Http;

class Response
{
    private string $body = '';
    private array $headers = [];
    private  int $status_code = 200;

    public function setStatusCode(int $code): Response
    {
        $this->status_code = $code;

        return $this;
    }

    public function setHeader(string $key, mixed $value): Response
    {
        $this->headers[$key] = $value;

        return $this;
    }

    public function setBody(string $body): void
    {
        $this->body = $body;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function send(): void
    {
        ob_start();

        if ($this->status_code) {
            http_response_code($this->status_code);
        }

        foreach ($this->headers as $key => $value) {
            header("$key: $value");
        }

        echo $this->body;

        ob_end_flush();
    }

    public function headers(): array
    {
        return $this->headers;
    }

    public function header(string $header): array
    {
        // Säkerställ att header alltid returnerar en array
        $value = $this->headers[$header] ?? [];

        return is_array($value) ? $value : [$value];
    }

    // Getter för statuskod
    public function getStatusCode(): int
    {
        return $this->status_code;
    }

    // Getter för headers
    public function getHeaders(): array
    {
        return $this->headers;
    }

    // Getter för body
    public function getBody(): string
    {
        return $this->body;
    }
}