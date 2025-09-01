<?php

declare(strict_types=1);

namespace Radix\ServiceProvider;

interface ServiceProviderInterface
{
    public function register(): void;
}