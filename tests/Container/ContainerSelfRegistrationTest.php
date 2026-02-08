<?php

declare(strict_types=1);

namespace Radix\Tests\Container;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Radix\Container\Container;
use Radix\Container\Contract\ContainerRegistryInterface;

final class ContainerSelfRegistrationTest extends TestCase
{
    public function testContainerCanBeResolvedByInterfaces(): void
    {
        $c = new Container();

        $byPsr = $c->get(ContainerInterface::class);
        self::assertSame($c, $byPsr);

        $byRegistry = $c->get(ContainerRegistryInterface::class);
        self::assertSame($c, $byRegistry);
    }
}
