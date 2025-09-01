<?php

declare(strict_types=1);

namespace Radix\Http\Exception;

class NotAuthorizedException extends HttpException
{
    public function __construct(string $message = 'You are not authorized to perform this action.')
    {
        parent::__construct($message, 403);
    }
}

