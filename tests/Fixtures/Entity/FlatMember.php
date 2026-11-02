<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Tests\Fixtures\Entity;

use Golpilolz\CsvOm\Om\Mapping as CsvOm;

/** Only the .csv files directly in flat/; no default file for new objects. */
#[CsvOm\Entity(directory: 'flat/', recursive: false)]
class FlatMember
{
    #[CsvOm\Id]
    public int $id;

    #[CsvOm\Column]
    public string $name = '';

    #[CsvOm\SourceFile]
    public ?string $source = null;
}
