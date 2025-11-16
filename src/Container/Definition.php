<?php

declare(strict_types=1);

namespace Radix\Container;

class Definition
{
    private mixed $concrete;
    private ?string $class = null;
    /**
     * @var array<int|string, mixed>
     */
    private array $arguments = [];
    /**
     * @var array<int, array{0: string, 1: array<int, mixed>}>
     */
    private array $calls = [];
    /**
     * @var array<string, mixed>
     */
    private array $properties = [];
    /**
     * @var array{class-string, string}|(callable(): mixed)|null
     */
    private $factory = null;
    /**
     * @var array<string, array<int, array<string, mixed>>>
     */
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

    /**
     * @param array{class-string, string}|(callable(): mixed)|null $factory
     */
    public function setFactory($factory): self
    {
        if ($factory === null) {
            $this->factory = null;
            return $this;
        }

        if (is_array($factory)) {
            // Måste vara [class-string, method]
            if (
                count($factory) !== 2
                || !is_string($factory[0])
                || !is_string($factory[1])
            ) {
                throw new \InvalidArgumentException('Factory array must be [class-string, method].');
            }

            /** @var array{class-string, string} $factory */
            $this->factory = $factory;

            return $this;
        }

        if (is_callable($factory)) {
            /** @var callable(): mixed $factoryCallable */
            $factoryCallable = $factory;
            $this->factory = $factoryCallable;

            return $this;
        }

        throw new \InvalidArgumentException('Factory must be [class-string, method], callable, or null.');
    }

    /**
     * @return array{class-string, string}|(callable(): mixed)|null
     */
    public function getFactory()
    {
        return $this->factory;
    }

    /**
     * Sätt alla egenskaper som ska injiceras på instansen.
     *
     * @param array<string, mixed> $properties
     */
    public function setProperties(array $properties): void
    {
        $this->properties = $properties;
    }

    /**
     * Hämta alla egenskaper som ska sättas på instansen.
     *
     * @return array<string, mixed>
     */
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

    public function setArgument(int|string $key, mixed $value): Definition
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

    /**
     * Sätt alla argument för definitionen.
     *
     * @param array<int|string, mixed> $arguments
     */
    public function setArguments(array $arguments): Definition
    {
        $this->arguments = $arguments;

        return $this;
    }

    /**
     * Hämta alla registrerade argument för definitionen.
     *
     * @return array<int|string, mixed>
     */
    public function getArguments(): array
    {
        return $this->arguments;
    }

    /**
     * Lägg till ett metodanrop som ska utföras på instansen efter skapande.
     *
     * @param array<int, mixed>|string $arguments
     */
    public function addMethodCall(string $method, array|string $arguments): Definition
    {
        if (empty($method)) {
            throw new \InvalidArgumentException('Method name must be a non-empty string.');
        }

        $this->calls[] = [
            $method,
            (array) $arguments,
        ];

        return $this;
    }

    /**
     * Sätt alla metodanrop som ska utföras på instansen.
     *
     * @param array<int, array{0: string, 1: array<int, mixed>}> $methods
     */
    public function setMethodCalls(array $methods): Definition
    {
        $this->calls = [];

        foreach ($methods as $call) {
            $this->addMethodCall($call[0], $call[1]);
        }

        return $this;
    }

    /**
     * Hämta alla metodanrop som ska utföras på instansen.
     *
     * @return array<int, array{0: string, 1: array<int, mixed>}>
     */
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

    /**
     * Sätt alla taggar för denna definition.
     *
     * @param array<string, array<int, array<string, mixed>>> $tags
     */
    public function setTags(array $tags): Definition
    {
        $this->tags = $tags;

        return $this;
    }

    /**
     * Hämta alla taggar för denna definition.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function getTags(): array
    {
        return $this->tags;
    }

    /**
     * Hämta alla attribut-set för en given tagg.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getTag(string $name): array
    {
        return $this->tags[$name] ?? array();
    }

    /**
     * Lägg till en tagg på definitionen.
     *
     * @param array<string, mixed> $attributes
     */
    public function addTag(string $name, array $attributes = []): Definition
    {
        if (empty($name)) {
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