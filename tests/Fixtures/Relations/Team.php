<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Tests\Fixtures\Relations;

use Golpilolz\CsvOm\Om\Collection\Collection;
use Golpilolz\CsvOm\Om\Mapping as CsvOm;

#[CsvOm\Entity(file: 'teams.csv')]
class Team
{
    #[CsvOm\Id]
    public int $id;

    #[CsvOm\Column]
    public string $name;

    /** @var Collection<Player> */
    #[CsvOm\OneToMany(Player::class, mappedBy: 'team')]
    public Collection $players;

    /** @var Collection<Tag> */
    #[CsvOm\ManyToMany(Tag::class, inversedBy: 'teams')]
    public Collection $tags;

    public function __construct(int $id, string $name)
    {
        $this->id = $id;
        $this->name = $name;
        $this->players = new Collection();
        $this->tags = new Collection();
    }
}
