<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Om\Metadata;

use Golpilolz\CsvOm\Om\Mapping\GeneratedValue;

/**
 * Mapping information of one CSV entity.
 *
 * @template T of object
 */
final readonly class ClassMetadata
{
    /** @var array<string, string> column name => field name */
    private array $columnToField;

    /** @var array<string, FieldMapping> field name => mapping of every column: scalar fields, then association join columns */
    public array $columnMappings;

    /**
     * @param class-string<T> $className
     * @param class-string<\Golpilolz\CsvOm\Om\Repository\RepositoryInterface> $repositoryClass
     * @param array<string, FieldMapping> $fieldMappings field name => mapping, in declaration order
     * @param array<string, AssociationMapping> $associationMappings field name => mapping, in declaration order
     * @param string|null $delimiter null means "use the configured default"
     * @param string|null $enclosure null means "use the configured default"
     * @param array<string, list<string>> $lifecycleCallbacks event name => entity methods to call, in declaration order
     * @param string|null $directory directory of the entity files, null for an entity mapped to one file ({@see self::$file}; for a directory entity it holds the directory too)
     * @param string|null $newFile file, relative to the directory, for new objects without a source file
     * @param string|null $sourceFileField property holding the relative path of the file of a row
     * @param string|null $idGenerator a {@see GeneratedValue} strategy, null when ids are assigned by the application
     */
    public function __construct(
        public string $className,
        public string $file,
        public string $repositoryClass,
        public string $idField,
        public array $fieldMappings,
        public ?string $delimiter = null,
        public ?string $enclosure = null,
        public array $associationMappings = [],
        public ?string $idGenerator = null,
        public array $lifecycleCallbacks = [],
        public ?string $directory = null,
        public bool $recursive = true,
        public ?string $newFile = null,
        public ?string $sourceFileField = null,
    ) {
        $columnMappings = $fieldMappings;
        foreach ($associationMappings as $association) {
            if ($association->joinMapping !== null) {
                $columnMappings[$association->fieldName] = $association->joinMapping;
            }
        }
        $this->columnMappings = $columnMappings;

        $columnToField = [];
        foreach ($columnMappings as $mapping) {
            $columnToField[$mapping->columnName] = $mapping->fieldName;
        }
        $this->columnToField = $columnToField;
    }

    /**
     * Why a path, relative to the entity directory, cannot hold rows of the entity; null when it can: a
     * relative `.csv` path without "..", without hidden parts (they are not listed) and, when the directory
     * is not read recursively, without subdirectory.
     */
    public function invalidSourcePath(string $path): ?string
    {
        return self::sourcePathProblem($path, $this->recursive);
    }

    /**
     * Same check as {@see self::invalidSourcePath()} for any directory, given whether it is read recursively.
     */
    public static function sourcePathProblem(string $path, bool $recursive): ?string
    {
        $parts = explode('/', str_replace('\\', '/', $path));
        if (str_contains($path, '\\')) {
            return 'use "/" as the path separator';
        }
        if ($path === '' || str_starts_with($path, '/') || in_array('..', $parts, true) || in_array('', $parts, true)) {
            return 'it must be a relative path without ".."';
        }
        foreach ($parts as $part) {
            if (str_starts_with($part, '.')) {
                return 'hidden files and directories are not read';
            }
        }
        if (strcasecmp(substr($path, -4), '.csv') !== 0) {
            return 'it must end with ".csv"';
        }
        if (!$recursive && count($parts) > 1) {
            return 'the directory is not read recursively';
        }

        return null;
    }

    public function isDirectory(): bool
    {
        return $this->directory !== null;
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

    public function hasAssociation(string $fieldName): bool
    {
        return isset($this->associationMappings[$fieldName]);
    }

    public function getAssociationMapping(string $fieldName): AssociationMapping
    {
        return $this->associationMappings[$fieldName]
            ?? throw new \InvalidArgumentException(sprintf('Association "%s" is not mapped in "%s".', $fieldName, $this->className));
    }

    /**
     * Whether queries can filter or sort on this name: a scalar field or an owning to-one association.
     */
    public function hasColumnMapping(string $fieldName): bool
    {
        return isset($this->columnMappings[$fieldName]);
    }

    public function getFieldForColumn(string $columnName): ?string
    {
        return $this->columnToField[$columnName] ?? null;
    }

    /**
     * Column names (scalar fields, then association join columns); this is the header written to the file.
     *
     * @return list<string>
     */
    public function getColumnNames(): array
    {
        return array_keys($this->columnToField);
    }
}
