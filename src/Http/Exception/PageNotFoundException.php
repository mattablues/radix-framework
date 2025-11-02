<?php

declare(strict_types=1);

namespace Radix\Http\Exception;

class PageNotFoundException extends HttpException
{
    public function __construct(string $message = 'Page not found.')
    {
        parent::__construct($message, 404);
    }
}