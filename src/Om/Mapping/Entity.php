<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Om\Mapping;

use Attribute;
use Golpilolz\CsvOm\Om\Repository\RepositoryInterface;

#[Attribute(Attribute::TARGET_CLASS)]
final class Entity
{
    public function __construct(public ?string $prefix = null, public ?RepositoryInterface $repository = null)
    {

    }
}