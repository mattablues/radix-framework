<?php

declare(strict_types=1);

namespace Radix\Http\EventListeners;

use Radix\Http\Event\ResponseEvent;

class CorsListener
{
    public function __invoke(ResponseEvent $event): void
    {
        $response = $event->response();
        $response->setHeader('Access-Control-Allow-Origin', '*'); // Anpassa efter behov
    }
}
