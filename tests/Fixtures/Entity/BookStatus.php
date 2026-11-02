<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Tests\Fixtures\Entity;

enum BookStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}
