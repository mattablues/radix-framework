<?php

declare(strict_types=1);

namespace Radix\Http;

class RedirectResponse extends Response
{
    public function __construct(private readonly string $location) {}

    public function send(): void
    {
        header('Location: ' . $this->location, true, 302);
        exit();
    }
}
