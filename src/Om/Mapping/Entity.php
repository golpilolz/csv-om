<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Om\Mapping;

use Attribute;
use Golpilolz\CsvOm\Om\Repository\RepositoryInterface;

/**
 * Maps a class to a CSV file, or to a directory of CSV files. One row is one object.
 *
 * With `directory`, every `.csv` file of the directory (and of its subdirectories) holds rows of the entity:
 * they all need the mapped columns, and ids are unique across all of them. See `#[SourceFile]`.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Entity
{
    /**
     * @param string|null $file CSV file name, relative to the configured directory.
     *                          Defaults to the snake_case short class name + ".csv" (unless `directory` is set).
     * @param class-string<RepositoryInterface>|null $repositoryClass Custom repository class.
     * @param string|null $delimiter Field delimiter (one character). Defaults to the bundle configuration.
     * @param string|null $enclosure Field enclosure (one character). Defaults to the bundle configuration.
     * @param string|null $directory Directory, relative to the configured directory, whose `.csv` files hold the
     *                               rows. Exclusive with `file`.
     * @param bool|null $recursive With `directory`: also read the subdirectories (default true).
     * @param string|null $newFile With `directory`: file, relative to the directory, that receives new objects
     *                             that have no `#[SourceFile]` value. Without it such objects can't be persisted.
     */
    public function __construct(
        public ?string $file = null,
        public ?string $repositoryClass = null,
        public ?string $delimiter = null,
        public ?string $enclosure = null,
        public ?string $directory = null,
        public ?bool $recursive = null,
        public ?string $newFile = null,
    ) {
    }
}
