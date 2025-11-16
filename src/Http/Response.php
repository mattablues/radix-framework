<?php

declare(strict_types=1);

namespace Radix\Http;

class Response
{
    private string $body = '';
    /** @var array<string,string> */
    private array $headers = [];
    private int $status_code = 200;

    public function setStatusCode(int $code): Response
    {
        $this->status_code = $code;

        return $this;
    }

    /**
     * @param scalar $value
     */
    public function setHeader(string $key, mixed $value): Response
    {
        if (!is_scalar($value)) {
            throw new \InvalidArgumentException(
                'Header value must be a scalar, ' . get_debug_type($value) . ' given.'
            );
        }

        $this->headers[$key] = (string) $value;

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

    /**
     * @return array<string,string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * @return list<string>
     */
    public function header(string $header): array
    {
        // Säkerställ att header alltid returnerar en lista av strängar
        if (!array_key_exists($header, $this->headers)) {
            return [];
        }

        $value = $this->headers[$header];

        // Om du någon gång vill stödja flera värden per header kan du utöka detta
        return [$value];
    }

    // Getter för statuskod
    public function getStatusCode(): int
    {
        return $this->status_code;
    }

    /**
     * @return array<string,string>
     */
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