<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Om;

use Golpilolz\CsvOm\Client\CSVClientInterface;

class QueryBuilder
{
    private ?string $query = null;

    public function __construct(
        private CSVClientInterface $csvClient,
    ) {
    }

    public function query(string $query): void
    {
        $this->query = $query;
    }

    public function execute(): array
    {
        $data = $this->csvClient->customSearch("", $this->query, "");

        return $data;
    }
}