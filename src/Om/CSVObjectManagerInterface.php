<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Om;

use Golpilolz\CsvOm\Om\Metadata\ClassMetadata;
use Golpilolz\CsvOm\Om\Repository\RepositoryInterface;

interface CSVObjectManagerInterface
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

    public function getUnitOfWork(): UnitOfWork;
}
