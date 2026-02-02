<?php

declare(strict_types=1);

namespace Radix\Container\Contract;

use Psr\Container\ContainerInterface;
use Radix\Container\Definition;

interface ContainerRegistryInterface extends ContainerInterface
{
    public function add(object|string $id, mixed $concrete = null): Definition;

    public function addShared(object|string $id, mixed $concrete = null): Definition;
}
