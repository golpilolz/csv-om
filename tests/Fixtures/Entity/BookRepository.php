<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Tests\Fixtures\Entity;

use Golpilolz\CsvOm\Om\Repository\RepositoryInterface;

final class BookRepository implements RepositoryInterface
{
    public ?string $prefix = null;
    public ?string $className = null;

    public function find($identifier): ?object
    {
        return null;
    }
}
