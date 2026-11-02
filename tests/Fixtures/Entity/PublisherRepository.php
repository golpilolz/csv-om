<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Tests\Fixtures\Entity;

use Golpilolz\CsvOm\Om\ObjectManagerInterface;
use Golpilolz\CsvOm\Om\Repository\CSVRepository;

/**
 * Misconfigured on purpose: declares the wrong entity class.
 */
final class PublisherRepository extends CSVRepository
{
    public function __construct(ObjectManagerInterface $objectManager)
    {
        parent::__construct($objectManager, Book::class);
    }
}
