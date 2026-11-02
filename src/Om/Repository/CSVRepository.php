<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Om\Repository;

use Golpilolz\CsvOm\Client\CSVClientInterface;
use Golpilolz\CsvOm\Om\QueryBuilder;

final class CSVRepository implements RepositoryInterface
{
    public ?string $prefix = null;
    public ?string $className = null;
    protected ?CSVClientInterface $csvClient = null;

    /**
     * @inheritdoc
     */
    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null, ?int $offset = 0, array $additionalRangeFilters = []): array
    {
        $data = $this->csvClient->search(
            $this->prefix,
            $criteria,
            []
        );

        return $data;
    }

    public function find($identifier): ?object
    {
        $object = $this->csvClient->search(
            $this->prefix,
            ['id' => $identifier],
            []
        );

        return $object;
    }

    /**
     * @inheritdoc
     */
    public function createQueryBuilder(): QueryBuilder
    {
        return new QueryBuilder(
            csvClient: $this->csvClient,
        );
    }

}
