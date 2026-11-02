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
final readonly class RowIndex implements \ArrayAccess, \Countable, \IteratorAggregate
{
    /** @var array<int|string, int> canonical id => index of its file, only when there are several files */
    private array $fileOf;

    /**
     * @param array<int|string, int> $offsets canonical id => byte offset of the row in its file, grouped by file
     *        (files in order, rows in file order)
     * @param list<CsvStream> $streams the files
     * @param list<list<int|string>> $fileIds ids of the rows of each file, in file order
     */
    public function __construct(
        private array $offsets = [],
        private array $streams = [],
        private array $fileIds = [],
    ) {
        $fileOf = [];
        if (count($streams) > 1) {
            foreach ($fileIds as $index => $ids) {
                foreach ($ids as $id) {
                    $fileOf[$id] = $index;
                }
            }
        }
        $this->fileOf = $fileOf;
    }

    /**
     * @return list<int|string> ids in file order
     */
    public function ids(): array
    {
        return array_keys($this->offsets);
    }

    /**
     * Index (in {@see Table::$files}) of the file holding the row.
     */
    public function fileIndex(int|string $id): int
    {
        return $this->fileOf[$id] ?? 0;
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
        if (!isset($this->offsets[$offset])) {
            throw new \OutOfBoundsException(sprintf('No row with id "%s".', (string) $offset));
        }

        return $this->streams[$this->fileIndex($offset)]->rowAt($this->offsets[$offset]);
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
     * Streams every row in file order, file after file.
     *
     * @return \Generator<int|string, array<string, string>>
     */
    public function getIterator(): \Generator
    {
        foreach (array_keys($this->streams) as $file) {
            yield from $this->rowsOfFile($file);
        }
    }

    /**
     * Streams the rows of one file, in file order.
     *
     * @return \Generator<int|string, array<string, string>>
     */
    public function rowsOfFile(int $file): \Generator
    {
        if (!isset($this->streams[$file])) {
            return;
        }

        $records = $this->streams[$file]->records();
        foreach ($this->fileIds[$file] as $id) {
            yield $id => $records->current();
            $records->next();
        }
    }
}
