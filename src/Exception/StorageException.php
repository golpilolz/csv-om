<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Exception;

final class StorageException extends \RuntimeException
{
    public static function invalidPath(string $file): self
    {
        return new self(sprintf('CSV file "%s" must be a non-empty relative path without "..".', $file));
    }

    public static function fileNotFound(string $path): self
    {
        return new self(sprintf('CSV file "%s" does not exist.', $path));
    }

    public static function unreadable(string $path, ?\Throwable $previous = null): self
    {
        return new self(sprintf('CSV file "%s" cannot be read%s', $path, $previous ? ': ' . $previous->getMessage() : '.'), 0, $previous);
    }

    public static function duplicateHeader(string $path, string $column): self
    {
        return new self(sprintf('CSV file "%s" has duplicate column "%s" in its header.', $path, $column));
    }

    public static function invalidRecord(string $path, int $line, int $expected, int $actual): self
    {
        return new self(sprintf('CSV file "%s", line %d: expected %d fields, got %d.', $path, $line, $expected, $actual));
    }

    public static function invalidRow(string $path, int $index, string $reason): self
    {
        return new self(sprintf('Cannot write row #%d to CSV file "%s": %s.', $index, $path, $reason));
    }

    public static function writeFailed(string $path, string $reason, ?\Throwable $previous = null): self
    {
        return new self(sprintf('Cannot write CSV file "%s": %s.', $path, $reason), 0, $previous);
    }

    public static function invalidControlCharacter(string $option, string $value): self
    {
        return new self(sprintf('CSV option "%s" must be a single character, "%s" given.', $option, $value));
    }
}
