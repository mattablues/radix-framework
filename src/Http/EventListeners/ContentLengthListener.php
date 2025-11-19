<?php

declare(strict_types=1);

namespace Radix\Http\EventListeners;

use Radix\Http\Event\ResponseEvent;

class ContentLengthListener
{
    public function __invoke(ResponseEvent $event): void
    {
        $response = $event->response();

        if (!array_key_exists('Content-Length', $response->headers())) {
            $response->setHeader('Content-Length', strlen($response->body()));
        }
    }
}
