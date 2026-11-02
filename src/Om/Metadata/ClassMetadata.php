<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Om\Metadata;

/**
 * Mapping information of one CSV entity.
 *
 * @template T of object
 */
final class ClassMetadata
{
    /** @var array<string, string> column name => field name */
    private readonly array $columnToField;

    /**
     * @param class-string<T> $className
     * @param class-string<\Golpilolz\CsvOm\Om\Repository\RepositoryInterface> $repositoryClass
     * @param array<string, FieldMapping> $fieldMappings field name => mapping, in declaration order
     * @param string|null $delimiter null means "use the configured default"
     * @param string|null $enclosure null means "use the configured default"
     */
    public function __construct(
        public readonly string $className,
        public readonly string $file,
        public readonly string $repositoryClass,
        public readonly string $idField,
        public readonly array $fieldMappings,
        public readonly ?string $delimiter = null,
        public readonly ?string $enclosure = null,
    ) {
        $columnToField = [];
        foreach ($fieldMappings as $mapping) {
            $columnToField[$mapping->columnName] = $mapping->fieldName;
        }
        $this->columnToField = $columnToField;
    }

    public function getIdMapping(): FieldMapping
    {
        return $this->fieldMappings[$this->idField];
    }

    public function getIdColumn(): string
    {
        return $this->getIdMapping()->columnName;
    }

    public function hasField(string $fieldName): bool
    {
        return isset($this->fieldMappings[$fieldName]);
    }

    public function getFieldMapping(string $fieldName): FieldMapping
    {
        return $this->fieldMappings[$fieldName]
            ?? throw new \InvalidArgumentException(sprintf('Field "%s" is not mapped in "%s".', $fieldName, $this->className));
    }

    public function getFieldForColumn(string $columnName): ?string
    {
        return $this->columnToField[$columnName] ?? null;
    }

    /**
     * Column names in declaration order; this is the header written to the file.
     *
     * @return list<string>
     */
    public function getColumnNames(): array
    {
        return array_keys($this->columnToField);
    }
}
