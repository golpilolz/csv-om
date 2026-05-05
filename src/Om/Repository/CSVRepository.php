<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Om\Repository;

use Golpilolz\CsvOm\Client\CSVClientInterface;

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

//        $collection = [];
//        foreach ($data as $item) {
//            $collection[] = $this->converter->revert($item, $this->className);
//        }

        return $data;
    }

    public function find($identifier): ?object
    {
        return null;
    }
}
