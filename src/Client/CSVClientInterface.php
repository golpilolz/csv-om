<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Client;

interface CSVClientInterface
{
    /**
     * Search objects by given prefix key and criterias.
     */
    /**
     * @param string $prefixKey
     * @param array $search
     * @param array $orderBy
     * @return array
     */
    public function search(string $prefixKey, array $search, array $orderBy): array;

}