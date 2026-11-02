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
