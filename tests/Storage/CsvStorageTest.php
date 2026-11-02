<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Tests\Storage;

use Golpilolz\CsvOm\Exception\StorageException;
use Golpilolz\CsvOm\Storage\CsvStorage;
use Golpilolz\CsvOm\Tests\CsvDirectoryTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CsvStorageTest extends TestCase
{
    use CsvDirectoryTrait;

    private CsvStorage $storage;

    protected function setUp(): void
    {
        $this->createCsvDirectory();
        $this->storage = new CsvStorage($this->dir . '/');
    }

    protected function tearDown(): void
    {
        $this->removeCsvDirectory();
    }

    private function put(string $file, string $content): void
    {
        $this->putCsv($file, $content);
    }

    public function testReadReturnsHeaderAndRowsKeyedByColumn(): void
    {
        $this->put('test.csv', "id,name\n1,margot\n2,arthur\n");

        $table = $this->storage->read('test.csv');

        self::assertSame(['id', 'name'], $table->header);
        self::assertSame([['id' => '1', 'name' => 'margot'], ['id' => '2', 'name' => 'arthur']], $table->rows);
    }

    public function testReadHandlesQuotingBomBlankLinesAndCrlf(): void
    {
        $this->put('test.csv', "\xEF\xBB\xBFid,text\r\n1,\"a, \"\"quoted\"\"\nmultiline\"\r\n\r\n2,\r\n");

        $table = $this->storage->read('test.csv');

        self::assertSame(['id', 'text'], $table->header);
        self::assertSame([
            ['id' => '1', 'text' => "a, \"quoted\"\nmultiline"],
            ['id' => '2', 'text' => ''],
        ], $table->rows);
    }

    public function testDelimiterAndEnclosureDefaultsAndOverrides(): void
    {
        $this->put('semi.csv', "id;name\n1;'a;b'\n");

        $semicolonStorage = new CsvStorage($this->dir, ';', "'");
        self::assertSame([['id' => '1', 'name' => 'a;b']], $semicolonStorage->read('semi.csv')->rows);
        self::assertSame([['id' => '1', 'name' => 'a;b']], $this->storage->read('semi.csv', ';', "'")->rows);
    }

    public function testHeaderOnlyAndEmptyFiles(): void
    {
        $this->put('header.csv', "id,name\n");
        $this->put('empty.csv', '');

        self::assertSame(['id', 'name'], $this->storage->read('header.csv')->header);
        self::assertSame([], $this->storage->read('header.csv')->rows);
        self::assertSame([], $this->storage->read('empty.csv')->header);
        self::assertSame([], $this->storage->read('empty.csv')->rows);
    }

    public function testOpenStreamsRowsByOffsetWithoutLoadingTheFile(): void
    {
        $this->put('s.csv', "\xEF\xBB\xBFid,note\r\n\r\n1,\"two\nlines\"\r\n2,\"a \"\"q\"\" b\"\n3,end");

        $stream = $this->storage->open('s.csv');
        self::assertSame(['id', 'note'], $stream->header);

        $offsets = [];
        $rows = [];
        foreach ($stream->records() as $offset => $row) {
            $offsets[] = $offset;
            $rows[] = $row;
        }
        self::assertSame([
            ['id' => '1', 'note' => "two\nlines"],
            ['id' => '2', 'note' => 'a "q" b'],
            ['id' => '3', 'note' => 'end'],
        ], $rows);

        // Random access, in any order, also while a sequential read is in progress.
        self::assertSame($rows[2], $stream->rowAt($offsets[2]));
        self::assertSame($rows[0], $stream->rowAt($offsets[0]));
    }

    public function testOpenReportsMalformedRecordsAndMissingFiles(): void
    {
        $this->put('bad.csv', "id,name\n1,a\n2\n");
        $this->put('dup.csv', "id,id\n");

        try {
            iterator_to_array($this->storage->open('bad.csv')->records());
            self::fail('Expected a StorageException.');
        } catch (StorageException) {
        }

        $this->expectException(StorageException::class);
        try {
            $this->storage->open('dup.csv');
        } finally {
            try {
                $this->storage->open('missing.csv');
            } catch (StorageException) {
            }
        }
    }

    public function testMissingBaseDirectoryIsAConfigurationError(): void
    {
        $storage = new CsvStorage($this->dir . '/nope');

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('CSV directory "' . $this->dir . '/nope" does not exist');

        $storage->exists('test.csv');
    }

    public function testExists(): void
    {
        $this->put('test.csv', "id\n");

        self::assertTrue($this->storage->exists('test.csv'));
        self::assertFalse($this->storage->exists('missing.csv'));
    }

    public function testReadMissingFileThrows(): void
    {
        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('does not exist');

        $this->storage->read('missing.csv');
    }

    public function testReadRejectsRowWithWrongFieldCount(): void
    {
        $this->put('test.csv', "id,name\n1,margot\n2\n");

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('line 3: expected 2 fields, got 1');

        $this->storage->read('test.csv');
    }

    public function testReadRejectsDuplicateHeader(): void
    {
        $this->put('test.csv', "id,id\n1,2\n");

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('duplicate column "id"');

        $this->storage->read('test.csv');
    }

    #[DataProvider('provideInvalidPaths')]
    public function testInvalidPathIsRejected(string $file): void
    {
        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('relative path');

        $this->storage->read($file);
    }

    public static function provideInvalidPaths(): iterable
    {
        yield 'empty' => [''];
        yield 'absolute' => ['/etc/passwd'];
        yield 'parent' => ['../outside.csv'];
        yield 'nested parent' => ['a/../../outside.csv'];
        yield 'windows backslash parent' => ['a\\..\\..\\outside.csv'];
        yield 'windows drive' => ['C:\\data.csv'];
    }

    public function testInvalidControlCharacterIsRejected(): void
    {
        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('"delimiter" must be a single character');

        new CsvStorage($this->dir, ';;');
    }

    public function testWriteThenReadRoundTrips(): void
    {
        $rows = [
            ['id' => '1', 'text' => "comma, \"quote\" and\nnewline"],
            ['id' => '2', 'text' => 'back\\slash\\"quote'],
            ['id' => '3', 'text' => null],
            // Breaks with league's default backslash escape: the closing quote is read as escaped.
            ['id' => '4', 'text' => 'quoted, ends with backslash\\'],
            ['id' => '5', 'text' => 'next row'],
        ];

        $this->storage->write('test.csv', ['id', 'text'], $rows);
        $table = $this->storage->read('test.csv');

        self::assertSame(['id', 'text'], $table->header);
        self::assertSame($rows[0], $table->rows[0]);
        self::assertSame($rows[1], $table->rows[1]);
        self::assertSame(['id' => '3', 'text' => ''], $table->rows[2], 'null is written as an empty field');
        self::assertSame($rows[3], $table->rows[3]);
        self::assertSame($rows[4], $table->rows[4]);
        self::assertCount(5, $table->rows);
    }

    public function testWriteOrdersColumnsByHeaderAndUsesDialect(): void
    {
        $this->storage->write('test.csv', ['id', 'name'], [['name' => 'a;b', 'id' => '1']], ';', "'");

        self::assertSame("id;name\n1;'a;b'\n", file_get_contents($this->dir . '/test.csv'));
    }

    public function testWriteGeneratorRows(): void
    {
        $rows = (static function () {
            yield ['id' => '1'];
            yield ['id' => '2'];
        })();

        $this->storage->write('test.csv', ['id'], $rows);

        self::assertSame("id\n1\n2\n", file_get_contents($this->dir . '/test.csv'));
    }

    public function testWriteCreatesSubdirectories(): void
    {
        $this->storage->write('library/2024/books.csv', ['id'], [['id' => '1']]);

        self::assertSame("id\n1\n", file_get_contents($this->dir . '/library/2024/books.csv'));
    }

    public function testWriteReplacesFileAndLeavesNoTemporaryFile(): void
    {
        $this->put('test.csv', "id\nold\n");
        chmod($this->dir . '/test.csv', 0640);

        $this->storage->write('test.csv', ['id'], [['id' => 'new']]);

        self::assertSame("id\nnew\n", file_get_contents($this->dir . '/test.csv'));
        clearstatcache();
        self::assertSame(0640, fileperms($this->dir . '/test.csv') & 0777, 'existing permissions are kept');
        self::assertSame(['.test.csv.lock', 'test.csv'], $this->listDir());
    }

    public function testNewFileGetsUmaskPermissionsNotTempnamOnes(): void
    {
        $this->storage->write('test.csv', ['id'], []);

        self::assertSame(0666 & ~umask(), fileperms($this->dir . '/test.csv') & 0777);
    }

    #[DataProvider('provideInvalidRows')]
    public function testInvalidRowKeepsOriginalFile(array $row, string $messagePart): void
    {
        $this->put('test.csv', "id,name\n1,original\n");

        try {
            $this->storage->write('test.csv', ['id', 'name'], [['id' => '1', 'name' => 'ok'], $row]);
            self::fail('Expected a StorageException.');
        } catch (StorageException $e) {
            self::assertStringContainsString('row #1', $e->getMessage());
            self::assertStringContainsString($messagePart, $e->getMessage());
        }

        self::assertSame("id,name\n1,original\n", file_get_contents($this->dir . '/test.csv'));
        self::assertSame(['.test.csv.lock', 'test.csv'], $this->listDir());
    }

    public static function provideInvalidRows(): iterable
    {
        yield 'missing column' => [['id' => '2'], 'missing columns name'];
        yield 'unknown column' => [['id' => '2', 'name' => 'x', 'extra' => 'y'], 'unknown columns extra'];
        yield 'renamed column' => [['id' => '2', 'nom' => 'x'], 'missing columns name unknown columns nom'];
        yield 'non string value' => [['id' => 2, 'name' => 'x'], 'must be a string or null, int given'];
    }

    public function testWriteRejectsDuplicateHeader(): void
    {
        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('duplicate column "id"');

        $this->storage->write('test.csv', ['id', 'id'], []);
    }
}
