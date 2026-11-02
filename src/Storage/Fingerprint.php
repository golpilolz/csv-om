<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Storage;

/**
 * Identity of a file's current version. It combines a token that {@see CsvStorage} renews on every write
 * (kept in the sidecar lock file, so two writes never share one, even if the inode is reused within the same
 * second) with the file's inode, size and modification time (which reveal an edit made outside the bundle).
 * The fingerprint of a missing file is the empty string.
 *
 * @internal
 */
final class Fingerprint
{
    public const string MISSING = '';

    private function __construct()
    {
    }

    /**
     * Path of the sidecar file that serializes writers and holds the write token.
     */
    public static function lockPath(string $path): string
    {
        return dirname($path) . '/.' . basename($path) . '.lock';
    }

    /**
     * Stat part of a fingerprint, from stat()/fstat().
     *
     * @param array<string|int, int>|false $stat
     */
    public static function statKey(array|false $stat): string
    {
        return $stat === false ? self::MISSING : sprintf('%d-%d-%d', $stat['ino'], $stat['size'], $stat['mtime']);
    }

    /**
     * @param array<string|int, int>|false $stat stat of the file (or of the open handle reading it)
     */
    public static function of(string $path, array|false $stat): string
    {
        if ($stat === false) {
            return self::MISSING;
        }

        return trim((string) @file_get_contents(self::lockPath($path))) . '|' . self::statKey($stat);
    }

    public static function ofPath(string $path): string
    {
        clearstatcache(true, $path);

        return self::of($path, @stat($path));
    }
}
