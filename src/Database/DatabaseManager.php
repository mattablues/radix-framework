<?php

declare(strict_types=1);

namespace Radix\Database;

use Psr\Container\ContainerInterface;
use RuntimeException;

class DatabaseManager
{
    protected ContainerInterface $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    public function connection(): Connection
    {
        $connection = $this->container->get(Connection::class);

        if (!$connection instanceof Connection) {
            throw new RuntimeException(
                'Container did not return a ' . Connection::class . ' instance.'
            );
        }

        return $connection;
    }
}
