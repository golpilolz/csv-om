<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Tests\Fixtures\Entity;

use Golpilolz\CsvOm\Om\Mapping as CsvOm;

#[CsvOm\Entity]
final class Author extends Person
{
    #[CsvOm\Id]
    public readonly int $id;

    #[CsvOm\Column(name: 'birth_date', format: 'Y-m-d')]
    public readonly ?\DateTimeImmutable $birthDate;

    #[CsvOm\Column]
    public ?Priority $priority = null;

    public bool $constructed = false;

    public function __construct(int $id, string $name, ?\DateTimeImmutable $birthDate = null)
    {
        $this->id = $id;
        $this->name = $name;
        $this->birthDate = $birthDate;
        $this->constructed = true;
    }
}
