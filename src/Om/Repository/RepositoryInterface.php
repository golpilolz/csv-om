<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Om\Repository;

use Golpilolz\CsvOm\Om\Query\Criteria;

/**
 * @template T of object
 */
interface RepositoryInterface
{
    /**
     * Find an object by its identifier.
     * @return T|null
     */
    public function find(mixed $id): ?object;

    /**
     * @return list<T>
     */
    public function findAll(): array;

    /**
     * @param array<string, mixed> $criteria field name => value; a list value means "one of"; null matches empty cells
     * @param array<string, string>|null $orderBy field name => 'ASC' | 'DESC'
     * @return list<T>
     */
    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null, ?int $offset = null): array;

    /**
     * @param array<string, mixed> $criteria
     * @param array<string, string>|null $orderBy
     * @return T|null
     */
    public function findOneBy(array $criteria, ?array $orderBy = null): ?object;

    /**
     * Objects matching a {@see Criteria}: filter expression, order and window.
     *
     * @return list<T>
     */
    public function matching(Criteria $criteria): array;

    /**
     * @param array<string, mixed> $criteria
     */
    public function count(array $criteria = []): int;

    /**
     * @return class-string<T>
     */
    public function getClassName(): string;
}
