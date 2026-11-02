<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Om;

interface CSVObjectManagerInterface
{
    /**
     * Get object by class name (FQCN) and id.
     * @template T of object
     * @param class-string<T> $className
     * @return T|null
     */
    public function find(string $className, $id): ?object;
}