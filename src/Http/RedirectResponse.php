<?php

declare(strict_types=1);

namespace Radix\Http;

use InvalidArgumentException;

class RedirectResponse extends Response
{
    public function __construct(
        private readonly string $location,
        private readonly int $statusCode = 302
    ) {
        if ($this->statusCode < 300 || $this->statusCode > 399) {
            throw new InvalidArgumentException('Redirect status code must be between 300 and 399.');
        }

        $this->setStatusCode($this->statusCode);
        $this->setHeader('Location', $this->location);
    }

    public function send(): void
    {
        header('Location: ' . $this->location, true, $this->statusCode);
        exit();
    }
}
