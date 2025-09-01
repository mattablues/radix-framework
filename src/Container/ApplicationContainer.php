<?php

declare(strict_types=1);

namespace Radix\Container;

use Psr\Container\ContainerInterface;
use RuntimeException;

class ApplicationContainer
{
    private static ?ContainerInterface $instance = null;

    public static function set(ContainerInterface $container): void
    {
        if (self::$instance !== null) {
            throw new RuntimeException('Application container kan bara sättas en gång.');
        }

        self::$instance = $container;
    }

    public static function get(): ContainerInterface
    {
        if (self::$instance === null) {
            throw new RuntimeException('Application container är inte satt.');
        }

        if (!(self::$instance instanceof \Psr\Container\ContainerInterface)) {
            throw new RuntimeException('Application container är inte en giltig ContainerInterface.');
        }

        return self::$instance;
    }

    public static function reset(): void
    {
        self::$instance = null;
    }
}