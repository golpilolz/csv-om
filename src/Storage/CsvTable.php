<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Storage;

/**
 * Raw content of a CSV file: header and rows keyed by column name. All values are strings.
 */
final class CsvTable
{
    /**
     * @param list<string> $header
     * @param list<array<string, string>> $rows
     */
    public function __construct(
        public readonly array $header,
        public readonly array $rows,
    ) {
    }
}
