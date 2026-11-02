<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Om;

/**
 * In-memory content of one entity file, as loaded. Internal to the unit of work.
 *
 * Only the raw cells are kept per row. Converted values are derived on demand, one field at a time, and
 * cached per field ({@see self::$canonicalColumns}, {@see self::$valueColumns}), so a big file costs
 * roughly the size of its text plus the fields that queries actually use.
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
     * @param array<string, array<string, string>> $rows raw cells (mapped and extra columns) as read, keyed by
     *        canonical id, in file order
     */
    public function __construct(
        public readonly array $header,
        public readonly array $extraColumns,
        public array $rows,
    ) {
    }

    /**
     * @param array<string, array<string, string>> $rows
     */
    public function setRows(array $rows): void
    {
        $this->rows = $rows;
        $this->canonicalColumns = [];
        $this->valueColumns = [];
    }
}
