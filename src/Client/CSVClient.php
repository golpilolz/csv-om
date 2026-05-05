<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Client;

final class CSVClient implements CSVClientInterface
{
    private string $basePath = "";

    public function __construct()
    {
        if (array_key_exists('DATABASE_CSV_DIR', $_SERVER)) {
            $this->basePath = $_SERVER['DATABASE_CSV_DIR'];
        }
    }

    public function search(string $prefixKey, array $search, array $orderBy): array
    {


        return [];
    }
}