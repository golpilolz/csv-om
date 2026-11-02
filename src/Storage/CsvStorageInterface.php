<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Storage;

use Golpilolz\CsvOm\Exception\ConcurrentModificationException;
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
     * Opens the file for streaming reads, without loading its rows.
     *
     * @throws StorageException when the file is missing, unreadable or has a malformed header
     */
    public function open(string $file, ?string $delimiter = null, ?string $enclosure = null): CsvStream;

    /**
     * Relative paths (from the base directory, with "/") of the `.csv` files of a directory, sorted by path.
     * Hidden files and directories (names starting with ".") and symbolic links are skipped. A missing
     * directory has no files.
     *
     * @return list<string>
     */
    public function listFiles(string $directory, bool $recursive = true): array;

    /**
     * Version of the file as it is now (see {@see Fingerprint}): the empty string when it does not exist.
     */
    public function fingerprint(string $file): string;

    /**
     * Atomically replaces the file content. Each row must have exactly the header columns as keys;
     * null values are written as empty fields.
     *
     * @param list<string> $header
     * @param iterable<array<string, string|null>> $rows
     *
     * @param string|null $expectedFingerprint when given, the file must still be in that version (the empty
     *        string: still missing), checked under the write lock; null skips the check
     * @return string fingerprint of the file as written
     *
     * @throws StorageException
     * @throws ConcurrentModificationException when the file is not in the expected version; nothing is written
     */
    public function write(string $file, array $header, iterable $rows, ?string $delimiter = null, ?string $enclosure = null, ?string $expectedFingerprint = null): string;
}
