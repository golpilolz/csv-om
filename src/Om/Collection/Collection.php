<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Om\Collection;

/**
 * List of related objects (to-many associations). Loaded objects come from the object manager's
 * identity map, so the same row is always the same instance.
 *
 * Collections created by the object manager load their content on first access. Create your own with
 * `new Collection()` (e.g. in a constructor); it starts empty and initialized.
 *
 * @template T of object
 * @implements \ArrayAccess<int, T>
 * @implements \IteratorAggregate<int, T>
 */
final class Collection implements \ArrayAccess, \Countable, \IteratorAggregate
{
    /** @var list<T> */
    private array $elements;

    private ?\Closure $loader = null;

    /**
     * @param iterable<T> $elements
     */
    public function __construct(iterable $elements = [])
    {
        $this->elements = [];
        foreach ($elements as $element) {
            $this->elements[] = $element;
        }
    }

    /**
     * @template U of object
     * @param \Closure(): iterable<U> $loader called once, on first access
     * @return self<U>
     */
    public static function lazy(\Closure $loader): self
    {
        $collection = new self();
        $collection->loader = $loader;

        return $collection;
    }

    public function isInitialized(): bool
    {
        return $this->loader === null;
    }

    /**
     * @param T $element
     */
    public function add(object $element): void
    {
        $this->initialize();
        if (!in_array($element, $this->elements, true)) {
            $this->elements[] = $element;
        }
    }

    /**
     * @param T $element
     * @return bool whether the element was in the collection
     */
    public function remove(object $element): bool
    {
        $this->initialize();
        $index = array_search($element, $this->elements, true);
        if ($index === false) {
            return false;
        }
        array_splice($this->elements, $index, 1);

        return true;
    }

    /**
     * @param T $element
     */
    public function contains(object $element): bool
    {
        $this->initialize();

        return in_array($element, $this->elements, true);
    }

    public function isEmpty(): bool
    {
        $this->initialize();

        return $this->elements === [];
    }

    public function clear(): void
    {
        $this->loader = null;
        $this->elements = [];
    }

    /**
     * @return list<T>
     */
    public function toArray(): array
    {
        $this->initialize();

        return $this->elements;
    }

    public function count(): int
    {
        $this->initialize();

        return count($this->elements);
    }

    /**
     * @return \ArrayIterator<int, T>
     */
    public function getIterator(): \ArrayIterator
    {
        $this->initialize();

        return new \ArrayIterator($this->elements);
    }

    public function offsetExists(mixed $offset): bool
    {
        $this->initialize();

        return isset($this->elements[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        $this->initialize();

        return $this->elements[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset !== null) {
            throw new \LogicException('Collections are lists: use $collection[] = $object or add().');
        }
        $this->add($value);
    }

    public function offsetUnset(mixed $offset): void
    {
        $this->initialize();
        if (isset($this->elements[$offset])) {
            array_splice($this->elements, (int) $offset, 1);
        }
    }

    private function initialize(): void
    {
        if ($this->loader === null) {
            return;
        }
        $loader = $this->loader;
        $this->loader = null;
        try {
            foreach ($loader() as $element) {
                if (!in_array($element, $this->elements, true)) {
                    $this->elements[] = $element;
                }
            }
        } catch (\Throwable $e) {
            $this->loader = $loader;
            throw $e;
        }
    }
}
