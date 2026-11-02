<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Om\Metadata;

final class FieldMapping
{
    /**
     * @param string $type One of the {@see \Golpilolz\CsvOm\Om\Mapping\Types} constants.
     * @param class-string<\BackedEnum>|null $enumType Backed enum class when the property is an enum.
     * @param string|null $format Date format, set for datetime columns only.
     */
    public function __construct(
        public readonly string $fieldName,
        public readonly string $columnName,
        public readonly string $type,
        public readonly bool $nullable,
        public readonly ?string $enumType = null,
        public readonly ?string $format = null,
    ) {
    }
}
