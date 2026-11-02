<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Exception;

final class QueryException extends \InvalidArgumentException
{
    public static function unknownField(string $className, string $field): self
    {
        return new self(sprintf('Unknown field "%s" in criteria or order of entity "%s".', $field, $className));
    }

    public static function invalidDirection(string $field, string $direction): self
    {
        return new self(sprintf('Invalid order direction "%s" for field "%s", expected "ASC" or "DESC".', $direction, $field));
    }

    public static function invalidCriterion(string $className, string $field, ConversionException $previous): self
    {
        return new self(sprintf('Invalid value for field "%s" of entity "%s": %s', $field, $className, $previous->getMessage()), 0, $previous);
    }

    public static function invalidLimit(string $option, int $value): self
    {
        return new self(sprintf('"%s" must be a positive integer, %d given.', $option, $value));
    }
}
