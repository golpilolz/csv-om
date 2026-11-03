<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Tests\Fixtures\Relations;

use Golpilolz\CsvOm\Om\Collection\Collection;
use Golpilolz\CsvOm\Om\Mapping as CsvOm;

#[CsvOm\Entity(file: 'clubs.csv')]
class Club
{
    #[CsvOm\Id]
    public int $id;

    /** @var Collection<Tag> */
    #[CsvOm\ManyToMany(Tag::class)]
    #[CsvOm\JoinTable(directory: 'club_tags', newFile: 'new.csv')]
    public Collection $tags;

    /** @var Collection<Tag> */
    #[CsvOm\ManyToMany(Tag::class)]
    #[CsvOm\JoinTable(directory: 'club_labels')]
    public Collection $labels;

    public function __construct(int $id)
    {
        $this->id = $id;
        $this->tags = new Collection();
        $this->labels = new Collection();
    }
}
