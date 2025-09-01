<?php

declare(strict_types=1);

namespace Radix\Http\Event;

use Radix\EventDispatcher\Event;
use Radix\Http\Request;
use Radix\Http\Response;

class ResponseEvent extends Event
{
    public function __construct(
        private readonly Request $request,
        private readonly Response $response
    )
    {
    }

    public function request(): Request
    {
        return $this->request;
    }

    public function response(): Response
    {
        return $this->response;
    }
}