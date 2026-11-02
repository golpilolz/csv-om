<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Exception;

final class HydrationException extends \RuntimeException
{
    public static function missingColumn(string $className, string $column, ?string $id): self
    {
        return new self(sprintf('Column "%s" mapped by entity "%s" is missing from the row%s.', $column, $className, self::id($id)));
    }

    public static function wrongClass(string $expected, object $object): self
    {
        return new self(sprintf('Expected an instance of "%s", "%s" given.', $expected, get_debug_type($object)));
    }

    public static function uninitialized(string $className, string $field, ?string $id): self
    {
        return new self(sprintf('Property "%s::$%s" is not nullable and must be initialized before it is written%s.', $className, $field, self::id($id)));
    }

    public static function typeMismatch(string $className, string $field, mixed $value, \TypeError $previous): self
    {
        return new self(sprintf('Cannot assign %s to property "%s::$%s".', get_debug_type($value), $className, $field), 0, $previous);
    }

    private static function id(?string $id): string
    {
        return $id !== null ? sprintf(' (id "%s")', $id) : '';
    }
}
