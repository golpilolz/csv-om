<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Om\Hydrator;

use Golpilolz\CsvOm\Exception\ConversionException;
use Golpilolz\CsvOm\Exception\HydrationException;
use Golpilolz\CsvOm\Om\Metadata\ClassMetadata;
use Golpilolz\CsvOm\Om\Type\TypeConverter;

/**
 * Turns CSV rows into objects and back.
 *
 * Objects are created without calling their constructor. Properties are written through a closure
 * bound to the declaring class, so private and readonly properties are supported.
 */
final class ObjectHydrator
{
    /** @var array<class-string, \ReflectionClass<object>> */
    private array $classes = [];

    /** @var array<string, \ReflectionProperty> "class::field" => property */
    private array $properties = [];

    /** @var array<class-string, \Closure(object, string, mixed): void> declaring class => setter */
    private array $setters = [];

    private readonly TypeConverter $converter;

    public function __construct(?TypeConverter $converter = null)
    {
        $this->converter = $converter ?? new TypeConverter();
    }

    /**
     * @template T of object
     * @param ClassMetadata<T> $metadata
     * @param array<string, string> $row cell values keyed by column name; unmapped columns are ignored
     * @return T
     */
    public function hydrate(ClassMetadata $metadata, array $row): object
    {
        $object = $this->reflectionClass($metadata->className)->newInstanceWithoutConstructor();
        $idColumn = $metadata->getIdColumn();
        $id = $row[$idColumn] ?? null;

        foreach ($metadata->fieldMappings as $field => $mapping) {
            if (!array_key_exists($mapping->columnName, $row)) {
                throw HydrationException::missingColumn($metadata->className, $mapping->columnName, $id);
            }

            try {
                $value = $this->converter->toPhp($row[$mapping->columnName], $mapping);
            } catch (ConversionException $e) {
                throw ConversionException::forEntity($metadata->className, $id, $e);
            }

            $this->setProperty($metadata->className, $object, $field, $value);
        }

        return $object;
    }

    /**
     * @param ClassMetadata<object> $metadata
     * @return array<string, string|null> cell values keyed by column name, in header order
     */
    public function extract(ClassMetadata $metadata, object $object): array
    {
        if (!$object instanceof $metadata->className) {
            throw HydrationException::wrongClass($metadata->className, $object);
        }

        $id = $this->extractId($metadata, $object);
        $row = [];
        foreach ($metadata->fieldMappings as $field => $mapping) {
            $property = $this->property($metadata->className, $field);

            if (!$property->isInitialized($object)) {
                if (!$mapping->nullable) {
                    throw HydrationException::uninitialized($metadata->className, $field, $id);
                }
                $row[$mapping->columnName] = null;
                continue;
            }

            try {
                $row[$mapping->columnName] = $this->converter->toCsv($property->getValue($object), $mapping);
            } catch (ConversionException $e) {
                throw ConversionException::forEntity($metadata->className, $id, $e);
            }
        }

        return $row;
    }

    /**
     * Identifier as written in the CSV file, or null when the id property is not set yet.
     *
     * @param ClassMetadata<object> $metadata
     */
    public function extractId(ClassMetadata $metadata, object $object): ?string
    {
        $property = $this->property($metadata->className, $metadata->idField);
        if (!$property->isInitialized($object)) {
            return null;
        }

        $value = $property->getValue($object);
        if ($value === null) {
            return null;
        }

        try {
            return $this->converter->toCsv($value, $metadata->getIdMapping());
        } catch (ConversionException $e) {
            throw ConversionException::forEntity($metadata->className, null, $e);
        }
    }

    /**
     * Current value of a mapped property, or null when it is not initialized.
     */
    public function getProperty(string $className, object $object, string $field): mixed
    {
        $property = $this->property($className, $field);

        return $property->isInitialized($object) ? $property->getValue($object) : null;
    }

    public function isInitialized(string $className, object $object, string $field): bool
    {
        return $this->property($className, $field)->isInitialized($object);
    }

    /**
     * Writes a property regardless of its visibility (readonly properties only while uninitialized).
     */
    public function setProperty(string $className, object $object, string $field, mixed $value): void
    {
        $declaringClass = $this->property($className, $field)->getDeclaringClass()->getName();

        $setter = $this->setters[$declaringClass] ??= \Closure::bind(
            static function (object $object, string $field, mixed $value): void {
                $object->$field = $value;
            },
            null,
            $declaringClass,
        );

        try {
            $setter($object, $field, $value);
        } catch (\TypeError $e) {
            throw HydrationException::typeMismatch($className, $field, $value, $e);
        }
    }

    private function property(string $className, string $field): \ReflectionProperty
    {
        return $this->properties[$className . '::' . $field] ??= $this->reflectionClass($className)->getProperty($field);
    }

    /**
     * @param class-string $className
     * @return \ReflectionClass<object>
     */
    private function reflectionClass(string $className): \ReflectionClass
    {
        return $this->classes[$className] ??= new \ReflectionClass($className);
    }
}
