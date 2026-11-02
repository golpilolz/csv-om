<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Storage;

use Golpilolz\CsvOm\Exception\ConcurrentModificationException;
use Golpilolz\CsvOm\Exception\StorageException;
use League\Csv\Exception as CsvException;
use League\Csv\Reader;
use League\Csv\Writer;

/**
 * Filesystem storage based on league/csv.
 *
 * Writes go to a temporary file in the target directory, which is then renamed over the target,
 * so readers always see a complete file. Concurrent writers are serialized with an exclusive
 * flock() on a sidecar ".<file>.lock" file (the target itself cannot be locked: rename() replaces it).
 * Two processes that both read then write would still overwrite each other: pass the fingerprint
 * seen at read time to write() (see {@see Fingerprint}) to turn that into a
 * {@see ConcurrentModificationException}.
 */
final class CsvStorage implements CsvStorageInterface
{
    /** RFC 4180: quotes are escaped by doubling them, no backslash escape. */
    private const string ESCAPE = '';

    private readonly string $directory;

    private bool $directoryChecked = false;

    public function __construct(
        string $directory,
        private readonly string $delimiter = ',',
        private readonly string $enclosure = '"',
    ) {
        $this->assertControlCharacter('delimiter', $delimiter);
        $this->assertControlCharacter('enclosure', $enclosure);
        $this->directory = rtrim($directory, '/\\');
    }

    public function exists(string $file): bool
    {
        return is_file($this->path($file));
    }

    public function read(string $file, ?string $delimiter = null, ?string $enclosure = null): CsvTable
    {
        $path = $this->path($file);
        if (!is_file($path)) {
            throw StorageException::fileNotFound($path);
        }
        if (!is_readable($path)) {
            throw StorageException::unreadable($path);
        }

        try {
            $reader = Reader::from($path);
            $reader->setDelimiter($this->resolve('delimiter', $delimiter, $this->delimiter));
            $reader->setEnclosure($this->resolve('enclosure', $enclosure, $this->enclosure));
            $reader->setEscape(self::ESCAPE);

            $header = null;
            $rows = [];
            // Without a header offset, records are lists keyed by 0-based record offset; empty lines are skipped.
            foreach ($reader->getRecords() as $offset => $record) {
                $record = array_map(static fn (?string $v): string => $v ?? '', $record);

                if ($header === null) {
                    $header = $this->validateHeader($path, $record);
                    continue;
                }
                if (count($record) !== count($header)) {
                    throw StorageException::invalidRecord($path, $offset + 1, count($header), count($record));
                }
                $rows[] = array_combine($header, $record);
            }
        } catch (CsvException $e) {
            throw StorageException::unreadable($path, $e);
        }

        return new CsvTable($header ?? [], $rows);
    }

    public function open(string $file, ?string $delimiter = null, ?string $enclosure = null): CsvStream
    {
        $path = $this->path($file);
        if (!is_file($path)) {
            throw StorageException::fileNotFound($path);
        }
        if (!is_readable($path)) {
            throw StorageException::unreadable($path);
        }

        return new CsvStream(
            $path,
            $this->resolve('delimiter', $delimiter, $this->delimiter),
            $this->resolve('enclosure', $enclosure, $this->enclosure),
        );
    }

    public function listFiles(string $directory, bool $recursive = true): array
    {
        $root = $this->path($directory);
        if (!is_dir($root)) {
            return [];
        }

        $files = [];
        $this->collect($root, rtrim(str_replace('\\', '/', $directory), '/'), $recursive, $files);
        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * @param list<string> $files
     */
    private function collect(string $absolute, string $relative, bool $recursive, array &$files): void
    {
        $entries = @scandir($absolute);
        if ($entries === false) {
            throw StorageException::unreadable($absolute);
        }
        foreach ($entries as $entry) {
            if (str_starts_with($entry, '.')) {
                continue;
            }
            $path = $absolute . '/' . $entry;
            if (is_link($path)) {
                continue;
            }
            if (is_dir($path)) {
                if ($recursive) {
                    $this->collect($path, $relative . '/' . $entry, true, $files);
                }
            } elseif (is_file($path) && strcasecmp(substr($entry, -4), '.csv') === 0) {
                $files[] = $relative . '/' . $entry;
            }
        }
    }

    public function fingerprint(string $file): string
    {
        return Fingerprint::ofPath($this->path($file));
    }

    public function write(string $file, array $header, iterable $rows, ?string $delimiter = null, ?string $enclosure = null, ?string $expectedFingerprint = null): string
    {
        $path = $this->path($file);
        $delimiter = $this->resolve('delimiter', $delimiter, $this->delimiter);
        $enclosure = $this->resolve('enclosure', $enclosure, $this->enclosure);
        $header = array_values($header);
        $this->validateHeader($path, $header);

        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw StorageException::writeFailed($path, sprintf('directory "%s" cannot be created', $dir));
        }

        $lock = @fopen(Fingerprint::lockPath($path), 'c');
        if ($lock === false) {
            throw StorageException::writeFailed($path, 'cannot open lock file');
        }
        if (!flock($lock, LOCK_EX)) {
            fclose($lock);
            throw StorageException::writeFailed($path, 'cannot acquire lock');
        }

        $tmp = null;
        try {
            if ($expectedFingerprint !== null && Fingerprint::ofPath($path) !== $expectedFingerprint) {
                throw ConcurrentModificationException::forFile($path);
            }

            $tmp = @tempnam($dir, '.' . basename($path) . '.');
            if ($tmp === false || dirname($tmp) !== $dir) {
                // tempnam() falls back to the system temp dir, where rename() would not be atomic.
                if (is_string($tmp)) {
                    @unlink($tmp);
                }
                $tmp = null;
                throw StorageException::writeFailed($path, sprintf('cannot create a temporary file in "%s"', $dir));
            }

            $this->writeTemp($path, $tmp, $header, $rows, $delimiter, $enclosure);

            // tempnam() creates files with 0600: keep the existing mode, or apply the umask to a new file.
            $mode = is_file($path) ? (fileperms($path) & 0777) : (0666 & ~umask());
            @chmod($tmp, $mode);

            if (!@rename($tmp, $path)) {
                throw StorageException::writeFailed($path, 'cannot replace the file');
            }
            $tmp = null;

            // A new token for every write: the version changes even if size, time and inode do not.
            if (@ftruncate($lock, 0) === false || @rewind($lock) === false || @fwrite($lock, bin2hex(random_bytes(8))) === false || !@fflush($lock)) {
                throw StorageException::writeFailed($path, 'cannot update the lock file');
            }

            return Fingerprint::ofPath($path);
        } finally {
            if ($tmp !== null) {
                @unlink($tmp);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @param list<string> $header
     * @param iterable<array<string, string|null>> $rows
     */
    private function writeTemp(string $path, string $tmp, array $header, iterable $rows, string $delimiter, string $enclosure): void
    {
        $stream = @fopen($tmp, 'w');
        if ($stream === false) {
            throw StorageException::writeFailed($path, 'cannot open temporary file');
        }

        try {
            $writer = Writer::from($stream);
            $writer->setDelimiter($delimiter);
            $writer->setEnclosure($enclosure);
            $writer->setEscape(self::ESCAPE);
            $writer->insertOne($header);

            $index = 0;
            foreach ($rows as $row) {
                $writer->insertOne($this->orderRow($path, $index++, $header, $row));
            }
            unset($writer);

            if (!fflush($stream) || !fsync($stream)) {
                throw StorageException::writeFailed($path, 'cannot flush temporary file');
            }
        } catch (CsvException $e) {
            throw StorageException::writeFailed($path, $e->getMessage(), $e);
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param list<string> $header
     * @param array<string, string|null> $row
     * @return list<string>
     */
    private function orderRow(string $path, int $index, array $header, array $row): array
    {
        if (count($row) !== count($header) || array_diff_key(array_flip($header), $row) !== []) {
            $missing = array_diff($header, array_map(strval(...), array_keys($row)));
            $extra = array_diff(array_map(strval(...), array_keys($row)), $header);

            throw StorageException::invalidRow($path, $index, trim(
                ($missing ? 'missing columns ' . implode(', ', $missing) . ' ' : '')
                . ($extra ? 'unknown columns ' . implode(', ', $extra) : ''),
            ));
        }

        $ordered = [];
        foreach ($header as $column) {
            $value = $row[$column];
            if ($value !== null && !is_string($value)) {
                throw StorageException::invalidRow($path, $index, sprintf('value of column "%s" must be a string or null, %s given', $column, get_debug_type($value)));
            }
            $ordered[] = $value ?? '';
        }

        return $ordered;
    }

    /**
     * @param list<string> $header
     * @return list<string>
     */
    private function validateHeader(string $path, array $header): array
    {
        $seen = [];
        foreach ($header as $column) {
            if (isset($seen[$column])) {
                throw StorageException::duplicateHeader($path, $column);
            }
            $seen[$column] = true;
        }

        return $header;
    }

    private function path(string $file): string
    {
        // A missing base directory is a configuration error: report it instead of treating every file as missing.
        if (!$this->directoryChecked) {
            if (!is_dir($this->directory)) {
                throw StorageException::directoryNotFound($this->directory);
            }
            $this->directoryChecked = true;
        }

        $normalized = str_replace('\\', '/', $file);
        if ($normalized === '' || str_starts_with($normalized, '/') || preg_match('#^[a-zA-Z]:#', $normalized)
            || in_array('..', explode('/', $normalized), true)) {
            throw StorageException::invalidPath($file);
        }

        return $this->directory . '/' . $normalized;
    }

    private function resolve(string $option, ?string $value, string $default): string
    {
        if ($value === null) {
            return $default;
        }
        $this->assertControlCharacter($option, $value);

        return $value;
    }

    private function assertControlCharacter(string $option, string $value): void
    {
        if (strlen($value) !== 1) {
            throw StorageException::invalidControlCharacter($option, $value);
        }
    }
}
