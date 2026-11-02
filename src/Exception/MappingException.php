<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Exception;

use Golpilolz\CsvOm\Om\Mapping\Types;

final class MappingException extends \LogicException
{
    public static function classNotFound(string $className): self
    {
        return new self(sprintf('Class "%s" does not exist.', $className));
    }

    public static function notAnEntity(string $className): self
    {
        return new self(sprintf('Class "%s" is not a CSV entity. Add the #[CsvOm\Entity] attribute.', $className));
    }

    public static function notInstantiable(string $className): self
    {
        return new self(sprintf('CSV entity "%s" must be a concrete class.', $className));
    }

    public static function missingId(string $className): self
    {
        return new self(sprintf('CSV entity "%s" has no identifier. Add #[CsvOm\Id] on one property.', $className));
    }

    public static function multipleIds(string $className, array $fields): self
    {
        return new self(sprintf('CSV entity "%s" has several identifiers (%s); exactly one is supported.', $className, implode(', ', $fields)));
    }

    public static function invalidIdType(string $className, string $field, string $type): self
    {
        return new self(sprintf('Identifier "%s::$%s" must be of type "int" or "string", "%s" given.', $className, $field, $type));
    }

    public static function nullableId(string $className, string $field): self
    {
        return new self(sprintf('Identifier "%s::$%s" cannot be nullable.', $className, $field));
    }

    public static function staticProperty(string $className, string $field): self
    {
        return new self(sprintf('Static property "%s::$%s" cannot be mapped.', $className, $field));
    }

    public static function unknownType(string $className, string $field, string $type): self
    {
        return new self(sprintf('Unknown type "%s" on "%s::$%s". Supported types: %s.', $type, $className, $field, implode(', ', Types::ALL)));
    }

    public static function cannotInferType(string $className, string $field, string $propertyType): self
    {
        return new self(sprintf('Cannot infer a column type from "%s" on "%s::$%s". Set the "type" option of #[CsvOm\Column].', $propertyType, $className, $field));
    }

    public static function enumTypeMismatch(string $className, string $field, string $type, string $backingType): self
    {
        return new self(sprintf('"%s::$%s" is a backed enum of type "%s" but its column type is "%s".', $className, $field, $backingType, $type));
    }

    public static function nullableMismatch(string $className, string $field): self
    {
        return new self(sprintf('"%s::$%s" is mapped as nullable but its PHP type does not allow null.', $className, $field));
    }

    public static function duplicateColumn(string $className, string $column): self
    {
        return new self(sprintf('Column "%s" is mapped more than once in "%s".', $column, $className));
    }

    public static function invalidRepositoryClass(string $className, string $repositoryClass): self
    {
        return new self(sprintf('Repository "%s" of CSV entity "%s" must exist and implement RepositoryInterface.', $repositoryClass, $className));
    }

    public static function invalidControlCharacter(string $className, string $option, string $value): self
    {
        return new self(sprintf('Option "%s" of CSV entity "%s" must be a single character, "%s" given.', $option, $className, $value));
    }

    public static function invalidFile(string $className, string $file): self
    {
        return new self(sprintf('File "%s" of CSV entity "%s" must be a relative path without "..".', $file, $className));
    }
}
