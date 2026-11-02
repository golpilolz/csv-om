<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Storage;

use Golpilolz\CsvOm\Exception\StorageException;

/**
 * Reads and writes whole CSV files, relative to a base directory.
 *
 * A null delimiter/enclosure means "use the storage default".
 */
interface CsvStorageInterface
{
    public function exists(string $file): bool;

    /**
     * The first record is the header; following records are returned keyed by header column.
     *
     * @throws StorageException when the file is missing, unreadable or malformed
     */
    public function read(string $file, ?string $delimiter = null, ?string $enclosure = null): CsvTable;

    /**
     * Atomically replaces the file content. Each row must have exactly the header columns as keys;
     * null values are written as empty fields.
     *
     * @param list<string> $header
     * @param iterable<array<string, string|null>> $rows
     *
     * @throws StorageException
     */
    public function write(string $file, array $header, iterable $rows, ?string $delimiter = null, ?string $enclosure = null): void;
}
