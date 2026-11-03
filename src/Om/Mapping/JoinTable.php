<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Om\Mapping;

use Attribute;

/**
 * Configures the join file of an owning #[ManyToMany]: one row per pair, with two id columns.
 *
 * With `directory`, the pairs are spread over every `.csv` file of the directory (like `#[Entity(directory)]`):
 * they all need the two id columns. Removed pairs are dropped from the file they live in; new pairs are
 * added to `newFile`.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class JoinTable
{
    /**
     * @param string|null $file Join file, relative to the configured directory. Defaults to the owner's file
     *                          name without extension + "_<property>.csv" (snake_case).
     *                          Exclusive with `directory`.
     * @param string|null $joinColumn Column holding the owner id. Defaults to "<owner>_id" (snake_case class name).
     * @param string|null $inverseJoinColumn Column holding the target id. Defaults to "<target>_id".
     * @param string|null $directory Directory, relative to the configured directory, whose `.csv` files hold
     *                               the pairs. Exclusive with `file`.
     * @param bool|null $recursive With `directory`: also read the subdirectories (default true).
     * @param string|null $newFile With `directory`: file, relative to the directory, that receives new pairs.
     *                             Without it, adding a pair throws on flush.
     */
    public function __construct(
        public ?string $file = null,
        public ?string $joinColumn = null,
        public ?string $inverseJoinColumn = null,
        public ?string $directory = null,
        public ?bool $recursive = null,
        public ?string $newFile = null,
    ) {
    }
}
