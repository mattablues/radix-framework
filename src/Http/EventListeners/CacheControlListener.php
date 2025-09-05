<?php

declare(strict_types=1);

namespace Radix\Http\EventListeners;

use Radix\Http\Event\ResponseEvent;

class CacheControlListener
{
    public function __invoke(ResponseEvent $event): void
    {
        $response = $event->response();

        // Förhindra klient-cache för dynamiskt genererade resurser
        $response->setHeader('Cache-Control', 'no-store, must-revalidate, max-age=0');
        $response->setHeader('Pragma', 'no-cache');
        $response->setHeader('Expires', '0');
    }
}

