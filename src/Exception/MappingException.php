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

    public static function generatedValueNotOnId(string $className, string $field): self
    {
        return new self(sprintf('#[CsvOm\\GeneratedValue] on "%s::$%s": it can only be used on the #[CsvOm\\Id] property.', $className, $field));
    }

    public static function invalidGeneratedValue(string $className, string $field, string $reason): self
    {
        return new self(sprintf('Invalid #[CsvOm\\GeneratedValue] on "%s::$%s": %s.', $className, $field, $reason));
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

    public static function formatNotAllowed(string $className, string $field, string $type): self
    {
        return new self(sprintf('Option "format" on "%s::$%s" is only supported for "%s" columns, column type is "%s".', $className, $field, Types::DATETIME_IMMUTABLE, $type));
    }

    public static function duplicateColumn(string $className, string $column): self
    {
        return new self(sprintf('Column "%s" is mapped more than once in "%s".', $column, $className));
    }

    public static function invalidRepositoryClass(string $className, string $repositoryClass): self
    {
        return new self(sprintf('Repository "%s" of CSV entity "%s" must exist and implement RepositoryInterface.', $repositoryClass, $className));
    }

    public static function repositoryEntityMismatch(string $repositoryClass, string $expected, string $actual): self
    {
        return new self(sprintf('Repository service "%s" is configured for entity "%s" but is used by "%s". Pass "%s::class" to the parent constructor.', $repositoryClass, $actual, $expected, $expected));
    }

    public static function invalidControlCharacter(string $className, string $option, string $value): self
    {
        return new self(sprintf('Option "%s" of CSV entity "%s" must be a single character, "%s" given.', $option, $className, $value));
    }

    public static function invalidFile(string $className, string $file): self
    {
        return new self(sprintf('File "%s" of CSV entity "%s" must be a relative path without "..".', $file, $className));
    }

    public static function associationAndColumn(string $className, string $field): self
    {
        return new self(sprintf('"%s::$%s" cannot be both a column (#[Id], #[Column]) and an association.', $className, $field));
    }

    public static function multipleAssociations(string $className, string $field): self
    {
        return new self(sprintf('"%s::$%s" has several association attributes; exactly one is allowed.', $className, $field));
    }

    public static function targetNotAnEntity(string $className, string $field, string $target): self
    {
        return new self(sprintf('Target "%s" of association "%s::$%s" is not a CSV entity.', $target, $className, $field));
    }

    public static function invalidCascade(string $className, string $field, string $value): self
    {
        return new self(sprintf('Unsupported cascade "%s" on "%s::$%s"; only "persist" is supported.', $value, $className, $field));
    }

    public static function invalidAssociationType(string $className, string $field, string $expected, string $actual): self
    {
        return new self(sprintf('Association "%s::$%s" must be typed "%s" (or untyped), "%s" given.', $className, $field, $expected, $actual));
    }

    public static function unexpectedAttribute(string $className, string $field, string $attribute, string $reason): self
    {
        return new self(sprintf('#[%s] on "%s::$%s" is not allowed: %s.', $attribute, $className, $field, $reason));
    }

    public static function missingInverse(string $className, string $field, string $target, string $property, string $expected): self
    {
        return new self(sprintf('Association "%s::$%s" refers to "%s::$%s", which must be %s.', $className, $field, $target, $property, $expected));
    }

    public static function invalidOnDelete(string $className, string $field, string $value): self
    {
        return new self(sprintf('Invalid onDelete "%s" on "%s::$%s", expected RESTRICT, SET NULL or CASCADE.', $value, $className, $field));
    }

    public static function setNullNotNullable(string $className, string $field): self
    {
        return new self(sprintf('onDelete "SET NULL" on "%s::$%s" requires a nullable, non-readonly association.', $className, $field));
    }

    public static function selfReferencingJoinTable(string $className, string $field): self
    {
        return new self(sprintf('Self-referencing many-to-many "%s::$%s" needs explicit joinColumn and inverseJoinColumn names in #[JoinTable].', $className, $field));
    }

    public static function duplicateJoinColumns(string $className, string $field): self
    {
        return new self(sprintf('#[JoinTable] of "%s::$%s" must use two different column names.', $className, $field));
    }
}
