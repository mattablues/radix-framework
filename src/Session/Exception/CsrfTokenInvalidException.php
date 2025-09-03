<?php

declare(strict_types=1);

namespace Radix\Session\Exception;

use Radix\Http\Exception\HttpException;

class CsrfTokenInvalidException extends HttpException
{
    public function __construct(string $message = 'CSRF-token är ogiltig.', int $statusCode = 403)
    {
        parent::__construct($message, $statusCode);
    }
}