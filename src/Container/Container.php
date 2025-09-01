<?php

declare(strict_types=1);

namespace Radix\Container;

use ArrayAccess;
use Psr\Container\ContainerInterface;
use Radix\Container\Exception\ContainerDependencyInjectionException;
use Radix\Container\Exception\ContainerNotFoundException;
use ReflectionClass;

class Container implements ContainerInterface, ArrayAccess
{
    private array $aliases = [];
    private array $definitions = [];
    private array $instances = [];
    private Parameter $parameters;
    private Resolver $resolver;
    private array $defaults = [
        'share' => false,
        'autowire' => true,
        'autoregister' => true
    ];
    public function __construct()
    {
        $this->parameters = new Parameter();
        $this->resolver = new Resolver($this);
        $this->add($this);
    }

    public function offsetExists(mixed $offset): bool
    {
        return $this->has($offset);
    }

    public function offsetGet(mixed $offset): object
    {
        return $this->get($offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if (!is_string($offset) || empty($offset)) {
            throw new ContainerDependencyInjectionException('Array key must be a non-empty string.');
        }
        $this->add($offset, $value);
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->definitions[$offset], $this->instances[$offset]);
    }

    public function add(object|string $id, mixed $concrete = null): Definition
    {
        if (null === $concrete) {
            $concrete = $id;
        }

        if (is_object($id)) {
            $id = get_class($id);
        }

        //Apply defaults.
        $definition = (new Definition($concrete))
            ->setShared($this->defaults['share'])
            ->setAutowired($this->defaults['autowire']);

        return $this->setDefinition($id, $definition);
    }

    public function addShared(object|string $id, mixed $concrete = null): Definition
    {
        if (null === $concrete) {
            $concrete = $id;
        }

        if (is_object($id)) {
            $id = get_class($id);
        }

        //Apply defaults.
        $definition = (new Definition($concrete))
            ->setShared(true)
            ->setAutowired($this->defaults['autowire']);

        return $this->setDefinition($id, $definition);
    }

    public function setDefinition(string $id, Definition $definition): Definition
    {
        unset($this->aliases[$id]);
        return $this->definitions[$id] = $definition;
    }

    public function addDefinitions(array $definitions): void
    {
        foreach ($definitions as $id => $definition) {
            $this->setDefinition($id, $definition);
        }
    }

    public function setDefinitions(array $definitions): void
    {
        $this->definitions = [];
        $this->addDefinitions($definitions);
    }

    public function setAlias(string $alias, string $id): void
    {
        if (!$this->has($id)) {
            throw new ContainerDependencyInjectionException(sprintf('Cannot alias "%s" to "%s" because "%s" is not defined.', $alias, $id, $id));
        }
        $this->aliases[$alias] = $id;
    }

    public function getAlias(string $alias): ?string
    {
        return $this->aliases[$alias] ?? null;
    }

    public function get(string $id): object
    {
        $id = $this->resolveAlias($id);

        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        return $this->resolveInstance($id);
    }

    public function getNew(string $id): object
    {
        $id = $this->resolveAlias($id);

        return $this->resolveInstance($id);
    }

    protected function resolveAlias(string $id): string
    {
        $resolvedIds = [];
        while (isset($this->aliases[$id])) {
            if (in_array($id, $resolvedIds, true)) {
                throw new ContainerDependencyInjectionException(sprintf('Circular alias detected for "%s".', $id));
            }
            $resolvedIds[] = $id;
            $id = $this->aliases[$id];
        }

        return $id;
    }


    protected function resolveInstance(string $id): object
    {
        if (!$this->has($id)) {
            if ($this->defaults['autoregister'] && class_exists($id)) {
                $reflection = new ReflectionClass($id);
                if ($reflection->isAbstract() || $reflection->isInterface()) {
                    throw new ContainerDependencyInjectionException(sprintf('Cannot auto-register abstract class or interface "%s".', $id));
                }
                $this->add($id);
            } else {
                throw new ContainerNotFoundException(sprintf('There is no definition named "%s"', $id));
            }
        }

        $instance = $this->resolver->resolve($this->definitions[$id]);
        if ($this->definitions[$id]->isShared()) {
            $this->instances[$id] = $instance;
        }

        return $instance;
    }

    public function addTag(string $serviceId, string $tag, array $parameters = []): void
    {
        if (!$this->has($serviceId)) {
            throw new ContainerNotFoundException(sprintf('Cannot add tag to non-existent service "%s".', $serviceId));
        }
        $this->definitions[$serviceId]->addTag($tag, $parameters);
    }

    public function has($id): bool
    {
        return isset($this->definitions[$id]);
    }

    public function extend(string $id): Definition
    {
        if (!$this->has($id)) {
            throw new ContainerNotFoundException(sprintf('There is no definition named "%s"', $id));
        }
        $definition = $this->definitions[$id];
        if ($definition->getResolved()) {
            throw new ContainerDependencyInjectionException(sprintf('Cannot override frozen service "%s".', $id));
        }

        return $definition;
    }

    /**
     * Returns service ids for a given tag.
     *
     * Example:
     *
     *     $container->register('foo')->addTag('my.tag', array('hello' => 'world'));
     *
     *     $serviceIds = $container->findTaggedServiceIds('my.tag');
     *     foreach ($serviceIds as $serviceId => $tags) {
     *         foreach ($tags as $tag) {
     *             echo $tag['hello'];
     *         }
     *     }
     *
     */
    public function findTaggedServiceIds(string $name): array
    {
        $tags = array();
        foreach ($this->definitions as $id => $definition) {
            if ($definition->hasTag($name)) {
                $tags[$id] = $definition->getTag($name);
            }
        }

        return $tags;
    }

    public function getParameters(): array
    {
        return $this->parameters->toArray();
    }

    public function setParameters(array $parameterStore): void
    {
        $this->parameters->setParameters($parameterStore);
    }

    public function addParameters(array $parameters): void
    {
        $this->parameters->addParameters($parameters);
    }

    public function setParameter(string $name, mixed $value): void
    {
        $this->parameters->setParameter($name, $value);
    }

    public function getParameter(string $name, mixed $default = null): mixed
    {
        return $this->parameters->getParameter($name, $default);
    }

    public function getDefault(string $option): mixed
    {
        return $this->defaults[$option] ?? null;
    }

    public function setDefaults(array $defaults): void
    {
        $this->defaults = array_merge($this->defaults, $defaults);
    }
}