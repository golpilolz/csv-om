<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Storage;

use Golpilolz\CsvOm\Exception\StorageException;

/**
 * Read access to a CSV file that is not loaded into memory: the header is parsed on open, data rows are
 * read one at a time, either sequentially ({@see self::records()}) or by byte offset ({@see self::rowAt()}).
 *
 * Quoting follows RFC 4180 (no backslash escape). All values are strings.
 */
final class CsvStream
{
    /** @var resource */
    private $handle;

    /** @var list<string> */
    public readonly array $header;

    /** Byte offset of the first data record. */
    private int $dataStart;

    public function __construct(
        private readonly string $path,
        private readonly string $delimiter,
        private readonly string $enclosure,
    ) {
        $this->handle = $this->openHandle();

        $header = [];
        while (($record = fgetcsv($this->handle, null, $this->delimiter, $this->enclosure, '')) !== false) {
            if ($record === [null]) {
                continue; // blank line
            }
            $header = $record;
            break;
        }

        $seen = [];
        foreach ($header as $column) {
            $column = (string) $column;
            if (isset($seen[$column])) {
                throw StorageException::duplicateHeader($this->path, $column);
            }
            $seen[$column] = true;
        }

        $this->header = array_map(strval(...), $header);
        $this->dataStart = (int) ftell($this->handle);
    }

    public function __destruct()
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
    }

    /**
     * Every data row, keyed by its byte offset, in file order. Uses its own file handle, so it can be
     * iterated while {@see self::rowAt()} is called.
     *
     * @return \Generator<int, array<string, string>>
     */
    public function records(): \Generator
    {
        $handle = $this->openHandle($this->dataStart);
        $count = count($this->header);
        $row = 0;

        try {
            while (true) {
                $offset = (int) ftell($handle);
                $record = fgetcsv($handle, null, $this->delimiter, $this->enclosure, '');
                if ($record === false) {
                    return;
                }
                if ($record === [null]) {
                    continue;
                }
                ++$row;
                yield $offset => $this->combine($record, $count, $row);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * The data row starting at the given byte offset (as yielded by {@see self::records()}).
     *
     * @return array<string, string>
     */
    public function rowAt(int $offset): array
    {
        if (fseek($this->handle, $offset) !== 0) {
            throw StorageException::unreadable($this->path);
        }
        $record = fgetcsv($this->handle, null, $this->delimiter, $this->enclosure, '');
        if (!is_array($record) || $record === [null]) {
            throw StorageException::unreadable($this->path);
        }

        return $this->combine($record, count($this->header), 0);
    }

    /**
     * @param array<int, string|null> $record
     * @return array<string, string>
     */
    private function combine(array $record, int $count, int $row): array
    {
        if (count($record) !== $count) {
            throw StorageException::invalidRecord($this->path, $row + 1, $count, count($record));
        }

        return array_combine($this->header, array_map(static fn (?string $v): string => $v ?? '', $record));
    }

    /**
     * @return resource
     */
    private function openHandle(int $position = 0)
    {
        $handle = @fopen($this->path, 'rb');
        if ($handle === false) {
            throw StorageException::unreadable($this->path);
        }
        if ($position === 0 && fread($handle, 3) !== "\xEF\xBB\xBF") {
            rewind($handle); // no UTF-8 BOM
        } elseif ($position > 0) {
            fseek($handle, $position);
        }

        return $handle;
    }
}
