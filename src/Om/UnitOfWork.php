<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Om;

use Golpilolz\CsvOm\Exception\ConversionException;
use Golpilolz\CsvOm\Exception\QueryException;
use Golpilolz\CsvOm\Exception\StorageException;
use Golpilolz\CsvOm\Om\Hydrator\ObjectHydrator;
use Golpilolz\CsvOm\Om\Metadata\ClassMetadata;
use Golpilolz\CsvOm\Om\Type\TypeConverter;
use Golpilolz\CsvOm\Storage\CsvStorageInterface;

/**
 * Loads entity files on demand and keeps the identity map (one object per entity id).
 *
 * A file is read once, when its entity is first used, and every row is converted at that moment, so
 * invalid data is reported immediately. Unmapped columns are kept in each raw row to be written back.
 */
final class UnitOfWork
{
    /** @var array<class-string, Table> */
    private array $tables = [];

    /** @var array<class-string, array<string, object>> class => canonical id => object */
    private array $identityMap = [];

    public function __construct(
        private readonly CsvStorageInterface $storage,
        private readonly ObjectHydrator $hydrator,
        private readonly TypeConverter $converter,
    ) {
    }

    /**
     * @template T of object
     * @param ClassMetadata<T> $metadata
     * @return T|null
     */
    public function find(ClassMetadata $metadata, mixed $id): ?object
    {
        $canonicalId = $this->canonicalId($metadata, $id);
        if ($canonicalId === null) {
            return null;
        }

        if (isset($this->identityMap[$metadata->className][$canonicalId])) {
            return $this->identityMap[$metadata->className][$canonicalId];
        }

        $table = $this->getTable($metadata);
        if (!isset($table->rows[$canonicalId])) {
            return null;
        }

        return $this->materialize($metadata, $canonicalId, $table->rows[$canonicalId]['raw']);
    }

    /**
     * @template T of object
     * @param ClassMetadata<T> $metadata
     * @param array<string, mixed> $criteria
     * @param array<string, string> $orderBy
     * @return list<T>
     */
    public function findBy(ClassMetadata $metadata, array $criteria, array $orderBy = [], ?int $limit = null, int $offset = 0): array
    {
        if ($limit !== null && $limit < 0) {
            throw QueryException::invalidLimit('limit', $limit);
        }
        if ($offset < 0) {
            throw QueryException::invalidLimit('offset', $offset);
        }

        $rows = $this->match($metadata, $criteria);
        if ($orderBy !== []) {
            $rows = $this->sort($metadata, $rows, $orderBy);
        }
        $rows = array_slice($rows, $offset, $limit, true);

        $objects = [];
        foreach ($rows as $id => $row) {
            $objects[] = $this->identityMap[$metadata->className][(string) $id] ?? $this->materialize($metadata, (string) $id, $row['raw']);
        }

        return $objects;
    }

    /**
     * @param ClassMetadata<object> $metadata
     * @param array<string, mixed> $criteria
     */
    public function count(ClassMetadata $metadata, array $criteria = []): int
    {
        return count($this->match($metadata, $criteria));
    }

    /**
     * Raw row (mapped and unmapped columns) as loaded from the file, or null if the id is not in the file.
     *
     * @param ClassMetadata<object> $metadata
     * @return array<string, string>|null
     */
    public function getOriginalRow(ClassMetadata $metadata, mixed $id): ?array
    {
        $canonicalId = $this->canonicalId($metadata, $id);

        return $canonicalId === null ? null : ($this->getTable($metadata)->rows[$canonicalId]['raw'] ?? null);
    }

    /**
     * @param ClassMetadata<object> $metadata
     */
    public function getTable(ClassMetadata $metadata): Table
    {
        return $this->tables[$metadata->className] ??= $this->load($metadata);
    }

    private function load(ClassMetadata $metadata): Table
    {
        if (!$this->storage->exists($metadata->file)) {
            return new Table($metadata->getColumnNames(), [], []);
        }

        $csv = $this->storage->read($metadata->file, $metadata->delimiter, $metadata->enclosure);
        $path = $metadata->file;

        $missing = array_diff($metadata->getColumnNames(), $csv->header);
        if ($missing !== []) {
            throw new StorageException(sprintf(
                'CSV file "%s" of entity "%s" is missing mapped columns: %s.',
                $path,
                $metadata->className,
                implode(', ', $missing),
            ));
        }

        $rows = [];
        $idColumn = $metadata->getIdColumn();
        foreach ($csv->rows as $index => $raw) {
            $values = [];
            $canonical = [];
            foreach ($metadata->fieldMappings as $field => $mapping) {
                try {
                    $value = $this->converter->toPhp($raw[$mapping->columnName], $mapping);
                    $canonical[$field] = $this->converter->toCsv($value, $mapping);
                } catch (ConversionException $e) {
                    throw ConversionException::forEntity($metadata->className, $raw[$idColumn], $e);
                }
                $values[$field] = $value;
            }

            $id = $canonical[$metadata->idField];
            if (isset($rows[$id])) {
                throw new StorageException(sprintf(
                    'CSV file "%s" of entity "%s" has duplicate id "%s" (data row %d).',
                    $path,
                    $metadata->className,
                    $id,
                    $index + 1,
                ));
            }
            $rows[$id] = ['raw' => $raw, 'values' => $values, 'canonical' => $canonical];
        }

        return new Table(
            $csv->header,
            array_values(array_diff($csv->header, $metadata->getColumnNames())),
            $rows,
        );
    }

    /**
     * @param array<string, string> $raw
     */
    private function materialize(ClassMetadata $metadata, string $canonicalId, array $raw): object
    {
        return $this->identityMap[$metadata->className][$canonicalId] = $this->hydrator->hydrate($metadata, $raw);
    }

    /**
     * Canonical id, or null when the given value cannot be an id of this entity (e.g. "abc" for an int id).
     */
    private function canonicalId(ClassMetadata $metadata, mixed $id): ?string
    {
        if (!is_scalar($id) && !$id instanceof \BackedEnum) {
            throw new \InvalidArgumentException(sprintf('Identifier of entity "%s" must be a scalar, %s given.', $metadata->className, get_debug_type($id)));
        }

        try {
            return $this->converter->canonicalize($id, $metadata->getIdMapping());
        } catch (ConversionException) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $criteria
     * @return array<string, array{raw: array<string, string>, values: array<string, mixed>, canonical: array<string, ?string>}>
     */
    private function match(ClassMetadata $metadata, array $criteria): array
    {
        $rows = $this->getTable($metadata)->rows;
        if ($criteria === []) {
            return $rows;
        }

        // Normalize every criterion once: field => list of accepted canonical values.
        $accepted = [];
        foreach ($criteria as $field => $value) {
            if (!$metadata->hasField($field)) {
                throw QueryException::unknownField($metadata->className, $field);
            }
            $mapping = $metadata->getFieldMapping($field);
            $accepted[$field] = [];
            foreach (is_array($value) ? $value : [$value] as $item) {
                try {
                    $accepted[$field][] = $this->converter->canonicalize($item, $mapping);
                } catch (ConversionException $e) {
                    throw QueryException::invalidCriterion($metadata->className, $field, $e);
                }
            }
        }

        return array_filter($rows, static function (array $row) use ($accepted): bool {
            foreach ($accepted as $field => $values) {
                if (!in_array($row['canonical'][$field], $values, true)) {
                    return false;
                }
            }

            return true;
        });
    }

    /**
     * Stable sort on converted PHP values; nulls come first in ascending order.
     *
     * @param array<string, array{values: array<string, mixed>}> $rows
     * @param array<string, string> $orderBy
     * @return array<string, array{values: array<string, mixed>}>
     */
    private function sort(ClassMetadata $metadata, array $rows, array $orderBy): array
    {
        $order = [];
        foreach ($orderBy as $field => $direction) {
            if (!$metadata->hasField($field)) {
                throw QueryException::unknownField($metadata->className, $field);
            }
            $order[$field] = match (strtoupper($direction)) {
                'ASC' => 1,
                'DESC' => -1,
                default => throw QueryException::invalidDirection($field, $direction),
            };
        }

        uasort($rows, static function (array $a, array $b) use ($order): int {
            foreach ($order as $field => $sign) {
                $x = $a['values'][$field];
                $y = $b['values'][$field];
                $cmp = match (true) {
                    $x === null || $y === null => ($x !== null) <=> ($y !== null),
                    $x instanceof \BackedEnum => $x->value <=> $y->value,
                    default => $x <=> $y,
                };
                if ($cmp !== 0) {
                    return $cmp * $sign;
                }
            }

            return 0;
        });

        return $rows;
    }
}
