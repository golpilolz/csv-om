<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Tests\Fixtures\Entity;

use Golpilolz\CsvOm\Om\Mapping as CsvOm;

#[CsvOm\Entity]
class ProductCategory
{
    #[CsvOm\Id]
    #[CsvOm\Column(name: 'code')]
    public string $id;
}
