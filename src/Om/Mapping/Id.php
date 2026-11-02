<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Om\Mapping;

use Attribute;

/**
 * Marks the identifier property. Implies #[Column] with default options when no #[Column] is present.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Id
{
}
