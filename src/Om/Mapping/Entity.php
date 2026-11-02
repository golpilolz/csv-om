<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Om\Mapping;

use Attribute;
use Golpilolz\CsvOm\Om\Repository\RepositoryInterface;

/**
 * Maps a class to a CSV file. One row of the file is one object.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Entity
{
    /**
     * @param string|null $file CSV file name, relative to the configured directory.
     *                          Defaults to the snake_case short class name + ".csv".
     * @param class-string<RepositoryInterface>|null $repositoryClass Custom repository class.
     * @param string|null $delimiter Field delimiter (one character). Defaults to the bundle configuration.
     * @param string|null $enclosure Field enclosure (one character). Defaults to the bundle configuration.
     */
    public function __construct(
        public ?string $file = null,
        public ?string $repositoryClass = null,
        public ?string $delimiter = null,
        public ?string $enclosure = null,
    ) {
    }
}
