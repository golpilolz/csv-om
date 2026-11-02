<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Om\Mapping;

use Attribute;

/**
 * On a `?string` property of an entity mapped to a `directory`: holds the path, relative to that directory
 * (`eu/2024.csv`), of the file the row lives in. Not a column.
 *
 * Set when the object is loaded. On a new object, it chooses the file the row is added to (created if
 * missing); left empty, the entity's `newFile` is used. It cannot be changed on a managed object: rows are
 * not moved between files.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class SourceFile
{
}
