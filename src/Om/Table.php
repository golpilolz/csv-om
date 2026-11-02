<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Om;

/**
 * In-memory content of one entity file, as loaded. Internal to the unit of work.
 *
 * Rows stay on disk: only their byte offsets are kept ({@see RowIndex}). Converted values are derived on demand,
 * one field at a time, and cached per field ({@see self::$canonicalColumns}, {@see self::$valueColumns}), so a big
 * file costs a few dozen bytes per row plus the fields that queries actually use.
 *
 * @internal
 */
final class Table
{
    /** @var array<string, array<string, ?string>> field => (row id => canonical CSV string), filled lazily */
    public array $canonicalColumns = [];

    /** @var array<string, array<string, mixed>> field => (row id => PHP value), filled lazily */
    public array $valueColumns = [];

    /** @var list<string> header of the first file (the only one for a file entity) */
    public readonly array $header;

    /** @var list<string> unmapped columns of the first file (the only one for a file entity) */
    public readonly array $extraColumns;

    /**
     * @param list<TableFile> $files the files read, in the order their rows come: one for a file entity (even
     *        when it does not exist yet), the matching files for a directory entity
     * @param RowIndex $rows raw cells (mapped and extra columns) as read, by canonical id, in file order; rows are
     *        read from the files on demand
     * @param string $listing version of the list of files of a directory entity, empty for a file entity
     */
    public function __construct(
        public readonly array $files,
        public readonly RowIndex $rows,
        public readonly string $listing = '',
    ) {
        $this->header = $files[0]->header ?? [];
        $this->extraColumns = $files[0]->extraColumns ?? [];
    }

    public function file(string $path): ?TableFile
    {
        foreach ($this->files as $file) {
            if ($file->path === $path) {
                return $file;
            }
        }

        return null;
    }

    /**
     * The file a loaded row comes from.
     */
    public function fileOfRow(int|string $id): TableFile
    {
        return $this->files[$this->rows->fileIndex($id)];
    }
}
