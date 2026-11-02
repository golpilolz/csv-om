<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Om\Mapping;

use Attribute;

/**
 * Configures the join file of an owning #[ManyToMany]: one row per pair, with two id columns.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class JoinTable
{
    /**
     * @param string|null $file Join file, relative to the configured directory. Defaults to the owner's file
     *                          name without extension + "_<property>.csv" (snake_case).
     * @param string|null $joinColumn Column holding the owner id. Defaults to "<owner>_id" (snake_case class name).
     * @param string|null $inverseJoinColumn Column holding the target id. Defaults to "<target>_id".
     */
    public function __construct(
        public ?string $file = null,
        public ?string $joinColumn = null,
        public ?string $inverseJoinColumn = null,
    ) {
    }
}
