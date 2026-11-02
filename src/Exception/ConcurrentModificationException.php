<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Exception;

/**
 * A file was changed (by another process, or by hand) after this object manager loaded it, so writing it
 * would silently overwrite those changes. Nothing was written to that file: call `clear()` and redo the
 * change on fresh data.
 */
final class ConcurrentModificationException extends \RuntimeException
{
    public static function forFile(string $path): self
    {
        return new self(sprintf(
            'CSV file "%s" was modified since it was loaded: refusing to overwrite those changes. Call clear() and redo the change.',
            $path,
        ));
    }
}
