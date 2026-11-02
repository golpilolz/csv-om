<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Tests\Fixtures\Entity;

use Golpilolz\CsvOm\Om\Mapping as CsvOm;

abstract class Person
{
    #[CsvOm\Column]
    protected string $name;

    public function getName(): string
    {
        return $this->name;
    }
}
