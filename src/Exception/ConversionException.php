<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Exception;

final class ConversionException extends \RuntimeException
{
    public static function fromCsv(string $field, string $value, string $expected): self
    {
        return new self(sprintf('Cannot convert value "%s" of field "%s" to %s.', $value, $field, $expected));
    }

    public static function toCsv(string $field, mixed $value, string $expected): self
    {
        return new self(sprintf('Cannot write field "%s": expected %s, %s given.', $field, $expected, get_debug_type($value)));
    }

    public static function nullNotAllowed(string $field): self
    {
        return new self(sprintf('Field "%s" is not nullable but its value is null or empty.', $field));
    }

    public static function forEntity(string $className, ?string $id, self $previous): self
    {
        return new self(
            sprintf('%s (entity "%s"%s)', $previous->getMessage(), $className, $id !== null ? sprintf(', id "%s"', $id) : ''),
            0,
            $previous,
        );
    }
}
