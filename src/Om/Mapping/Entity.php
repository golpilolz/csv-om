<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Om\Mapping;

use Attribute;
use Golpilolz\CsvOm\Om\Repository\RepositoryInterface;

/**
 * Maps a class to a CSV file. One row of the file is one object.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Entity
{
    /**
     * @param string|null $file CSV file name, relative to the configured directory.
     *                          Defaults to the snake_case short class name + ".csv".
     * @param class-string<RepositoryInterface>|null $repositoryClass Custom repository class.
     * @param string|null $delimiter Field delimiter (one character). Defaults to the bundle configuration.
     * @param string|null $enclosure Field enclosure (one character). Defaults to the bundle configuration.
     */
    public function __construct(
        public readonly ?string $file = null,
        public readonly ?string $repositoryClass = null,
        public readonly ?string $delimiter = null,
        public readonly ?string $enclosure = null,
    ) {
    }
}
