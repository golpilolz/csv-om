<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Tests\Fixtures\Entity;

use Golpilolz\CsvOm\Om\Mapping as CsvOm;

#[CsvOm\Entity(file: 'tickets.csv')]
class Ticket
{
    #[CsvOm\Id]
    #[CsvOm\GeneratedValue]
    public int $id;

    #[CsvOm\Column]
    public string $title = '';
}
