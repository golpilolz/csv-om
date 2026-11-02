<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Tests\Fixtures\Entity;

use Golpilolz\CsvOm\Om\Mapping as CsvOm;

/** Rows spread over every .csv file of members/ and its subdirectories. */
#[CsvOm\Entity(directory: 'members', newFile: 'inbox.csv')]
class Member
{
    #[CsvOm\Id]
    public int $id;

    #[CsvOm\Column]
    public string $name = '';

    #[CsvOm\SourceFile]
    public ?string $source = null;
}
