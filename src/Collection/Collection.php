<?php

declare(strict_types=1);

namespace Radix\Collection;

use ArrayAccess;
use ArrayIterator;
use Closure;
use Countable;
use InvalidArgumentException;
use IteratorAggregate;
use Traversable;

/**
 * @implements ArrayAccess<int|string,mixed>
 * @implements IteratorAggregate<int|string,mixed>
 */
class Collection implements IteratorAggregate, Countable, ArrayAccess
{
    /**
     * @var array<int|string, mixed>
     */
    private array $elements;

    /**
     * @param array<int|string, mixed> $elements
     */
    public function __construct(array $elements = [])
    {
        $this->elements = $elements;
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->elements);
    }

    public function get(int|string $key, mixed $default = null): mixed
    {
        return $this->containsKey($key) ? $this->elements[$key] : $default;
    }

    public function set(int|string $key, mixed $value): void
    {
        $this->elements[$key] = $value;
    }

    public function add(mixed $element): bool
    {
        $this->elements[] = $element;
        return true;
    }

    /**
     * Kontrollera om en nyckel finns i samlingen.
     *
     * @param mixed $key
     */
    public function containsKey(mixed $key): bool
    {
        if (!is_int($key) && !is_string($key)) {
            return false;
        }

        // array_key_exists hanterar även null-värden korrekt
        return array_key_exists($key, $this->elements);
    }

    // ArrayAccess
    public function offsetExists(mixed $offset): bool
    {
        if (!is_int($offset) && !is_string($offset)) {
            return false;
        }
        return $this->containsKey($offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        if (!is_int($offset) && !is_string($offset)) {
            return null;
        }
        return $this->get($offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            $this->add($value);
            return;
        }

        if (!is_int($offset) && !is_string($offset)) {
            throw new InvalidArgumentException('Offset måste vara int eller string.');
        }

        $this->set($offset, $value);
    }

    public function offsetUnset(mixed $offset): void
    {
        if (!is_int($offset) && !is_string($offset)) {
            return;
        }
        $this->remove($offset);
    }

    public function remove(int|string $key): mixed
    {
        if (!array_key_exists($key, $this->elements)) {
            return null;
        }

        $removed = $this->elements[$key];
        unset($this->elements[$key]);
        return $removed;
    }

    public function count(): int
    {
        return count($this->elements);
    }

    /**
     * @return array<int|string, mixed>
     */
    public function toArray(): array
    {
        return $this->elements;
    }

    public function first(?callable $callback = null, mixed $default = null): mixed
    {
        if ($callback === null) {
            if ($this->isEmpty()) {
                return $default;
            }
            foreach ($this->elements as $value) {
                return $value;
            }
            return $default;
        }

        foreach ($this->elements as $key => $value) {
            if ($callback($value, $key)) {
                return $value;
            }
        }

        return $default;
    }

    public function last(mixed $default = null): mixed
    {
        if ($this->isEmpty()) {
            return $default;
        }
        $copy = $this->elements;
        $end = end($copy);
        return $end === false && !in_array(false, $copy, true) ? $default : $end;
    }

    /**
     * Returnerar värdet på första element där $key === $value (eller callback).
     */
    public function firstWhere(string|callable $key, mixed $value = null, mixed $default = null): mixed
    {
        if (is_callable($key)) {
            foreach ($this->elements as $k => $item) {
                if ($key($item, $k)) {
                    return $item;
                }
            }
            return $default;
        }

        foreach ($this->elements as $item) {
            if (is_array($item) && array_key_exists($key, $item) && $item[$key] === $value) {
                return $item;
            }
            if (is_object($item) && isset($item->{$key}) && $item->{$key} === $value) {
                return $item;
            }
        }
        return $default;
    }

    /**
     * @return array<int, mixed>
     */
    public function getValues(): array
    {
        return array_values($this->elements);
    }

    /**
     * @return array<int, int|string>
     */
    public function getKeys(): array
    {
        return array_keys($this->elements);
    }

    public function isEmpty(): bool
    {
        return $this->count() === 0;
    }

    public function clear(): void
    {
        $this->elements = [];
    }

    // Kedjbara helpers

    public function map(Closure $callback): self
    {
        $mapped = [];
        foreach ($this->elements as $k => $v) {
            $mapped[$k] = $callback($v, $k);
        }
        return new self($mapped);
    }

    /**
     * @param callable(mixed, int|string): bool|null $callback
     */
    public function filter(?callable $callback = null, int $mode = 0): self
    {
        if ($callback === null) {
            return new self(array_filter($this->elements));
        }

        return new self(
            array_filter(
                $this->elements,
                /**
                 * @param mixed      $v
                 * @param int|string $k
                 * @return bool
                 */
                static function (mixed $v, int|string $k) use ($callback): bool {
                    return (bool) $callback($v, $k);
                },
                ARRAY_FILTER_USE_BOTH
            )
        );
    }

    public function reject(callable $callback): self
    {
        return $this->filter(
            /**
             * @param mixed      $v
             * @param int|string $k
             */
            fn(mixed $v, int|string $k): bool => !$callback($v, $k)
        );
    }

    public function each(Closure $callback): self
    {
        foreach ($this->elements as $k => $v) {
            $callback($v, $k);
        }
        return $this;
    }

    public function reduce(Closure $callback, mixed $initial = null): mixed
    {
        $acc = $initial;
        foreach ($this->elements as $k => $v) {
            $acc = $callback($acc, $v, $k);
        }
        return $acc;
    }

    /**
     * Plockar ut ett fält från varje element (stödjer array/objekt).
     * @param  string       $field
     * @param  string|null  $keyBy
     * @return self
     */
    public function pluck(string $field, ?string $keyBy = null): self
    {
        $result = [];
        foreach ($this->elements as $k => $item) {
            $value = null;
            if (is_array($item) && array_key_exists($field, $item)) {
                $value = $item[$field];
            } elseif (is_object($item) && isset($item->{$field})) {
                $value = $item->{$field};
            }

            if ($keyBy !== null) {
                $kb = null;
                if (is_array($item) && array_key_exists($keyBy, $item)) {
                    $kb = $item[$keyBy];
                } elseif (is_object($item) && isset($item->{$keyBy})) {
                    $kb = $item->{$keyBy};
                }

                // Använd endast int|string som nycklar
                if (is_int($kb) || is_string($kb)) {
                    $result[$kb] = $value;
                    continue;
                }
            }

            $result[$k] = $value;
        }

        return new self($result);
    }

    /**
     * @param array<int, int|string> $keys
     */
    public function only(array $keys): self
    {
        $subset = [];
        foreach ($keys as $k) {
            if (!is_int($k) && !is_string($k)) {
                // Ignorera nycklar som inte är int|string (t.ex. false)
                continue;
            }
            if (array_key_exists($k, $this->elements)) {
                $subset[$k] = $this->elements[$k];
            }
        }
        return new self($subset);
    }

    /**
     * @param array<int, int|string> $keys
     */
    public function except(array $keys): self
    {
        $copy = $this->elements;
        foreach ($keys as $k) {
            if (!is_int($k) && !is_string($k)) {
                // Ignorera ogiltiga nycklar
                continue;
            }
            unset($copy[$k]);
        }
        return new self($copy);
    }

    public function unique(?Closure $callback = null, bool $strict = false): self
    {
        $seen = [];
        $result = [];

        foreach ($this->elements as $k => $v) {
            $key = $callback ? $callback($v, $k) : $v;

            // Serialize för att jämföra komplexa värden säkert
            if (is_object($key) || is_array($key)) {
                $hash = md5(serialize($key));
            } elseif ($strict) {
                $encoded = json_encode([$key, gettype($key)]);
                // Säkerställ att hash alltid är en sträng, även om json_encode misslyckas
                $hash = $encoded === false ? 'null' : $encoded;
            } else {
                // I icke-strikt läge: tillåt endast scalar/null, annars fallback till serialize
                if (is_scalar($key) || $key === null) {
                    /** @var scalar|null $key */
                    $hash = (string) $key;
                } else {
                    $hash = md5(serialize($key));
                }
            }

            if (!array_key_exists($hash, $seen)) {
                $seen[$hash] = true;
                $result[$k] = $v;
            }
        }

        return new self($result);
    }

    public function values(): self
    {
        return new self(array_values($this->elements));
    }

    public function keys(): self
    {
        return new self(array_keys($this->elements));
    }

    // Muterande hjälpmetoder (bekvämlighet)

    public function push(mixed ...$values): self
    {
        foreach ($values as $v) {
            $this->elements[] = $v;
        }
        return $this;
    }

    public function pop(): mixed
    {
        return array_pop($this->elements);
    }

    public function shift(): mixed
    {
        return array_shift($this->elements);
    }

    public function unshift(mixed ...$values): self
    {
        array_unshift($this->elements, ...$values);
        return $this;
    }
}
