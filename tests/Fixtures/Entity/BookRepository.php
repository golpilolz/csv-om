<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Tests\Fixtures\Entity;

use Golpilolz\CsvOm\Om\Repository\CSVRepository;

/**
 * @extends CSVRepository<Book>
 */
final class BookRepository extends CSVRepository
{
    /** @return list<Book> */
    public function findPublished(): array
    {
        return $this->findBy(['status' => BookStatus::Published], ['id' => 'ASC']);
    }
}
