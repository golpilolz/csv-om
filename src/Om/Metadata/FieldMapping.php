<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Om\Metadata;

final readonly class FieldMapping
{
    /**
     * @param string $type One of the {@see \Golpilolz\CsvOm\Om\Mapping\Types} constants.
     * @param class-string<\BackedEnum>|null $enumType Backed enum class when the property is an enum.
     * @param string|null $format Date format, set for datetime columns only.
     */
    public function __construct(
        public string $fieldName,
        public string $columnName,
        public string $type,
        public bool $nullable,
        public ?string $enumType = null,
        public ?string $format = null,
    ) {
    }
}
