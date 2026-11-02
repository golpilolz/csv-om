<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Om;

/**
 * In-memory content of one entity file, as loaded. Internal to the unit of work.
 *
 * Rows stay on disk: only their byte offsets are kept ({@see RowIndex}). Converted values are derived on demand,
 * one field at a time, and cached per field ({@see self::$canonicalColumns}, {@see self::$valueColumns}), so a big
 * file costs a few dozen bytes per row plus the fields that queries actually use.
 *
 * @internal
 */
final class Table
{
    /** @var array<string, array<string, ?string>> field => (row id => canonical CSV string), filled lazily */
    public array $canonicalColumns = [];

    /** @var array<string, array<string, mixed>> field => (row id => PHP value), filled lazily */
    public array $valueColumns = [];

    /**
     * @param list<string> $header header of the file (mapped columns first when the file did not exist)
     * @param list<string> $extraColumns columns present in the file but not mapped; kept so they can be written back
     * @param string $fingerprint version of the file when it was loaded (empty: it did not exist), see {@see \Golpilolz\CsvOm\Storage\Fingerprint}
     * @param RowIndex $rows raw cells (mapped and extra columns) as read, by canonical id, in file order; rows are
     *        read from the file on demand
     */
    public function __construct(
        public readonly array $header,
        public readonly array $extraColumns,
        public readonly RowIndex $rows,
        public readonly string $fingerprint = '',
    ) {
    }
}
