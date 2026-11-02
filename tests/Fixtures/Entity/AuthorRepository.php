<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Tests\Fixtures\Entity;

use Golpilolz\CsvOm\Om\ObjectManagerInterface;
use Golpilolz\CsvOm\Om\Repository\CSVRepository;

/**
 * Service repository, Doctrine style: the constructor passes the entity class.
 *
 * @extends CSVRepository<Author>
 */
final class AuthorRepository extends CSVRepository
{
    public function __construct(ObjectManagerInterface $objectManager)
    {
        parent::__construct($objectManager, Author::class);
    }
}
