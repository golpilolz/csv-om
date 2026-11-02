<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Tests\Fixtures\Relations;

use Golpilolz\CsvOm\Om\Mapping as CsvOm;

/** Unidirectional many-to-one with the default RESTRICT rule. */
#[CsvOm\Entity(file: 'coaches.csv')]
class Coach
{
    #[CsvOm\Id]
    public int $id;

    #[CsvOm\ManyToOne(Team::class)]
    public Team $team;

    public function __construct(int $id, Team $team)
    {
        $this->id = $id;
        $this->team = $team;
    }
}
