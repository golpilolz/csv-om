<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Tests\Fixtures\Entity;

use Golpilolz\CsvOm\Om\Mapping as CsvOm;

/**
 * Entity identified by a backed enum.
 */
#[CsvOm\Entity]
final class StatusLabel
{
    #[CsvOm\Id]
    public BookStatus $status;

    #[CsvOm\Column]
    public string $label = '';
}
