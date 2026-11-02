<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Tests\Fixtures\Entity;

use Golpilolz\CsvOm\Om\Mapping as CsvOm;

#[CsvOm\Entity(file: 'tokens.csv')]
class Token
{
    #[CsvOm\Id]
    #[CsvOm\GeneratedValue(strategy: CsvOm\GeneratedValue::UUID)]
    public string $id;
}
