<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Om;

/**
 * One file of a loaded {@see Table}: its path, header and the version it had when it was read. Internal to the
 * unit of work.
 *
 * @internal
 */
final readonly class TableFile
{
    /**
     * @param string $path path relative to the configured directory
     * @param list<string> $header header of the file (mapped columns first when the file did not exist)
     * @param list<string> $extraColumns columns present in the file but not mapped; kept so they can be written back
     * @param string $fingerprint version of the file when it was loaded (empty: it did not exist), see {@see \Golpilolz\CsvOm\Storage\Fingerprint}
     */
    public function __construct(
        public string $path,
        public array $header,
        public array $extraColumns,
        public string $fingerprint,
    ) {
    }
}
