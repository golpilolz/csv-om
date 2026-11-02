<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Om\Mapping;

use Attribute;

/**
 * Makes the identifier generated: when an object is persisted with its id property still unset, the id is
 * generated at persist() time (so it is available before flush()). An id that is already set is kept as is.
 *
 * - INCREMENT (default): highest known id + 1 (1 for an empty file). Requires an `int` id.
 * - UUID: random UUID v4. Requires a `string` id.
 *
 * Two processes that persist at the same time can pick the same incremented id, and the last flush wins: use
 * UUID when several writers share a file.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class GeneratedValue
{
    public const string INCREMENT = 'INCREMENT';
    public const string UUID = 'UUID';

    public function __construct(
        public string $strategy = self::INCREMENT,
    ) {
    }
}
