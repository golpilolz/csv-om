<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Tests\Fixtures\Relations;

use Golpilolz\CsvOm\Om\Mapping as CsvOm;

#[CsvOm\Entity(file: 'players.csv')]
class Player
{
    #[CsvOm\Id]
    public int $id;

    #[CsvOm\Column]
    public string $name;

    /** Removing the team keeps the player. */
    #[CsvOm\ManyToOne(Team::class, inversedBy: 'players')]
    #[CsvOm\JoinColumn(onDelete: CsvOm\JoinColumn::SET_NULL)]
    public ?Team $team = null;

    #[CsvOm\OneToOne(Badge::class, mappedBy: 'player', cascade: ['persist'])]
    public ?Badge $badge = null;

    public function __construct(int $id, string $name, ?Team $team = null)
    {
        $this->id = $id;
        $this->name = $name;
        $this->team = $team;
    }
}
