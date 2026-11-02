<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Om\Repository;

use Golpilolz\CsvOm\Om\ObjectManagerInterface;

/**
 * Default repository. Extend it to add custom finders. To make it an autowirable service (like Doctrine's
 * ServiceEntityRepository), give it a constructor that passes the entity class:
 *
 *     #[CsvOm\Entity(repositoryClass: BookRepository::class)]
 *     class Book {}
 *
 *     class BookRepository extends CSVRepository
 *     {
 *         public function __construct(ObjectManagerInterface $objectManager)
 *         {
 *             parent::__construct($objectManager, Book::class);
 *         }
 *
 *         public function findPublished(): array { return $this->findBy(['status' => BookStatus::Published]); }
 *     }
 *
 * Repositories registered as services are autoconfigured and returned by ObjectManager::getRepository().
 *
 * Magic finders on any mapped property (Doctrine-style): `findByTitle('Dune')`, `findOneById(7)`,
 * `findByPublishedAt($date)`. They take the value as first argument, then (findBy only) `$orderBy`, `$limit`,
 * `$offset`; findOneBy takes `$orderBy`. The property name is the method suffix with a lowercased first letter.
 *
 * Queries run on the data as loaded from the file (like Doctrine queries the database): unflushed
 * changes to objects are not taken into account for filtering and sorting.
 *
 * @template T of object
 * @implements RepositoryInterface<T>
 */
class CSVRepository implements RepositoryInterface
{
    /**
     * @param class-string<T> $className
     */
    public function __construct(
        protected readonly ObjectManagerInterface $objectManager,
        protected readonly string $className,
    ) {
    }

    public function find(mixed $id): ?object
    {
        return $this->objectManager->find($this->className, $id);
    }

    public function findAll(): array
    {
        return $this->findBy([]);
    }

    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null, ?int $offset = null): array
    {
        return $this->objectManager->getUnitOfWork()->findBy(
            $this->objectManager->getClassMetadata($this->className),
            $criteria,
            $orderBy ?? [],
            $limit,
            $offset ?? 0,
        );
    }

    public function findOneBy(array $criteria, ?array $orderBy = null): ?object
    {
        return $this->findBy($criteria, $orderBy, 1)[0] ?? null;
    }

    /**
     * @param list<mixed> $arguments
     * @return list<T>|T|null
     */
    public function __call(string $method, array $arguments): array|object|null
    {
        if (str_starts_with($method, 'findOneBy') && strlen($method) > 9) {
            $field = lcfirst(substr($method, 9));
            $one = true;
        } elseif (str_starts_with($method, 'findBy') && strlen($method) > 6) {
            $field = lcfirst(substr($method, 6));
            $one = false;
        } else {
            throw new \BadMethodCallException(sprintf('Undefined method "%s::%s()".', static::class, $method));
        }

        if ([] === $arguments) {
            throw new \ArgumentCountError(sprintf('%s::%s() expects a value to match, none given.', static::class, $method));
        }

        $criteria = [$field => $arguments[0]];

        return $one
            ? $this->findOneBy($criteria, $arguments[1] ?? null)
            : $this->findBy($criteria, $arguments[1] ?? null, $arguments[2] ?? null, $arguments[3] ?? null);
    }

    public function count(array $criteria = []): int
    {
        return $this->objectManager->getUnitOfWork()->count(
            $this->objectManager->getClassMetadata($this->className),
            $criteria,
        );
    }

    public function getClassName(): string
    {
        return $this->className;
    }
}
