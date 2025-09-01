<?php

declare(strict_types=1);

namespace Radix\Database;

use Psr\Container\ContainerInterface;

class DatabaseManager
{
    protected ContainerInterface $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    public function connection(): Connection
    {
        return $this->container->get(Connection::class);
    }
}