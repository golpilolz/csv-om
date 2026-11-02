<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Tests\Fixtures\Relations;

use Golpilolz\CsvOm\Om\Mapping as CsvOm;

#[CsvOm\Entity(file: 'badges.csv')]
class Badge
{
    #[CsvOm\Id]
    public int $id;

    #[CsvOm\Column]
    public string $label;

    /** Owning side of the one-to-one; removing the player removes the badge. */
    #[CsvOm\OneToOne(Player::class, inversedBy: 'badge')]
    #[CsvOm\JoinColumn(name: 'owner_id', onDelete: CsvOm\JoinColumn::CASCADE)]
    public Player $player;

    public function __construct(int $id, string $label, Player $player)
    {
        $this->id = $id;
        $this->label = $label;
        $this->player = $player;
    }
}
