<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Om\Metadata;

use Golpilolz\CsvOm\Exception\MappingException;
use Golpilolz\CsvOm\Om\Collection\Collection;
use Golpilolz\CsvOm\Om\Mapping\Association;
use Golpilolz\CsvOm\Om\Mapping\Column;
use Golpilolz\CsvOm\Om\Mapping\JoinColumn;
use Golpilolz\CsvOm\Om\Mapping\LifecycleCallback;
use Golpilolz\CsvOm\Om\Mapping\JoinTable;
use Golpilolz\CsvOm\Om\Mapping\ManyToMany;
use Golpilolz\CsvOm\Om\Mapping\ManyToOne;
use Golpilolz\CsvOm\Om\Mapping\OneToMany;
use Golpilolz\CsvOm\Om\Mapping\OneToOne;
use Golpilolz\CsvOm\Om\Mapping\Entity;
use Golpilolz\CsvOm\Om\Mapping\GeneratedValue;
use Golpilolz\CsvOm\Om\Mapping\Id;
use Golpilolz\CsvOm\Om\Mapping\SourceFile;
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
        $associationMappings = [];
        $idFields = [];
        if ($entity->directory !== null && $entity->file !== null) {
            throw MappingException::invalidDirectoryOptions($className, '"file" and "directory" cannot be used together');
        }
        if ($entity->directory === null && ($entity->recursive !== null || $entity->newFile !== null)) {
            throw MappingException::invalidDirectoryOptions($className, '"recursive" and "newFile" need a "directory"');
        }
        $file = $entity->directory !== null ? rtrim(str_replace('\\', '/', $entity->directory), '/') : ($entity->file ?? self::defaultFileName($class->getShortName()));
        $sourceFileField = null;
        foreach ($class->getProperties() as $property) {
            if ($property->getAttributes(SourceFile::class) !== []) {
                $sourceFileField = $this->sourceFileField($className, $property, $entity->directory !== null, $sourceFileField);

                continue;
            }

            $associationAttributes = $property->getAttributes(Association::class, \ReflectionAttribute::IS_INSTANCEOF);
            if ($associationAttributes !== []) {
                if (count($associationAttributes) > 1) {
                    throw MappingException::multipleAssociations($className, $property->getName());
                }
                if ($property->getAttributes(Id::class) !== [] || $property->getAttributes(Column::class) !== []) {
                    throw MappingException::associationAndColumn($className, $property->getName());
                }
                if ($property->isStatic()) {
                    throw MappingException::staticProperty($className, $property->getName());
                }
                $associationMappings[$property->getName()] = $this->buildAssociationMapping($className, $file, $property, $associationAttributes[0]->newInstance());

                continue;
            }

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

        $usedColumns = array_map(static fn (FieldMapping $m) => $m->columnName, $fieldMappings);
        foreach ($associationMappings as $association) {
            if ($association->joinMapping === null) {
                continue;
            }
            if (in_array($association->joinMapping->columnName, $usedColumns, true)) {
                throw MappingException::duplicateColumn($className, $association->joinMapping->columnName);
            }
            $usedColumns[] = $association->joinMapping->columnName;
        }

        $idMapping = $fieldMappings[$idFields[0]];
        if ($idMapping->enumType === null && !in_array($idMapping->type, [Types::INT, Types::STRING], true)) {
            throw MappingException::invalidIdType($className, $idMapping->fieldName, $idMapping->type);
        }
        if ($idMapping->nullable) {
            throw MappingException::nullableId($className, $idMapping->fieldName);
        }

        $idGenerator = $this->resolveIdGenerator($class, $idMapping);

        $repositoryClass = $entity->repositoryClass ?? CSVRepository::class;
        if (!class_exists($repositoryClass) || !is_subclass_of($repositoryClass, RepositoryInterface::class)) {
            throw MappingException::invalidRepositoryClass($className, $repositoryClass);
        }

        foreach (['delimiter' => $entity->delimiter, 'enclosure' => $entity->enclosure] as $option => $value) {
            if ($value !== null && strlen($value) !== 1) {
                throw MappingException::invalidControlCharacter($className, $option, $value);
            }
        }

        if ($file === '' || str_starts_with($file, '/') || in_array('..', explode('/', str_replace('\\', '/', $file)), true)) {
            throw MappingException::invalidFile($className, $file);
        }

        $metadata = new ClassMetadata(
            className: $className,
            file: $file,
            repositoryClass: $repositoryClass,
            idField: $idFields[0],
            fieldMappings: $fieldMappings,
            delimiter: $entity->delimiter,
            enclosure: $entity->enclosure,
            associationMappings: $associationMappings,
            idGenerator: $idGenerator,
            lifecycleCallbacks: $this->lifecycleCallbacks($class),
            directory: $entity->directory !== null ? $file : null,
            recursive: $entity->recursive ?? true,
            newFile: $entity->newFile,
            sourceFileField: $sourceFileField,
        );
        if ($entity->newFile !== null && ($reason = $metadata->invalidSourcePath($entity->newFile)) !== null) {
            throw MappingException::invalidDirectoryOptions($className, sprintf('invalid "newFile" "%s": %s', $entity->newFile, $reason));
        }

        return $metadata;
    }

    private function buildAssociationMapping(string $className, string $ownerFile, \ReflectionProperty $property, Association $attribute): AssociationMapping
    {
        $field = $property->getName();
        $target = ltrim($attribute->getTargetEntity(), '\\');
        if (!$this->isEntity($target)) {
            throw MappingException::targetNotAnEntity($className, $field, $target);
        }
        $target = (new \ReflectionClass($target))->getName();

        foreach ($attribute->getCascade() as $cascade) {
            if ($cascade !== 'persist') {
                throw MappingException::invalidCascade($className, $field, (string) $cascade);
            }
        }

        [$type, $mappedBy, $inversedBy] = match (true) {
            $attribute instanceof ManyToOne => [AssociationMapping::MANY_TO_ONE, null, $attribute->inversedBy],
            $attribute instanceof OneToMany => [AssociationMapping::ONE_TO_MANY, $attribute->mappedBy, null],
            $attribute instanceof OneToOne => [AssociationMapping::ONE_TO_ONE, $attribute->mappedBy, $attribute->inversedBy],
            default => [AssociationMapping::MANY_TO_MANY, $attribute->getMappedBy(), $attribute->getInversedBy()],
        };
        if ($mappedBy !== null && $inversedBy !== null) {
            throw MappingException::unexpectedAttribute($className, $field, (new \ReflectionClass($attribute))->getShortName(), 'mappedBy and inversedBy cannot both be set');
        }
        $owning = $type === AssociationMapping::MANY_TO_ONE || ($type !== AssociationMapping::ONE_TO_MANY && $mappedBy === null);
        $toOne = $type === AssociationMapping::MANY_TO_ONE || $type === AssociationMapping::ONE_TO_ONE;

        $joinColumn = ($property->getAttributes(JoinColumn::class)[0] ?? null)?->newInstance();
        $joinTable = ($property->getAttributes(JoinTable::class)[0] ?? null)?->newInstance();
        if ($joinColumn !== null && !($toOne && $owning)) {
            throw MappingException::unexpectedAttribute($className, $field, 'JoinColumn', 'only the owning side of a to-one association is stored in a column');
        }
        if ($joinTable !== null && !($type === AssociationMapping::MANY_TO_MANY && $owning)) {
            throw MappingException::unexpectedAttribute($className, $field, 'JoinTable', 'only the owning side of a many-to-many has a join file');
        }

        // The other side must exist and point back here.
        $otherName = $mappedBy ?? $inversedBy;
        if ($otherName !== null) {
            $this->assertCounterpart($className, $field, $type, $owning, $target, $otherName);
        }

        $this->assertPropertyType($className, $property, $target, $toOne);

        if (!$toOne) {
            $mapping = ['joinFile' => null, 'joinOwnerColumn' => null, 'joinTargetColumn' => null];
            if ($type === AssociationMapping::MANY_TO_MANY && $owning) {
                $stem = (string) preg_replace('/\\.csv$/i', '', $ownerFile);
                $ownerColumn = $joinTable?->joinColumn ?? self::stem((new \ReflectionClass($className))->getShortName()) . '_id';
                $targetColumn = $joinTable?->inverseJoinColumn ?? self::stem((new \ReflectionClass($target))->getShortName()) . '_id';
                if ($ownerColumn === $targetColumn) {
                    throw $joinTable?->joinColumn !== null || $joinTable?->inverseJoinColumn !== null
                        ? MappingException::duplicateJoinColumns($className, $field)
                        : MappingException::selfReferencingJoinTable($className, $field);
                }
                $file = $joinTable?->file ?? $stem . '_' . self::stem($field) . '.csv';
                if ($file === '' || str_starts_with($file, '/') || in_array('..', explode('/', str_replace('\\', '/', $file)), true)) {
                    throw MappingException::invalidFile($className, $file);
                }
                $mapping = ['joinFile' => $file, 'joinOwnerColumn' => $ownerColumn, 'joinTargetColumn' => $targetColumn];
            }

            return new AssociationMapping($field, $type, $target, $mappedBy, $inversedBy, in_array('persist', $attribute->getCascade(), true), ...$mapping);
        }

        $allowsNull = $property->getType() === null || $property->getType()->allowsNull();
        if ($joinColumn?->nullable === true && !$allowsNull) {
            throw MappingException::nullableMismatch($className, $field);
        }
        $nullable = $joinColumn?->nullable ?? $allowsNull;
        if (!$owning) {
            return new AssociationMapping($field, $type, $target, $mappedBy, $inversedBy, in_array('persist', $attribute->getCascade(), true), $nullable);
        }

        $onDelete = $joinColumn?->onDelete ?? JoinColumn::RESTRICT;
        if (!in_array($onDelete, [JoinColumn::RESTRICT, JoinColumn::SET_NULL, JoinColumn::CASCADE], true)) {
            throw MappingException::invalidOnDelete($className, $field, $onDelete);
        }
        if ($onDelete === JoinColumn::SET_NULL && (!$nullable || $property->isReadOnly())) {
            throw MappingException::setNullNotNullable($className, $field);
        }

        $id = $this->targetIdMapping($target);
        $joinMapping = new FieldMapping(
            fieldName: $field,
            columnName: $joinColumn?->name ?? $field . '_id',
            type: $id->type,
            nullable: $nullable,
            enumType: $id->enumType,
        );

        return new AssociationMapping($field, $type, $target, $mappedBy, $inversedBy, in_array('persist', $attribute->getCascade(), true), $nullable, $joinMapping, $onDelete);
    }

    /**
     * Validates a #[SourceFile] property and returns its name.
     */
    private function sourceFileField(string $className, \ReflectionProperty $property, bool $directory, ?string $already): string
    {
        $field = $property->getName();
        $type = $property->getType();
        if (!$directory) {
            throw MappingException::invalidSourceFile($className, $field, 'only an entity mapped to a "directory" has source files');
        }
        if ($already !== null) {
            throw MappingException::invalidSourceFile($className, $field, sprintf('"%s" is already the source file property', $already));
        }
        if ($property->isStatic() || $property->getAttributes(Id::class) !== [] || $property->getAttributes(Column::class) !== []
            || $property->getAttributes(Association::class, \ReflectionAttribute::IS_INSTANCEOF) !== []) {
            throw MappingException::invalidSourceFile($className, $field, 'it must be a plain, non-static property, not a column or an association');
        }
        if ($type !== null && (!$type instanceof \ReflectionNamedType || $type->getName() !== 'string')) {
            throw MappingException::invalidSourceFile($className, $field, 'it must be typed ?string');
        }

        return $field;
    }

    /**
     * Methods marked with a lifecycle attribute (#[PrePersist], ...), including inherited and private ones.
     *
     * @param \ReflectionClass<object> $class
     * @return array<string, list<string>> event name => method names
     */
    private function lifecycleCallbacks(\ReflectionClass $class): array
    {
        $callbacks = [];
        foreach ($class->getMethods() as $method) {
            foreach ($method->getAttributes(LifecycleCallback::class, \ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
                $name = $method->getName();
                if ($method->isStatic() || $method->isAbstract()) {
                    throw MappingException::invalidLifecycleCallback($class->getName(), $name, 'it must be a concrete, non-static method');
                }
                if ($method->getNumberOfRequiredParameters() > 1 || $method->getNumberOfParameters() > 1) {
                    throw MappingException::invalidLifecycleCallback($class->getName(), $name, 'it takes no argument or only the event arguments');
                }
                $callbacks[$attribute->getName()::EVENT][] = $name;
            }
        }

        return $callbacks;
    }

    /**
     * @param \ReflectionClass<object> $class
     */
    private function resolveIdGenerator(\ReflectionClass $class, FieldMapping $idMapping): ?string
    {
        $className = $class->getName();
        $generator = null;
        foreach ($class->getProperties() as $property) {
            $attributes = $property->getAttributes(GeneratedValue::class);
            if ($attributes === []) {
                continue;
            }
            if ($property->getName() !== $idMapping->fieldName) {
                throw MappingException::generatedValueNotOnId($className, $property->getName());
            }
            $generator = $attributes[0]->newInstance()->strategy;
        }
        if ($generator === null) {
            return null;
        }

        $field = $idMapping->fieldName;
        if ($idMapping->enumType !== null) {
            throw MappingException::invalidGeneratedValue($className, $field, 'the id cannot be an enum');
        }
        match ($generator) {
            GeneratedValue::INCREMENT => $idMapping->type === Types::INT
                || throw MappingException::invalidGeneratedValue($className, $field, 'INCREMENT needs an int id'),
            GeneratedValue::UUID => $idMapping->type === Types::STRING
                || throw MappingException::invalidGeneratedValue($className, $field, 'UUID needs a string id'),
            default => throw MappingException::invalidGeneratedValue($className, $field, sprintf('unknown strategy "%s"', $generator)),
        };

        return $generator;
    }

    /**
     * Checks that "$target::$other" is the matching side of the association at "$className::$field":
     * an owning side needs an inverse side naming it in mappedBy; an inverse side needs an owning side.
     */
    private function assertCounterpart(string $className, string $field, string $type, bool $owning, string $target, string $other): void
    {
        $targetClass = new \ReflectionClass($target);
        $property = $targetClass->hasProperty($other) ? $targetClass->getProperty($other) : null;
        $attribute = ($property?->getAttributes(Association::class, \ReflectionAttribute::IS_INSTANCEOF)[0] ?? null)?->newInstance();

        $expectedClass = match ($type) {
            AssociationMapping::MANY_TO_ONE => OneToMany::class,
            AssociationMapping::ONE_TO_MANY => ManyToOne::class,
            AssociationMapping::ONE_TO_ONE => OneToOne::class,
            default => ManyToMany::class,
        };

        $ok = $attribute instanceof $expectedClass
            && ltrim($attribute->getTargetEntity(), '\\') === $className
            && ($owning
                ? $attribute->getMappedBy() === $field
                : $attribute->getMappedBy() === null && ($attribute->getInversedBy() === null || $attribute->getInversedBy() === $field));

        if (!$ok) {
            throw MappingException::missingInverse($className, $field, $target, $other, $owning
                ? sprintf('an inverse #[%s] with mappedBy: \'%s\'', (new \ReflectionClass($expectedClass))->getShortName(), $field)
                : sprintf('an owning #[%s]', (new \ReflectionClass($expectedClass))->getShortName()));
        }
    }

    private function assertPropertyType(string $className, \ReflectionProperty $property, string $target, bool $toOne): void
    {
        $type = $property->getType();
        if ($type === null) {
            return;
        }
        if (!$type instanceof \ReflectionNamedType) {
            throw MappingException::invalidAssociationType($className, $property->getName(), $toOne ? $target : Collection::class, (string) $type);
        }
        $name = $type->getName();
        $valid = $toOne
            ? (is_a($target, $name, true) || $name === 'object' || $name === 'mixed')
            : ($name === Collection::class || $name === 'mixed');
        if (!$valid) {
            throw MappingException::invalidAssociationType($className, $property->getName(), $toOne ? $target : Collection::class, $name);
        }
    }

    /**
     * Mapping of the #[Id] property of a target entity, without building the target's whole metadata
     * (which could loop back here).
     */
    private function targetIdMapping(string $target): FieldMapping
    {
        foreach ((new \ReflectionClass($target))->getProperties() as $property) {
            if ($property->getAttributes(Id::class) === []) {
                continue;
            }
            $column = ($property->getAttributes(Column::class)[0] ?? null)?->newInstance() ?? new Column();

            return $this->buildFieldMapping($target, $property, $column);
        }

        throw MappingException::missingId($target);
    }

    private static function stem(string $name): string
    {
        return substr(self::defaultFileName($name), 0, -4);
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
                format: $this->resolveFormat($className, $field, $column, $column->type),
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
            format: $this->resolveFormat($className, $field, $column, $type),
        );
    }

    private function resolveFormat(string $className, string $field, Column $column, string $type): ?string
    {
        if ($type !== Types::DATETIME_IMMUTABLE) {
            if ($column->format !== null) {
                throw MappingException::formatNotAllowed($className, $field, $type);
            }

            return null;
        }

        return $column->format ?? \DateTimeInterface::ATOM;
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
