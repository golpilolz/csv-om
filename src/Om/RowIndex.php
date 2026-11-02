<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Om;

use Golpilolz\CsvOm\Storage\CsvStream;

/**
 * The rows of a loaded file, by canonical id, without holding their cells in memory: only the byte offset of
 * each row is kept, and a row is read from the file when it is needed. Read-only. Internal to the unit of work.
 *
 * @implements \ArrayAccess<int|string, array<string, string>>
 * @implements \IteratorAggregate<int|string, array<string, string>>
 *
 * @internal
 */
final class RowIndex implements \ArrayAccess, \Countable, \IteratorAggregate
{
    /**
     * @param array<int|string, int> $offsets canonical id => byte offset of the row, in file order
     */
    public function __construct(
        private readonly array $offsets = [],
        private readonly ?CsvStream $stream = null,
    ) {
    }

    /**
     * @return list<int|string> ids in file order
     */
    public function ids(): array
    {
        return array_keys($this->offsets);
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->offsets[$offset]);
    }

    /**
     * @return array<string, string>
     */
    public function offsetGet(mixed $offset): array
    {
        if (!isset($this->offsets[$offset]) || $this->stream === null) {
            throw new \OutOfBoundsException(sprintf('No row with id "%s".', (string) $offset));
        }

        return $this->stream->rowAt($this->offsets[$offset]);
    }

    public function offsetSet(mixed $offset, mixed $value): never
    {
        throw new \LogicException('Loaded rows are read-only.');
    }

    public function offsetUnset(mixed $offset): never
    {
        throw new \LogicException('Loaded rows are read-only.');
    }

    public function count(): int
    {
        return count($this->offsets);
    }

    /**
     * Streams every row in file order.
     *
     * @return \Generator<int|string, array<string, string>>
     */
    public function getIterator(): \Generator
    {
        if ($this->stream === null) {
            return;
        }

        $records = $this->stream->records();
        foreach ($this->offsets as $id => $_) {
            yield $id => $records->current();
            $records->next();
        }
    }
}
