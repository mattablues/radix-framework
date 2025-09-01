<?php

declare(strict_types=1);

namespace Radix\Container;

class Definition
{
    private mixed $concrete;
    private ?string $class = null;
    private array $arguments = [];
    private array $calls = [];
    private array $properties = [];
    private mixed $factory = null;
    private array $tags = [];
    private bool $autowired = true;
    private bool $shared = true;
    private ?object $resolved = null;

    public function __construct(mixed $concrete)
    {
        $this->setConcrete($concrete);
    }

    public function setConcrete(mixed $concrete): void
    {
        if (!is_object($concrete) && !is_string($concrete) && !is_callable($concrete)) {
            throw new \InvalidArgumentException('Concrete must be a class name, an object, or a callable.');
        }

        $this->concrete = $concrete;
    }

    public function getConcrete(): mixed
    {
        return $this->concrete;
    }

    public function setShared(bool $shared): Definition
    {
        $this->shared = $shared;

        return $this;
    }

    public function setAutowired(bool $autowired): Definition
    {
        $this->autowired = $autowired;

        return $this;
    }

    public function setClass(string $class): Definition
    {
        $this->class = $class;

        return $this;
    }

    public function getClass(): ?string
    {
        return $this->class;
    }

    public function setFactory(callable|array $factory): Definition
    {
        $this->factory = $factory;

        return $this;
    }

    public function getFactory(): null|callable|array
    {
        return $this->factory;
    }

    public function setProperties(array $properties): void
    {
        $this->properties = $properties;
    }

    public function getProperties(): array
    {
        return $this->properties;
    }

    public function setProperty(string $name, mixed $value): Definition
    {
        $this->properties[$name] = $value;

        return $this;
    }

    public function getProperty(string $name): mixed
    {
        return $this->properties[$name] ?? null;
    }

    public function addArgument(mixed $value): Definition
    {
        if ($value === null) {
            throw new \InvalidArgumentException('Argument value cannot be null.');
        }

        $this->arguments[] = $value;

        return $this;
    }

    public function setArgument(mixed $key, mixed $value): Definition
    {
        $this->arguments[$key] = $value;

        return $this;
    }

    public function getArgument(int|string $index): mixed
    {
        if (!array_key_exists($index, $this->arguments)) {
            throw new \OutOfBoundsException(sprintf('Argument at index "%s" does not exist.', $index));
        }

        return $this->arguments[$index];
    }

    public function setArguments(array $arguments): Definition
    {
        $this->arguments = $arguments;

        return $this;
    }

    public function getArguments(): array
    {
        return $this->arguments;
    }

    public function addMethodCall(string $method, array|string $arguments): Definition
    {
        if (!is_string($method) || empty($method)) {
            throw new \InvalidArgumentException('Method name must be a non-empty string.');
        }

        $this->calls[] = [
            $method,
            (array) $arguments,
        ];

        return $this;
    }

    public function setMethodCalls(array $methods): Definition
    {
        $this->calls = [];

        foreach ($methods as $call) {
            $this->addMethodCall($call[0], $call[1]);
        }

        return $this;
    }

    public function getMethodCalls(): array
    {
        return $this->calls;
    }

    public function hasMethodCall(string $method): bool
    {
        foreach ($this->calls as $call) {
            if ($call[0] === $method) {
                return true;
            }
        }

        return false;
    }

    public function setTags(array $tags): Definition
    {
        $this->tags = $tags;

        return $this;
    }

    public function getTags(): array
    {
        return $this->tags;
    }

    public function getTag(string $name): array
    {
        return $this->tags[$name] ?? array();
    }

    public function addTag(string $name, array $attributes = []): Definition
    {
        if (!is_string($name) || empty($name)) {
            throw new \InvalidArgumentException('Tag name must be a non-empty string.');
        }

        $this->tags[$name][] = $attributes;

        return $this;
    }

    public function hasTag(string $name): bool
    {
        return isset($this->tags[$name]);
    }

    public function clearTag(string $name): Definition
    {
        if (!isset($this->tags[$name])) {
            throw new \InvalidArgumentException(sprintf('Tag "%s" does not exist.', $name));
        }

        unset($this->tags[$name]);
        return $this;
    }

    public function clearTags(): Definition
    {
        $this->tags = array();

        return $this;
    }

        public function isAutowired(): bool
    {
        return $this->autowired;
    }

    public function isShared(): bool
    {
        return $this->shared;
    }

    public function getResolved(): ?object
    {
        if (!is_null($this->resolved) && !is_object($this->resolved)) {
            throw new \LogicException('Resolved instance must be an object or null.');
        }

        return $this->resolved;
    }

    public function setResolved(object $resolved): Definition
    {
        $this->resolved = $resolved;

        return $this;
    }
}