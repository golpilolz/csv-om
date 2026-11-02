<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Om;

use Golpilolz\CsvOm\Exception\ConversionException;
use Golpilolz\CsvOm\Exception\PersistenceException;
use Golpilolz\CsvOm\Exception\QueryException;
use Golpilolz\CsvOm\Exception\StorageException;
use Golpilolz\CsvOm\Om\Hydrator\ObjectHydrator;
use Golpilolz\CsvOm\Om\Metadata\ClassMetadata;
use Golpilolz\CsvOm\Om\Type\TypeConverter;
use Golpilolz\CsvOm\Storage\CsvStorageInterface;

/**
 * Loads entity files on demand, keeps the identity map and writes changes back on flush().
 *
 * A file is read once, when its entity is first used, and every row is converted at that moment, so
 * invalid data is reported immediately. The loaded table doubles as the change-tracking snapshot: on
 * flush, each managed object is compared to its row, and only changed rows get their mapped cells
 * replaced. Unmapped columns and unchanged rows are written back exactly as they were read.
 */
final class UnitOfWork
{
    private const STATE_MANAGED = 1;
    private const STATE_NEW = 2;
    private const STATE_REMOVED = 3;

    /** @var array<class-string, Table> */
    private array $tables = [];

    /** @var array<class-string, ClassMetadata<object>> metadata of every class with a table or managed objects */
    private array $metadata = [];

    /** @var array<class-string, array<string, object>> class => canonical id => object */
    private array $identityMap = [];

    /** @var array<int, array{class: class-string, id: string, state: int}> spl_object_id => entity state */
    private array $states = [];

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
     * Schedules a new object for insertion. Persisting an already managed object is a no-op;
     * persisting an object scheduled for removal cancels the removal.
     *
     * @param ClassMetadata<object> $metadata
     */
    public function persist(ClassMetadata $metadata, object $object): void
    {
        $oid = spl_object_id($object);
        if (isset($this->states[$oid])) {
            if ($this->states[$oid]['state'] === self::STATE_REMOVED) {
                $this->states[$oid]['state'] = self::STATE_MANAGED;
            }

            return;
        }

        $id = $this->hydrator->extractId($metadata, $object);
        if ($id === null) {
            throw PersistenceException::missingId($metadata->className);
        }
        if (isset($this->identityMap[$metadata->className][$id]) || isset($this->getTable($metadata)->rows[$id])) {
            throw PersistenceException::duplicateId($metadata->className, $id);
        }

        $this->identityMap[$metadata->className][$id] = $object;
        $this->states[$oid] = ['class' => $metadata->className, 'id' => $id, 'state' => self::STATE_NEW];
    }

    /**
     * Schedules a managed object for removal. Removing a new, never flushed object just cancels its insertion.
     *
     * @param ClassMetadata<object> $metadata
     */
    public function remove(ClassMetadata $metadata, object $object): void
    {
        $oid = spl_object_id($object);
        $state = $this->states[$oid] ?? null;
        if ($state === null || $state['class'] !== $metadata->className) {
            throw PersistenceException::notManaged($metadata->className, 'remove');
        }

        if ($state['state'] === self::STATE_NEW) {
            $this->detach($oid);

            return;
        }

        $this->states[$oid]['state'] = self::STATE_REMOVED;
    }

    /**
     * True for objects that are managed and not scheduled for removal.
     */
    public function contains(object $object): bool
    {
        $state = $this->states[spl_object_id($object)] ?? null;

        return $state !== null && $state['state'] !== self::STATE_REMOVED;
    }

    /**
     * Writes every pending insertion, update and removal.
     *
     * All changes are computed and validated before any file is written, so a conversion error writes
     * nothing. Files are then written one by one: if writing one fails, the files written before it keep
     * their changes and the remaining changes stay pending.
     */
    public function flush(): void
    {
        $plans = [];
        foreach ($this->identityMap as $className => $objects) {
            $plan = $this->computeChanges($this->metadata[$className], $objects);
            if ($plan !== null) {
                $plans[$className] = $plan;
            }
        }

        foreach ($plans as $className => $plan) {
            $metadata = $this->metadata[$className];
            $table = $this->getTable($metadata);

            $this->storage->write(
                $metadata->file,
                $table->header,
                (static function () use ($plan, $table): \Generator {
                    foreach ($plan['rows'] as $row) {
                        // Cells in header order; the storage writes them by column name.
                        yield array_replace(array_fill_keys($table->header, ''), $row['raw']);
                    }
                })(),
                $metadata->delimiter,
                $metadata->enclosure,
            );

            $table->rows = $plan['rows'];
            foreach ($plan['inserted'] as $oid) {
                $this->states[$oid]['state'] = self::STATE_MANAGED;
            }
            foreach ($plan['removed'] as $oid) {
                $this->detach($oid);
            }
        }
    }

    /**
     * Detaches every object and forgets loaded tables: the next read sees the files again.
     */
    public function clear(): void
    {
        $this->tables = [];
        $this->metadata = [];
        $this->identityMap = [];
        $this->states = [];
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
        $this->metadata[$metadata->className] ??= $metadata;

        return $this->tables[$metadata->className] ??= $this->load($metadata);
    }

    /**
     * @param ClassMetadata<object> $metadata
     * @param array<string, object> $objects canonical id => object
     * @return array{rows: array<string, array{raw: array<string, string>, values: array<string, mixed>, canonical: array<string, ?string>}>, inserted: list<int>, removed: list<int>}|null
     */
    private function computeChanges(ClassMetadata $metadata, array $objects): ?array
    {
        $table = $this->getTable($metadata);
        $rows = $table->rows;
        $inserted = [];
        $removed = [];
        $changed = false;

        foreach ($objects as $id => $object) {
            $id = (string) $id;
            $oid = spl_object_id($object);
            $state = $this->states[$oid]['state'];

            if ($state === self::STATE_REMOVED) {
                unset($rows[$id]);
                $removed[] = $oid;
                $changed = true;
                continue;
            }

            $cells = $this->hydrator->extract($metadata, $object);
            $newId = $cells[$metadata->getIdColumn()];
            if ($newId !== $id) {
                throw PersistenceException::identifierChanged($metadata->className, $id, $newId);
            }

            if ($state === self::STATE_NEW) {
                $raw = array_fill_keys($table->extraColumns, '');
                $inserted[] = $oid;
            } else {
                if ($this->sameValues($metadata, $cells, $rows[$id]['canonical'])) {
                    continue;
                }
                // Keep unmapped cells as read; replace mapped ones.
                $raw = $rows[$id]['raw'];
            }

            foreach ($cells as $column => $value) {
                $raw[$column] = $value ?? '';
            }
            $rows[$id] = $this->buildRow($metadata, $raw);
            $changed = true;
        }

        return $changed ? ['rows' => $rows, 'inserted' => $inserted, 'removed' => $removed] : null;
    }

    /**
     * @param array<string, ?string> $cells column => canonical value, from the object
     * @param array<string, ?string> $canonical field => canonical value, from the table
     */
    private function sameValues(ClassMetadata $metadata, array $cells, array $canonical): bool
    {
        foreach ($metadata->fieldMappings as $field => $mapping) {
            if ($cells[$mapping->columnName] !== $canonical[$field]) {
                return false;
            }
        }

        return true;
    }

    private function detach(int $oid): void
    {
        $state = $this->states[$oid];
        unset($this->identityMap[$state['class']][$state['id']], $this->states[$oid]);
    }

    private function load(ClassMetadata $metadata): Table
    {
        if (!$this->storage->exists($metadata->file)) {
            return new Table($metadata->getColumnNames(), [], []);
        }

        $csv = $this->storage->read($metadata->file, $metadata->delimiter, $metadata->enclosure);

        $missing = array_diff($metadata->getColumnNames(), $csv->header);
        if ($missing !== []) {
            throw new StorageException(sprintf(
                'CSV file "%s" of entity "%s" is missing mapped columns: %s.',
                $metadata->file,
                $metadata->className,
                implode(', ', $missing),
            ));
        }

        $rows = [];
        foreach ($csv->rows as $index => $raw) {
            $row = $this->buildRow($metadata, $raw);
            $id = (string) $row['canonical'][$metadata->idField];
            if (isset($rows[$id])) {
                throw new StorageException(sprintf(
                    'CSV file "%s" of entity "%s" has duplicate id "%s" (data row %d).',
                    $metadata->file,
                    $metadata->className,
                    $id,
                    $index + 1,
                ));
            }
            $rows[$id] = $row;
        }

        return new Table(
            $csv->header,
            array_values(array_diff($csv->header, $metadata->getColumnNames())),
            $rows,
        );
    }

    /**
     * Converts a raw row once, keeping the raw cells, the PHP values (for sorting) and the canonical
     * strings (for matching and change detection).
     *
     * @param array<string, string> $raw
     * @return array{raw: array<string, string>, values: array<string, mixed>, canonical: array<string, ?string>}
     */
    private function buildRow(ClassMetadata $metadata, array $raw): array
    {
        $values = [];
        $canonical = [];
        foreach ($metadata->fieldMappings as $field => $mapping) {
            try {
                $value = $this->converter->toPhp($raw[$mapping->columnName], $mapping);
                $canonical[$field] = $this->converter->toCsv($value, $mapping);
            } catch (ConversionException $e) {
                throw ConversionException::forEntity($metadata->className, $raw[$metadata->getIdColumn()], $e);
            }
            $values[$field] = $value;
        }

        return ['raw' => $raw, 'values' => $values, 'canonical' => $canonical];
    }

    /**
     * @param array<string, string> $raw
     */
    private function materialize(ClassMetadata $metadata, string $canonicalId, array $raw): object
    {
        $object = $this->hydrator->hydrate($metadata, $raw);
        $this->identityMap[$metadata->className][$canonicalId] = $object;
        $this->states[spl_object_id($object)] = ['class' => $metadata->className, 'id' => $canonicalId, 'state' => self::STATE_MANAGED];

        return $object;
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
