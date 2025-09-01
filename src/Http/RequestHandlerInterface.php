<?php

declare(strict_types=1);

namespace Radix\Http;

interface RequestHandlerInterface
{
    public function handle(Request $request): Response;

}