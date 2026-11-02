<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Tests;

/**
 * Creates a fresh temporary CSV directory per test and removes it afterwards.
 */
trait CsvDirectoryTrait
{
    private string $dir;

    protected function createCsvDirectory(): void
    {
        $this->dir = sys_get_temp_dir() . '/csv-om-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function removeCsvDirectory(): void
    {
        if (!isset($this->dir) || !is_dir($this->dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->dir);
    }

    protected function putCsv(string $file, string $content): void
    {
        $path = $this->dir . '/' . $file;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $content);
    }

    /** @return list<string> */
    private function listDir(): array
    {
        $files = array_values(array_diff(scandir($this->dir), ['.', '..']));
        sort($files);

        return $files;
    }
}
