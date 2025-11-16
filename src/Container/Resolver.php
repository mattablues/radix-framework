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
    /**
     * @var array<string, mixed>
     */
    private array $resolvedDependenciesCache = [];

    public function __construct(private readonly Container $container)
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

        if (!is_object($instance)) {
            throw new ContainerConfigException(
                'Resolver::resolve() expected factory/class/resolved to produce an object, got ' . get_debug_type($instance)
            );
        }

        /** @var object $instance */
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

        } elseif (is_array($concrete)) {
            // Hantera array-fabriker: [SomeClass::class, 'method']
            if (
                count($concrete) === 2
                && is_string($concrete[0])
                && is_string($concrete[1])
                && class_exists($concrete[0])
                && method_exists($concrete[0], $concrete[1])
            ) {
                /** @var array{class-string, string} $factory */
                $factory = $concrete;
                $definition->setFactory($factory);
            } elseif (is_callable($concrete)) {
                // Callable array (t.ex. [$object, 'method'])
                $definition->setFactory($concrete);
            } else {
                throw new ContainerConfigException('Array concrete is not a valid factory.');
            }

        } elseif ($concrete instanceof \Closure) {
            // Closure är ett giltigt callable för fabriken
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

        if ($class === null) {
            throw new ContainerConfigException('Definition has no valid class to instantiate.');
        }

        if (!class_exists($class)) {
            throw new ContainerDependencyInjectionException(sprintf(
                "Class '%s' does not exist.",
                $class
            ));
        }

        /** @var class-string $class */
        $reflection = new ReflectionClass($class);

        if (!$reflection->isInstantiable()) {
            throw new ContainerDependencyInjectionException(
                sprintf("Cannot instantiate class '%s'. It might be abstract or an interface.", $class)
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

    /**
     * Skapa en instans via en factory som definierats på Definition-objektet.
     *
     * @param  Definition  $definition
     * @return mixed
     */
    private function createFromFactory(Definition $definition): mixed
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

    private function invokeMethods(Definition $definition, object $instance): void
    {
        foreach ($definition->getMethodCalls() as $method) {
            $callable = [$instance, $method[0]];

            if (!is_callable($callable)) {
                throw new ContainerConfigException(sprintf(
                    'Method "%s" is not callable on class "%s".',
                    (string) $method[0],
                    get_class($instance)
                ));
            }

            $arguments = $this->resolveArguments($method[1]);

            /** @var callable $callable */
            call_user_func_array($callable, $arguments);
        }
    }

    private function invokeProperties(Definition $definition, ?object $instance): void
    {
        $properties = $this->resolveArguments($definition->getProperties());

        foreach ($properties as $name => $value) {
            $instance->$name = $value;
        }
    }

    /**
     * Lös parametrar för en reflektions-lista utifrån givna argument och container.
     *
     * @param array<int, \ReflectionParameter> $dependencies
     * @param array<int|string, mixed>         $arguments
     * @return array<int, mixed>
     */
    private function resolveDependencies(array $dependencies, array $arguments): array
    {
        $solved = [];
        foreach ($dependencies as $dependency) {
            $declaringClass = $dependency->getDeclaringClass();
            $declaringClassName = $declaringClass instanceof \ReflectionClass
                ? $declaringClass->getName()
                : 'global';

            $cacheKey = $dependency->getName() . ':' . $declaringClassName;

            if (isset($this->resolvedDependenciesCache[$cacheKey])) {
                $solved[] = $this->resolvedDependenciesCache[$cacheKey];
                continue;
            }

            if (isset($arguments[$dependency->getPosition()])) {
                $solved[] = $arguments[$dependency->getPosition()];
            } elseif (isset($arguments[$dependency->getName()])) {
                $solved[] = $arguments[$dependency->getName()];
            } else {
                $type = $dependency->getType();

                if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                    $className = $type->getName();
                    $solved[] = $this->container->get($className);
                } elseif ($dependency->isDefaultValueAvailable()) {
                    $solved[] = $dependency->getDefaultValue();
                } else {
                    throw new ContainerDependencyInjectionException(sprintf(
                        'Unresolvable dependency for "%s" in class "%s".',
                        $dependency->getName(),
                        $declaringClassName
                    ));
                }
            }

            $this->resolvedDependenciesCache[$cacheKey] = end($solved);
        }
        return $solved;
    }

    /**
     * Ersätt Reference-objekt med faktiska beroenden från containern.
     *
     * @param array<int|string, mixed> $arguments
     * @return array<int|string, mixed>
     */
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