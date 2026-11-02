<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Om\Metadata;

use Golpilolz\CsvOm\Exception\MappingException;
use Golpilolz\CsvOm\Om\Mapping\Column;
use Golpilolz\CsvOm\Om\Mapping\Entity;
use Golpilolz\CsvOm\Om\Mapping\Id;
use Golpilolz\CsvOm\Om\Mapping\Types;
use Golpilolz\CsvOm\Om\Repository\CSVRepository;
use Golpilolz\CsvOm\Om\Repository\RepositoryInterface;

/**
 * Builds {@see ClassMetadata} from mapping attributes. Results are cached per class.
 */
final class MetadataFactory
{
    /** @var array<class-string, ClassMetadata> */
    private array $loaded = [];

    /**
     * @template T of object
     * @param class-string<T> $className
     * @return ClassMetadata<T>
     */
    public function getMetadataFor(string $className): ClassMetadata
    {
        $className = ltrim($className, '\\');

        return $this->loaded[$className] ??= $this->load($className);
    }

    public function isEntity(string $className): bool
    {
        return class_exists($className) && (new \ReflectionClass($className))->getAttributes(Entity::class) !== [];
    }

    private function load(string $className): ClassMetadata
    {
        if (!class_exists($className)) {
            throw MappingException::classNotFound($className);
        }

        $class = new \ReflectionClass($className);
        // Use the canonical case so the cache and error messages are consistent.
        $className = $class->getName();

        $entityAttributes = $class->getAttributes(Entity::class);
        if ($entityAttributes === []) {
            throw MappingException::notAnEntity($className);
        }
        // Hydration bypasses the constructor, so only abstract classes and enums are rejected.
        if ($class->isAbstract() || $class->isEnum()) {
            throw MappingException::notInstantiable($className);
        }

        /** @var Entity $entity */
        $entity = $entityAttributes[0]->newInstance();

        $fieldMappings = [];
        $idFields = [];
        foreach ($class->getProperties() as $property) {
            $isId = $property->getAttributes(Id::class) !== [];
            $columnAttributes = $property->getAttributes(Column::class);
            if (!$isId && $columnAttributes === []) {
                continue;
            }
            if ($property->isStatic()) {
                throw MappingException::staticProperty($className, $property->getName());
            }

            $column = $columnAttributes !== [] ? $columnAttributes[0]->newInstance() : new Column();
            $mapping = $this->buildFieldMapping($className, $property, $column);

            foreach ($fieldMappings as $existing) {
                if ($existing->columnName === $mapping->columnName) {
                    throw MappingException::duplicateColumn($className, $mapping->columnName);
                }
            }

            $fieldMappings[$mapping->fieldName] = $mapping;
            if ($isId) {
                $idFields[] = $mapping->fieldName;
            }
        }

        if ($idFields === []) {
            throw MappingException::missingId($className);
        }
        if (count($idFields) > 1) {
            throw MappingException::multipleIds($className, $idFields);
        }

        $idMapping = $fieldMappings[$idFields[0]];
        if ($idMapping->enumType === null && !in_array($idMapping->type, [Types::INT, Types::STRING], true)) {
            throw MappingException::invalidIdType($className, $idMapping->fieldName, $idMapping->type);
        }
        if ($idMapping->nullable) {
            throw MappingException::nullableId($className, $idMapping->fieldName);
        }

        $repositoryClass = $entity->repositoryClass ?? CSVRepository::class;
        if (!class_exists($repositoryClass) || !is_subclass_of($repositoryClass, RepositoryInterface::class)) {
            throw MappingException::invalidRepositoryClass($className, $repositoryClass);
        }

        foreach (['delimiter' => $entity->delimiter, 'enclosure' => $entity->enclosure] as $option => $value) {
            if ($value !== null && strlen($value) !== 1) {
                throw MappingException::invalidControlCharacter($className, $option, $value);
            }
        }

        $file = $entity->file ?? self::defaultFileName($class->getShortName());
        if ($file === '' || str_starts_with($file, '/') || in_array('..', explode('/', str_replace('\\', '/', $file)), true)) {
            throw MappingException::invalidFile($className, $file);
        }

        return new ClassMetadata(
            className: $className,
            file: $file,
            repositoryClass: $repositoryClass,
            idField: $idFields[0],
            fieldMappings: $fieldMappings,
            delimiter: $entity->delimiter,
            enclosure: $entity->enclosure,
        );
    }

    private function buildFieldMapping(string $className, \ReflectionProperty $property, Column $column): FieldMapping
    {
        $field = $property->getName();
        $propertyType = $property->getType();

        if ($propertyType !== null && !$propertyType instanceof \ReflectionNamedType) {
            // Union / intersection types: only accepted with an explicit type, and no enum support.
            if ($column->type === null) {
                throw MappingException::cannotInferType($className, $field, (string) $propertyType);
            }
            $this->assertKnownType($className, $field, $column->type);

            return new FieldMapping(
                fieldName: $field,
                columnName: $column->name ?? $field,
                type: $column->type,
                nullable: $this->resolveNullable($className, $field, $column, $propertyType->allowsNull()),
            );
        }

        $allowsNull = $propertyType === null || $propertyType->allowsNull();
        $typeName = $propertyType?->getName();
        $enumType = null;

        if ($typeName !== null && enum_exists($typeName)) {
            $backingType = (new \ReflectionEnum($typeName))->getBackingType();
            if ($backingType === null) {
                throw MappingException::cannotInferType($className, $field, $typeName . ' (pure enum)');
            }
            $type = (string) $backingType;
            if ($column->type !== null && $column->type !== $type) {
                throw MappingException::enumTypeMismatch($className, $field, $column->type, $type);
            }
            $enumType = $typeName;
        } elseif ($column->type !== null) {
            $type = $column->type;
        } else {
            $type = match (true) {
                $typeName === null, $typeName === 'string' => Types::STRING,
                $typeName === 'int' => Types::INT,
                $typeName === 'float' => Types::FLOAT,
                $typeName === 'bool' => Types::BOOL,
                $typeName === \DateTimeImmutable::class, $typeName === \DateTimeInterface::class => Types::DATETIME_IMMUTABLE,
                default => throw MappingException::cannotInferType($className, $field, $typeName),
            };
        }

        $this->assertKnownType($className, $field, $type);

        return new FieldMapping(
            fieldName: $field,
            columnName: $column->name ?? $field,
            type: $type,
            nullable: $this->resolveNullable($className, $field, $column, $allowsNull),
            enumType: $enumType,
        );
    }

    private function resolveNullable(string $className, string $field, Column $column, bool $allowsNull): bool
    {
        if ($column->nullable === true && !$allowsNull) {
            throw MappingException::nullableMismatch($className, $field);
        }

        return $column->nullable ?? $allowsNull;
    }

    private function assertKnownType(string $className, string $field, string $type): void
    {
        if (!in_array($type, Types::ALL, true)) {
            throw MappingException::unknownType($className, $field, $type);
        }
    }

    /**
     * "ProductCategory" => "product_category.csv"
     */
    public static function defaultFileName(string $shortClassName): string
    {
        return strtolower((string) preg_replace('/(?<=[a-z0-9])([A-Z])|(?<=[A-Z])([A-Z])(?=[a-z])/', '_$1$2', $shortClassName)) . '.csv';
    }
}
