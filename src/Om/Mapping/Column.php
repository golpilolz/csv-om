<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Om\Mapping;

use Attribute;

/**
 * Maps a property to a CSV column.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class Column
{
    /**
     * @param string|null $name Column name in the CSV header. Defaults to the property name.
     * @param string|null $type One of the {@see Types} constants. Inferred from the property type when null.
     * @param bool|null $nullable Whether an empty cell maps to null. Inferred from the property type when null.
     * @param string|null $format Date format ({@see \DateTimeInterface::format()}) for datetime columns.
     *                            Defaults to {@see \DateTimeInterface::ATOM}.
     */
    public function __construct(
        public ?string $name = null,
        public ?string $type = null,
        public ?bool $nullable = null,
        public ?string $format = null,
    ) {
    }
}
