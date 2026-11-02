<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Client;

interface CSVClientInterface
{
    /**
     * Search rows by given prefix key and criteria.
     */
    public function search(string $prefixKey, array $search, array $orderBy): array;

    /**
     * Run a raw query against the given prefix key.
     */
    public function customSearch(string $prefixKey, string $query, string $format): array;

}