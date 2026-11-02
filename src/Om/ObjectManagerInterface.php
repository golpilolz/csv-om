<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Om;

use Golpilolz\CsvOm\Om\Metadata\ClassMetadata;
use Golpilolz\CsvOm\Om\Repository\RepositoryInterface;

interface ObjectManagerInterface
{
    /**
     * Get object by class name (FQCN) and id. Returns the same instance for the same id.
     * @template T of object
     * @param class-string<T> $className
     * @return T|null
     */
    public function find(string $className, mixed $id): ?object;

    /**
     * @template T of object
     * @param class-string<T> $className
     * @return RepositoryInterface<T>
     */
    public function getRepository(string $className): RepositoryInterface;

    /**
     * @template T of object
     * @param class-string<T> $className
     * @return ClassMetadata<T>
     */
    public function getClassMetadata(string $className): ClassMetadata;

    /**
     * Schedules a new object for insertion on the next flush(). Its identifier must be set.
     */
    public function persist(object $object): void;

    /**
     * Schedules a managed object for removal on the next flush().
     */
    public function remove(object $object): void;

    /**
     * Writes all pending insertions, updates and removals to the CSV files.
     */
    public function flush(): void;

    /**
     * Detaches all objects and forgets loaded files; pending changes are discarded.
     */
    public function clear(): void;

    /**
     * Whether the object is managed (loaded or persisted, and not scheduled for removal).
     */
    public function contains(object $object): bool;

    public function getUnitOfWork(): UnitOfWork;
}
