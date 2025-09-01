<?php

declare(strict_types=1);

namespace Radix\Container;

use Closure;
use ReflectionClass;
use ReflectionException;
use Radix\Container\Exception\ContainerConfigException;
use Radix\Container\Exception\ContainerDependencyInjectionException;

class Resolver
{
    private array $resolvedDependenciesCache = [];
    public function __construct(private Container $container)
    {
    }

    /**
     * @throws ContainerConfigException
     * @throws ContainerDependencyInjectionException
     */
    public function resolve(Definition $definition): object
    {
        $this->parseConcrete($definition);

        if (null !== $definition->getFactory()) {
            $instance = $this->createFromFactory($definition);

        } elseif (null !== $definition->getClass()) {
            $instance = $this->createFromClass($definition);

        } elseif (null !== $definition->getResolved()) {
            $instance = $definition->getResolved();

        } else {
            throw new ContainerConfigException('The definition is invalid');
        }

        $this->invokeMethods($definition, $instance);
        $this->invokeProperties($definition, $instance);
        $definition->setResolved($instance);

        return $instance;
    }

    private function parseConcrete(Definition $definition): void
    {
        $concrete = $definition->getConcrete();

        if (is_string($concrete)) {
            $definition->setClass($concrete);

        } elseif (is_array($concrete) || $concrete instanceof Closure) {
            $definition->setFactory($concrete);

        } elseif (is_object($concrete)) {
            $definition->setResolved($concrete)->setShared(true);

        } else {
            throw new ContainerConfigException('The concrete of definition is invalid');
        }
    }

    private function createFromClass(Definition $definition): object
    {
        $class = $definition->getClass();

        try {
            $reflection = new ReflectionClass($definition->getClass());
        } catch (ReflectionException $e) {
            throw new ContainerDependencyInjectionException(
                sprintf("Failed to resolve class '%s': %s", $definition->getClass(), $e->getMessage())
            );
        }

        if (!$reflection->isInstantiable()){
            throw new ContainerDependencyInjectionException(
                sprintf("Cannot instantiate class '%s'. It might be abstract or an interface.", $definition->getClass())
            );
        }

        $constructor = $reflection->getConstructor();

        if (is_null($constructor)) {
            return $reflection->newInstanceWithoutConstructor();
        }

        // Lös argument och beroenden
        $arguments = $this->resolveArguments($definition->getArguments());

        if ($definition->isAutowired()) {
            $arguments = $this->resolveDependencies($constructor->getParameters(), $arguments);
        }

        if (count($arguments) < $constructor->getNumberOfRequiredParameters()) {
            throw new ContainerConfigException(sprintf(
                "Not enough arguments for class '%s'. Constructor requires at least %d arguments.",
                $class,
                $constructor->getNumberOfRequiredParameters()
            ));
        }

        return $reflection->newInstanceArgs($arguments);
    }

    private function createFromFactory(Definition $definition)
    {
        $factory = $definition->getFactory();

        if (is_array($factory) && count($factory) === 2 && is_string($factory[0]) && is_string($factory[1])) {
            [$className, $methodName] = $factory;

            if (!class_exists($className)) {
                throw new ContainerDependencyInjectionException(sprintf("Factory class '%s' does not exist.", $className));
            }

            if (!method_exists($className, $methodName)) {
                throw new ContainerDependencyInjectionException(sprintf("Factory method '%s' does not exist in class '%s'.", $methodName, $className));
            }

            $factory = [$className, $methodName];
        }

        if (!is_callable($factory)) {
            throw new ContainerConfigException("The factory provided is not callable.");
        }

        return call_user_func_array($factory, $this->resolveArguments($definition->getArguments()) ?: [$this->container]);
    }

    private function invokeMethods(Definition $definition, ?object $instance): void
    {
        foreach ($definition->getMethodCalls() as $method) {
            call_user_func_array([$instance, $method[0]], $this->resolveArguments($method[1]));
        }
    }

    private function invokeProperties(Definition $definition, ?object $instance): void
    {
        $properties = $this->resolveArguments($definition->getProperties());

        foreach ($properties as $name => $value) {
            $instance->$name = $value;
        }
    }

    private function resolveDependencies(array $dependencies, array $arguments): array
    {
        $solved = [];
        foreach ($dependencies as $dependency) {
            $cacheKey = $dependency->getName() . ':' . $dependency->getDeclaringClass()->getName();

            if (isset($this->resolvedDependenciesCache[$cacheKey])) {
                $solved[] = $this->resolvedDependenciesCache[$cacheKey];
                continue;
            }

            if (isset($arguments[$dependency->getPosition()])) {
                $solved[] = $arguments[$dependency->getPosition()];
            } elseif (isset($arguments[$dependency->getName()])) {
                $solved[] = $arguments[$dependency->getName()];
            } elseif (($type = $dependency->getType()) && !$type->isBuiltin()) {
                $solved[] = $this->container->get($type->getName());
            } elseif ($dependency->isDefaultValueAvailable()) {
                $solved[] = $dependency->getDefaultValue();
            } else {
                throw new ContainerDependencyInjectionException(sprintf(
                    'Unresolvable dependency for "%s" in class "%s".',
                    $dependency->name,
                    $dependency->getDeclaringClass()->getName()
                ));
            }

            $this->resolvedDependenciesCache[$cacheKey] = end($solved);
        }
        return $solved;
    }

    private function resolveArguments(array $arguments): array
    {
        foreach ($arguments as &$argument) {
            if ($argument instanceof Reference) {
                $argument = $this->container->get($argument->getId());
            }
        }
        return $arguments;
    }
}