<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Om;

/**
 * In-memory content of one entity file, as loaded. Internal to the unit of work.
 *
 * @internal
 */
final class Table
{
    /**
     * @param list<string> $header header of the file (mapped columns first when the file did not exist)
     * @param list<string> $extraColumns columns present in the file but not mapped; kept so they can be written back
     * @param array<string, array{raw: array<string, string>, values: array<string, mixed>, canonical: array<string, ?string>}> $rows
     *        keyed by canonical id, in file order. "raw" holds every cell (mapped and extra) as read; "values" the
     *        converted PHP value per field; "canonical" the canonical CSV string per field (used to match criteria).
     */
    public function __construct(
        public readonly array $header,
        public readonly array $extraColumns,
        public array $rows,
    ) {
    }
}
