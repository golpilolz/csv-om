<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Tests\Fixtures\Relations;

use Golpilolz\CsvOm\Om\Collection\Collection;
use Golpilolz\CsvOm\Om\Mapping as CsvOm;

#[CsvOm\Entity(file: 'tags.csv')]
class Tag
{
    #[CsvOm\Id]
    public string $code;

    /** @var Collection<Team> Inverse side: never written. */
    #[CsvOm\ManyToMany(Team::class, mappedBy: 'tags')]
    public Collection $teams;

    public function __construct(string $code)
    {
        $this->code = $code;
        $this->teams = new Collection();
    }
}
