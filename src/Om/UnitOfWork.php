<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Om;

use Golpilolz\CsvOm\Exception\ConversionException;
use Golpilolz\CsvOm\Exception\HydrationException;
use Golpilolz\CsvOm\Exception\PersistenceException;
use Golpilolz\CsvOm\Exception\QueryException;
use Golpilolz\CsvOm\Exception\StorageException;
use Golpilolz\CsvOm\Om\Hydrator\ObjectHydrator;
use Golpilolz\CsvOm\Om\Collection\Collection;
use Golpilolz\CsvOm\Om\Mapping\JoinColumn;
use Golpilolz\CsvOm\Om\Metadata\AssociationMapping;
use Golpilolz\CsvOm\Om\Metadata\ClassMetadata;
use Golpilolz\CsvOm\Om\Metadata\MetadataFactory;
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
    private const int STATE_MANAGED = 1;
    private const int STATE_NEW = 2;
    private const int STATE_REMOVED = 3;

    /** @var array<class-string, Table> */
    private array $tables = [];

    /** @var array<class-string, ClassMetadata<object>> metadata of every class with a table or managed objects */
    private array $metadata = [];

    /** @var array<class-string, array<string, object>> class => canonical id => object */
    private array $identityMap = [];

    /** @var array<int, array{class: class-string, id: string, state: int}> spl_object_id => entity state */
    private array $states = [];

    /** @var array<string, array{header: list<string>, extra: list<string>, rows: list<array{raw: array<string, string>, owner: string, target: string}>}> "Owner::field" => loaded many-to-many join file */
    private array $joinTables = [];

    private readonly MetadataFactory $metadataFactory;

    public function __construct(
        private readonly CsvStorageInterface $storage,
        private readonly ObjectHydrator $hydrator,
        private readonly TypeConverter $converter,
        ?MetadataFactory $metadataFactory = null,
    ) {
        $this->metadataFactory = $metadataFactory ?? new MetadataFactory();
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

        // A new object owns its to-many collections: start them empty so they are never lazily loaded.
        foreach ($metadata->associationMappings as $field => $association) {
            if ($association->isToMany() && !$this->hydrator->isInitialized($metadata->className, $object, $field)) {
                $this->hydrator->setProperty($metadata->className, $object, $field, new Collection());
            }
        }
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
        $this->cascadePersist();
        $this->processRemovals();

        $plans = [];
        foreach ($this->identityMap as $className => $objects) {
            $plan = $this->computeChanges($this->metadata[$className], $objects);
            if ($plan !== null) {
                $plans[$className] = $plan;
            }
        }
        $joinPlans = $this->computeJoinChanges();

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

        foreach ($joinPlans as $key => $plan) {
            $header = $plan['header'];
            $this->storage->write(
                $plan['file'],
                $header,
                (static function () use ($plan, $header): \Generator {
                    foreach ($plan['rows'] as $row) {
                        yield array_replace(array_fill_keys($header, ''), $row['raw']);
                    }
                })(),
                $plan['delimiter'],
                $plan['enclosure'],
            );
            $this->joinTables[$key]['rows'] = $plan['rows'];
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
        $this->joinTables = [];
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

            $cells = $this->hydrator->extract($metadata, $object) + $this->associationCells($metadata, $object, $id);
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
        foreach ($metadata->columnMappings as $field => $mapping) {
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
        foreach ($metadata->columnMappings as $field => $mapping) {
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
        $oid = spl_object_id($object);
        $this->states[$oid] = ['class' => $metadata->className, 'id' => $canonicalId, 'state' => self::STATE_MANAGED];

        // The object is in the identity map before its relations are resolved, so cycles end on it.
        try {
            $this->resolveAssociations($metadata, $object, $raw, $canonicalId);
        } catch (\Throwable $e) {
            $this->detach($oid);

            throw $e;
        }

        return $object;
    }

    /**
     * Sets the relation properties of a freshly hydrated object: owning to-one and inverse to-one are
     * resolved now (through the identity map); to-many get a collection loaded on first access.
     *
     * @param ClassMetadata<object> $metadata
     * @param array<string, string> $raw
     */
    private function resolveAssociations(ClassMetadata $metadata, object $object, array $raw, string $id): void
    {
        foreach ($metadata->associationMappings as $field => $association) {
            $target = $this->metadataFactory->getMetadataFor($association->targetEntity);

            if ($association->joinMapping !== null) {
                $targetId = $this->converter->toPhp($raw[$association->joinMapping->columnName], $association->joinMapping);
                $value = null;
                if ($targetId !== null) {
                    $value = $this->find($target, $targetId)
                        ?? throw HydrationException::danglingReference($metadata->className, $id, $field, $target->className, (string) ($targetId instanceof \BackedEnum ? $targetId->value : $targetId));
                }
                $this->hydrator->setProperty($metadata->className, $object, $field, $value);
            } elseif ($association->isToOne()) {
                $value = $this->findBy($target, [(string) $association->mappedBy => $object], [], 1)[0] ?? null;
                if ($value !== null || $association->nullable) {
                    $this->hydrator->setProperty($metadata->className, $object, $field, $value);
                }
            } else {
                $this->hydrator->setProperty(
                    $metadata->className,
                    $object,
                    $field,
                    Collection::lazy(fn (): array => $this->loadCollection($metadata, $association, $object, $id)),
                );
            }
        }
    }

    /**
     * @param ClassMetadata<object> $metadata
     * @return list<object>
     */
    private function loadCollection(ClassMetadata $metadata, AssociationMapping $association, object $owner, string $id): array
    {
        $target = $this->metadataFactory->getMetadataFor($association->targetEntity);
        if ($association->type === AssociationMapping::ONE_TO_MANY) {
            return $this->findBy($target, [(string) $association->mappedBy => $owner]);
        }

        // Many-to-many: the join file belongs to the owning side.
        if ($association->isOwning()) {
            $owning = $metadata;
            $owningAssociation = $association;
        } else {
            $owning = $target;
            $owningAssociation = $target->getAssociationMapping((string) $association->mappedBy);
        }

        $objects = [];
        foreach ($this->joinTable($owning, $owningAssociation)['rows'] as $row) {
            [$match, $otherId] = $association->isOwning() ? [$row['owner'], $row['target']] : [$row['target'], $row['owner']];
            if ($match !== $id) {
                continue;
            }
            $objects[] = $this->find($target, $otherId)
                ?? throw HydrationException::danglingReference($owning->className, $row['owner'], $owningAssociation->fieldName, $owningAssociation->targetEntity, $row['target']);
        }

        return $objects;
    }

    /**
     * Loaded many-to-many join file of an owning association.
     *
     * @param ClassMetadata<object> $owner
     * @return array{header: list<string>, extra: list<string>, rows: list<array{raw: array<string, string>, owner: string, target: string}>}
     */
    private function joinTable(ClassMetadata $owner, AssociationMapping $association): array
    {
        $key = $owner->className . '::' . $association->fieldName;
        if (isset($this->joinTables[$key])) {
            return $this->joinTables[$key];
        }

        $file = (string) $association->joinFile;
        $ownerColumn = (string) $association->joinOwnerColumn;
        $targetColumn = (string) $association->joinTargetColumn;
        if (!$this->storage->exists($file)) {
            return $this->joinTables[$key] = ['header' => [$ownerColumn, $targetColumn], 'extra' => [], 'rows' => []];
        }

        $target = $this->metadataFactory->getMetadataFor($association->targetEntity);
        $csv = $this->storage->read($file, $owner->delimiter, $owner->enclosure);
        $missing = array_diff([$ownerColumn, $targetColumn], $csv->header);
        if ($missing !== []) {
            throw new StorageException(sprintf('Join file "%s" of "%s::$%s" is missing columns: %s.', $file, $owner->className, $association->fieldName, implode(', ', $missing)));
        }

        $rows = [];
        foreach ($csv->rows as $index => $raw) {
            try {
                $ownerId = $this->converter->canonicalize($raw[$ownerColumn], $owner->getIdMapping());
                $targetId = $this->converter->canonicalize($raw[$targetColumn], $target->getIdMapping());
            } catch (ConversionException $e) {
                throw new StorageException(sprintf('Join file "%s" (data row %d): %s', $file, $index + 1, $e->getMessage()), 0, $e);
            }
            if ($ownerId === null || $targetId === null) {
                throw new StorageException(sprintf('Join file "%s" (data row %d) has an empty id.', $file, $index + 1));
            }
            $rows[] = ['raw' => $raw, 'owner' => $ownerId, 'target' => $targetId];
        }

        return $this->joinTables[$key] = [
            'header' => $csv->header,
            'extra' => array_values(array_diff($csv->header, [$ownerColumn, $targetColumn])),
            'rows' => $rows,
        ];
    }

    /**
     * Persists, recursively, the objects reachable through associations declared with cascade: ['persist'].
     */
    private function cascadePersist(): void
    {
        do {
            $added = false;
            foreach ($this->states as $state) {
                if ($state['state'] === self::STATE_REMOVED) {
                    continue;
                }
                $metadata = $this->metadata[$state['class']];
                $object = $this->identityMap[$state['class']][$state['id']];
                foreach ($metadata->associationMappings as $field => $association) {
                    if (!$association->cascadePersist) {
                        continue;
                    }
                    $value = $this->hydrator->getProperty($metadata->className, $object, $field);
                    $targets = match (true) {
                        $value instanceof Collection => $value->isInitialized() ? $value->toArray() : [],
                        $value === null => [],
                        default => [$value],
                    };
                    foreach ($targets as $target) {
                        if (!$target instanceof $association->targetEntity || isset($this->states[spl_object_id($target)])) {
                            continue;
                        }
                        $this->persist($this->metadataFactory->getMetadataFor($association->targetEntity), $target);
                        $added = true;
                    }
                }
            }
        } while ($added);
    }

    /**
     * Applies the onDelete rule of every to-one association that points to a removed object: refuse
     * (RESTRICT), set the reference to null (SET NULL) or remove the referencing object too (CASCADE).
     */
    private function processRemovals(): void
    {
        $queue = [];
        foreach ($this->states as $oid => $state) {
            if ($state['state'] === self::STATE_REMOVED) {
                $queue[] = $oid;
            }
        }

        $done = [];
        while ($queue !== []) {
            $oid = array_pop($queue);
            if (isset($done[$oid]) || !isset($this->states[$oid])) {
                continue;
            }
            $done[$oid] = true;
            $state = $this->states[$oid];
            $removed = $this->identityMap[$state['class']][$state['id']];

            foreach ($this->referencesTo($state['class']) as [$referrer, $association]) {
                foreach ($this->referrers($referrer, $association, $removed) as $object) {
                    $referrerOid = spl_object_id($object);
                    $referrerState = $this->states[$referrerOid];
                    if ($referrerState['state'] === self::STATE_REMOVED) {
                        continue;
                    }
                    switch ($association->onDelete) {
                        case JoinColumn::SET_NULL:
                            $this->hydrator->setProperty($referrer->className, $object, $association->fieldName, null);
                            break;
                        case JoinColumn::CASCADE:
                            if ($referrerState['state'] === self::STATE_NEW) {
                                $this->detach($referrerOid);
                            } else {
                                $this->states[$referrerOid]['state'] = self::STATE_REMOVED;
                                $queue[] = $referrerOid;
                            }
                            break;
                        default:
                            throw PersistenceException::stillReferenced($state['class'], $state['id'], $referrer->className, $referrerState['id'], $association->fieldName);
                    }
                }
            }
        }
    }

    /**
     * Owning to-one associations that point to the given class: those declared as the other side of an
     * inverse association of the class, and those of every entity already in use.
     *
     * @param class-string $className
     * @return list<array{0: ClassMetadata<object>, 1: AssociationMapping}>
     */
    private function referencesTo(string $className): array
    {
        $references = [];
        $metadata = $this->metadata[$className];
        foreach ($metadata->associationMappings as $association) {
            if ($association->isToOne() && !$association->isOwning()) {
                $referrer = $this->metadataFactory->getMetadataFor($association->targetEntity);
                $references[$referrer->className . '::' . $association->mappedBy] = [$referrer, $referrer->getAssociationMapping((string) $association->mappedBy)];
            } elseif ($association->type === AssociationMapping::ONE_TO_MANY) {
                $referrer = $this->metadataFactory->getMetadataFor($association->targetEntity);
                $references[$referrer->className . '::' . $association->mappedBy] = [$referrer, $referrer->getAssociationMapping((string) $association->mappedBy)];
            }
        }
        foreach ($this->metadata as $referrer) {
            foreach ($referrer->associationMappings as $association) {
                if ($association->joinMapping !== null && $association->targetEntity === $className) {
                    $references[$referrer->className . '::' . $association->fieldName] = [$referrer, $association];
                }
            }
        }

        return array_values($references);
    }

    /**
     * Objects whose association currently points to $target: rows of the file, and objects in memory.
     *
     * @param ClassMetadata<object> $referrer
     * @return list<object>
     */
    private function referrers(ClassMetadata $referrer, AssociationMapping $association, object $target): array
    {
        $candidates = $this->findBy($referrer, [$association->fieldName => $target]);
        foreach ($this->identityMap[$referrer->className] ?? [] as $object) {
            $candidates[] = $object;
        }

        $found = [];
        foreach ($candidates as $object) {
            if ($this->hydrator->getProperty($referrer->className, $object, $association->fieldName) === $target) {
                $found[spl_object_id($object)] = $object;
            }
        }

        return array_values($found);
    }

    /**
     * Join column cells of the owning to-one associations, from the object's current references.
     *
     * @param ClassMetadata<object> $metadata
     * @return array<string, string|null> column => target id
     */
    private function associationCells(ClassMetadata $metadata, object $object, string $id): array
    {
        $cells = [];
        foreach ($metadata->associationMappings as $field => $association) {
            if ($association->joinMapping === null) {
                continue;
            }
            $column = $association->joinMapping->columnName;
            $value = $this->hydrator->getProperty($metadata->className, $object, $field);
            if ($value === null) {
                if (!$association->nullable) {
                    throw PersistenceException::nullAssociation($metadata->className, $id, $field);
                }
                $cells[$column] = null;
                continue;
            }
            if (!$value instanceof $association->targetEntity) {
                throw PersistenceException::wrongTargetClass($metadata->className, $field, $association->targetEntity, $value);
            }
            $state = $this->states[spl_object_id($value)] ?? null;
            if ($state === null || $state['class'] !== $association->targetEntity || $state['state'] === self::STATE_REMOVED) {
                throw PersistenceException::unmanagedTarget($metadata->className, $id, $field, $association->targetEntity);
            }
            $cells[$column] = $state['id'];
        }

        return $cells;
    }

    /**
     * Compares each owning many-to-many collection that was loaded or modified to its join file, and
     * drops the rows of removed objects. Inverse sides are never written.
     *
     * @return array<string, array{file: string, header: list<string>, rows: list<array{raw: array<string, string>, owner: string, target: string}>, delimiter: ?string, enclosure: ?string}>
     */
    private function computeJoinChanges(): array
    {
        $removed = [];
        foreach ($this->states as $state) {
            if ($state['state'] === self::STATE_REMOVED) {
                $removed[$state['class']][$state['id']] = true;
            }
        }

        $candidates = [];
        foreach ($this->metadata as $metadata) {
            foreach ($metadata->associationMappings as $association) {
                if ($association->type === AssociationMapping::MANY_TO_MANY && $association->isOwning()) {
                    $candidates[$metadata->className . '::' . $association->fieldName] = [$metadata, $association];
                }
            }
        }
        foreach (array_keys($removed) as $className) {
            foreach ($this->metadata[$className]->associationMappings as $association) {
                if ($association->type === AssociationMapping::MANY_TO_MANY && !$association->isOwning()) {
                    $owner = $this->metadataFactory->getMetadataFor($association->targetEntity);
                    $candidates[$owner->className . '::' . $association->mappedBy] = [$owner, $owner->getAssociationMapping((string) $association->mappedBy)];
                }
            }
        }

        $plans = [];
        foreach ($candidates as $key => [$owner, $association]) {
            $target = $this->metadataFactory->getMetadataFor($association->targetEntity);
            $removedOwners = $removed[$owner->className] ?? [];
            $removedTargets = $removed[$target->className] ?? [];

            /** @var array<string, list<string>> $desired owner id => target ids, for collections that were loaded or modified */
            $desired = [];
            foreach ($this->identityMap[$owner->className] ?? [] as $ownerId => $object) {
                $ownerId = (string) $ownerId;
                if ($this->states[spl_object_id($object)]['state'] === self::STATE_REMOVED) {
                    continue;
                }
                $collection = $this->hydrator->getProperty($owner->className, $object, $association->fieldName);
                if (!$collection instanceof Collection || !$collection->isInitialized()) {
                    continue;
                }
                $ids = [];
                foreach ($collection as $item) {
                    if (!$item instanceof $association->targetEntity) {
                        throw PersistenceException::wrongTargetClass($owner->className, $association->fieldName, $association->targetEntity, is_object($item) ? $item : new \stdClass());
                    }
                    $state = $this->states[spl_object_id($item)] ?? null;
                    if ($state === null || $state['class'] !== $target->className) {
                        throw PersistenceException::unmanagedTarget($owner->className, $ownerId, $association->fieldName, $target->className);
                    }
                    if ($state['state'] !== self::STATE_REMOVED) {
                        $ids[$state['id']] = $state['id'];
                    }
                }
                $desired[$ownerId] = array_values($ids);
            }
            if ($desired === [] && $removedOwners === [] && $removedTargets === []) {
                continue;
            }

            $table = $this->joinTable($owner, $association);
            $kept = [];
            $present = [];
            $changed = false;
            foreach ($table['rows'] as $row) {
                if (isset($removedOwners[$row['owner']]) || isset($removedTargets[$row['target']])
                    || (isset($desired[$row['owner']]) && !in_array($row['target'], $desired[$row['owner']], true))) {
                    $changed = true;
                    continue;
                }
                $present[$row['owner']][$row['target']] = true;
                $kept[] = $row;
            }
            foreach ($desired as $ownerId => $targetIds) {
                foreach ($targetIds as $targetId) {
                    if (isset($present[$ownerId][$targetId])) {
                        continue;
                    }
                    $kept[] = [
                        'raw' => array_fill_keys($table['extra'], '') + [(string) $association->joinOwnerColumn => (string) $ownerId, (string) $association->joinTargetColumn => $targetId],
                        'owner' => (string) $ownerId,
                        'target' => $targetId,
                    ];
                    $changed = true;
                }
            }

            if ($changed) {
                $plans[$key] = [
                    'file' => (string) $association->joinFile,
                    'header' => $table['header'],
                    'rows' => $kept,
                    'delimiter' => $owner->delimiter,
                    'enclosure' => $owner->enclosure,
                ];
            }
        }

        return $plans;
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
            if (!$metadata->hasColumnMapping($field)) {
                throw QueryException::unknownField($metadata->className, $field);
            }
            $mapping = $metadata->columnMappings[$field];
            $association = $metadata->associationMappings[$field] ?? null;
            $accepted[$field] = [];
            foreach (is_array($value) ? $value : [$value] as $item) {
                if ($association !== null && is_object($item) && !$item instanceof \BackedEnum) {
                    $item = $this->associationCriterion($metadata, $association, $item);
                }
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
     * Id of an object given as criterion for an association.
     *
     * @param ClassMetadata<object> $metadata
     */
    private function associationCriterion(ClassMetadata $metadata, AssociationMapping $association, object $item): string
    {
        if (!$item instanceof $association->targetEntity) {
            throw QueryException::invalidAssociationValue($metadata->className, $association->fieldName, sprintf('expected "%s", "%s" given', $association->targetEntity, $item::class));
        }

        return $this->hydrator->extractId($this->metadataFactory->getMetadataFor($association->targetEntity), $item)
            ?? throw QueryException::invalidAssociationValue($metadata->className, $association->fieldName, 'the object has no identifier yet');
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
            if (!$metadata->hasColumnMapping($field)) {
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
