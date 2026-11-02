<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Tests\Fixtures\Entity;

use Golpilolz\CsvOm\Om\Mapping as CsvOm;

#[CsvOm\Entity(repositoryClass: PublisherRepository::class)]
final class Publisher
{
    #[CsvOm\Id]
    public int $id;
}
