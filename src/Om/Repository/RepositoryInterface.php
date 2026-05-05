<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Om\Repository;

/**
 * @template T of object
 */
interface RepositoryInterface {
    /**
     * Find an object by its identifier.
     * @return T|null
     */
    public function find($identifier): ?object;

}