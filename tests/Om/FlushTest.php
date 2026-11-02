<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Tests\Om;

use Golpilolz\CsvOm\Exception\ConversionException;
use Golpilolz\CsvOm\Exception\PersistenceException;
use Golpilolz\CsvOm\Exception\StorageException;
use Golpilolz\CsvOm\Om\ObjectManager;
use Golpilolz\CsvOm\Storage\CsvStorage;
use Golpilolz\CsvOm\Storage\CsvStorageInterface;
use Golpilolz\CsvOm\Storage\CsvTable;
use Golpilolz\CsvOm\Tests\CsvDirectoryTrait;
use Golpilolz\CsvOm\Tests\Fixtures\Entity\Author;
use Golpilolz\CsvOm\Tests\Fixtures\Entity\Book;
use Golpilolz\CsvOm\Tests\Fixtures\Entity\BookStatus;
use Golpilolz\CsvOm\Tests\Fixtures\Entity\Priority;
use Golpilolz\CsvOm\Tests\Fixtures\Entity\ProductCategory;
use PHPUnit\Framework\TestCase;

final class FlushTest extends TestCase
{
    use CsvDirectoryTrait;

    // ";" delimiter, "'" enclosure, unmapped "isbn" column, id "007" with leading zeros, quoted note.
    private const BOOKS = <<<CSV
        id;book_title;isbn;price;available;published_at;status;priority;notes;legacyCode
        10;Dune;978-0441;9.99;1;1965-08-01T00:00:00+00:00;published;2;;1
        9;Hyperion;978-0553;;0;;draft;;'a; note';2
        007;Foundation;978-0553;7.5;1;1951-06-01T00:00:00+00:00;published;1;;3

        CSV;

    private ObjectManager $om;

    protected function setUp(): void
    {
        $this->createCsvDirectory();
        $this->putCsv('library/books.csv', self::BOOKS);
        $this->om = new ObjectManager(new CsvStorage($this->dir));
    }

    protected function tearDown(): void
    {
        $this->removeCsvDirectory();
    }

    private function books(): string
    {
        return (string) file_get_contents($this->dir . '/library/books.csv');
    }

    private static function set(object $object, string $field, mixed $value): void
    {
        (new \ReflectionProperty($object, $field))->setValue($object, $value);
    }

    private static function get(object $object, string $field): mixed
    {
        return (new \ReflectionProperty($object, $field))->getValue($object);
    }

    private function newBook(int $id, string $title): Book
    {
        $book = (new \ReflectionClass(Book::class))->newInstanceWithoutConstructor();
        foreach (['id' => $id, 'title' => $title, 'price' => null, 'available' => true, 'publishedAt' => null,
            'status' => BookStatus::Draft, 'priority' => null, 'notes' => null, 'legacyCode' => 0] as $field => $value) {
            self::set($book, $field, $value);
        }

        return $book;
    }

    public function testUpdateRewritesOnlyChangedRowAndKeepsUnmappedColumns(): void
    {
        $book = $this->om->find(Book::class, 9);
        self::set($book, 'title', 'Hyperion; revised');
        self::set($book, 'price', 4.0);
        self::set($book, 'status', BookStatus::Published);
        $this->om->flush();

        self::assertSame(<<<CSV
            id;book_title;isbn;price;available;published_at;status;priority;notes;legacyCode
            10;Dune;978-0441;9.99;1;1965-08-01T00:00:00+00:00;published;2;;1
            9;'Hyperion; revised';978-0553;4.0;0;;published;;'a; note';2
            007;Foundation;978-0553;7.5;1;1951-06-01T00:00:00+00:00;published;1;;3

            CSV, $this->books());
    }

    public function testUnchangedRowsKeepTheirRawCells(): void
    {
        $foundation = $this->om->find(Book::class, 7);
        $dune = $this->om->find(Book::class, 10);
        self::set($dune, 'priority', null);
        $this->om->flush();

        self::assertStringContainsString("\n007;Foundation;", $this->books(), 'unchanged row keeps "007"');
        self::assertStringContainsString("\n10;Dune;978-0441;9.99;1;1965-08-01T00:00:00+00:00;published;;;1\n", $this->books(), 'null is written as an empty cell');
        self::assertSame(7, self::get($foundation, 'id'));
    }

    public function testChangedRowIsWrittenCanonically(): void
    {
        $foundation = $this->om->find(Book::class, 7);
        self::set($foundation, 'title', 'Foundation and Empire');
        $this->om->flush();

        // Changed row: id written canonically ("007" -> "7"); league/csv quotes fields containing spaces.
        self::assertStringContainsString("\n7;'Foundation and Empire';978-0553;", $this->books());
    }

    public function testFlushWithoutChangesWritesNothing(): void
    {
        $this->om->find(Book::class, 9);
        $this->om->getRepository(Book::class)->findAll();
        $this->om->flush();

        self::assertSame(self::BOOKS, $this->books());
        self::assertFalse(is_file($this->dir . '/library/.books.csv.lock'), 'storage write was not called');
    }

    public function testPersistAppendsRowWithEmptyUnmappedCells(): void
    {
        $book = $this->newBook(11, 'Solaris');
        self::set($book, 'publishedAt', new \DateTimeImmutable('1961-01-01T00:00:00+00:00'));
        self::set($book, 'priority', Priority::Low);

        $this->om->persist($book);
        self::assertTrue($this->om->contains($book));
        self::assertSame($book, $this->om->find(Book::class, 11), 'persisted object is in the identity map');
        self::assertSame(3, $this->om->getRepository(Book::class)->count(), 'queries see loaded data, not unflushed objects');

        $this->om->flush();

        self::assertStringEndsWith("\n11;Solaris;;;1;1961-01-01T00:00:00+00:00;draft;1;;0\n", $this->books());
        self::assertSame(4, $this->om->getRepository(Book::class)->count());
        self::assertSame($book, $this->om->getRepository(Book::class)->findOneBy(['title' => 'Solaris']));
    }

    public function testPersistIntoMissingFileCreatesItWithMappedHeader(): void
    {
        $this->om->persist(new Author(1, 'Ursula', new \DateTimeImmutable('1929-10-21')));
        $this->om->persist(new Author(2, 'Iain'));
        $this->om->flush();

        self::assertSame("id,birth_date,priority,name\n1,1929-10-21,,Ursula\n2,,,Iain\n", file_get_contents($this->dir . '/author.csv'));
    }

    public function testWrittenDataIsReadBackByAnotherObjectManager(): void
    {
        $author = new Author(3, 'Ann', new \DateTimeImmutable('1950-05-04'));
        $author->priority = Priority::High;
        $this->om->persist($author);
        $this->om->flush();

        $other = new ObjectManager(new CsvStorage($this->dir));
        $read = $other->find(Author::class, 3);

        self::assertNotSame($author, $read);
        self::assertSame('Ann', $read->getName());
        self::assertSame('1950-05-04', $read->birthDate->format('Y-m-d'));
        self::assertSame(Priority::High, $read->priority);
    }

    public function testPersistTwiceIsANoOp(): void
    {
        $author = new Author(1, 'a');
        $this->om->persist($author);
        $this->om->persist($author);
        $this->om->flush();

        self::assertSame("id,birth_date,priority,name\n1,,,a\n", file_get_contents($this->dir . '/author.csv'));
    }

    public function testPersistWithoutIdThrows(): void
    {
        $this->expectException(PersistenceException::class);
        $this->expectExceptionMessage('without identifier');

        $this->om->persist(new ProductCategory());
    }

    public function testPersistIdAlreadyInFileThrowsEvenIfNotLoaded(): void
    {
        $this->expectException(PersistenceException::class);
        $this->expectExceptionMessage('with id "7" already exists');

        $this->om->persist($this->newBook(7, 'Duplicate'));
    }

    public function testPersistIdOfAnotherNewObjectThrows(): void
    {
        $this->om->persist(new Author(1, 'a'));

        $this->expectException(PersistenceException::class);
        $this->expectExceptionMessage('with id "1" already exists');

        $this->om->persist(new Author(1, 'b'));
    }

    public function testRemoveDeletesRow(): void
    {
        $repository = $this->om->getRepository(Book::class);
        $book = $this->om->find(Book::class, 9);

        $this->om->remove($book);
        self::assertFalse($this->om->contains($book));
        self::assertSame(3, $repository->count(), 'not removed before flush');

        $this->om->flush();

        self::assertStringNotContainsString('Hyperion', $this->books());
        self::assertStringContainsString("\n007;Foundation;", $this->books());
        self::assertSame(2, $repository->count());
        self::assertNull($this->om->find(Book::class, 9));
        self::assertFalse($this->om->contains($book));
    }

    public function testFlushedNewObjectIsManagedLikeALoadedOne(): void
    {
        $author = new Author(1, 'a');
        $this->om->persist($author);
        $this->om->flush();

        $author->priority = Priority::High;
        $this->om->flush();
        self::assertSame("id,birth_date,priority,name\n1,,2,a\n", file_get_contents($this->dir . '/author.csv'));

        // After its first flush, removing it must delete the row, not just cancel a pending insertion.
        $this->om->remove($author);
        $this->om->flush();
        self::assertSame("id,birth_date,priority,name\n", file_get_contents($this->dir . '/author.csv'));
    }

    public function testRemoveNewObjectCancelsInsertion(): void
    {
        $author = new Author(1, 'a');
        $this->om->persist($author);
        $this->om->remove($author);
        $this->om->flush();

        self::assertFalse($this->om->contains($author));
        self::assertFileDoesNotExist($this->dir . '/author.csv');
    }

    public function testPersistCancelsRemoval(): void
    {
        $book = $this->om->find(Book::class, 9);
        $this->om->remove($book);
        $this->om->persist($book);
        $this->om->flush();

        self::assertTrue($this->om->contains($book));
        self::assertSame(self::BOOKS, $this->books());
    }

    public function testRemoveUnmanagedObjectThrows(): void
    {
        $this->expectException(PersistenceException::class);
        $this->expectExceptionMessage('not managed');

        $this->om->remove(new Author(1, 'a'));
    }

    public function testRemovedIdCanBeReusedAfterFlush(): void
    {
        $this->om->remove($this->om->find(Book::class, 9));
        $this->om->flush();

        $this->om->persist($this->newBook(9, 'Hyperion again'));
        $this->om->flush();

        self::assertStringEndsWith("\n9;'Hyperion again';;;1;;draft;;;0\n", $this->books());
    }

    public function testChangingIdentifierThrowsAndWritesNothing(): void
    {
        $book = $this->om->find(Book::class, 9);
        self::set($book, 'id', 99);

        try {
            $this->om->flush();
            self::fail('Expected a PersistenceException.');
        } catch (PersistenceException $e) {
            self::assertStringContainsString('cannot change (from "9" to "99")', $e->getMessage());
        }
        self::assertSame(self::BOOKS, $this->books());
    }

    public function testInvalidValueInOneFileWritesNoFile(): void
    {
        $this->om->persist(new Author(1, 'valid'));
        $book = $this->om->find(Book::class, 9);
        $book->legacyCode = 'not-an-int';

        try {
            $this->om->flush();
            self::fail('Expected a ConversionException.');
        } catch (ConversionException) {
        }

        self::assertFileDoesNotExist($this->dir . '/author.csv');
        self::assertSame(self::BOOKS, $this->books());
    }

    public function testWriteFailureKeepsEarlierFilesAndLeavesRestPending(): void
    {
        $storage = new class (new CsvStorage($this->dir)) implements CsvStorageInterface {
            public bool $failBooks = true;

            public function __construct(private readonly CsvStorageInterface $inner)
            {
            }

            public function exists(string $file): bool
            {
                return $this->inner->exists($file);
            }

            public function read(string $file, ?string $delimiter = null, ?string $enclosure = null): CsvTable
            {
                return $this->inner->read($file, $delimiter, $enclosure);
            }

            public function write(string $file, array $header, iterable $rows, ?string $delimiter = null, ?string $enclosure = null): void
            {
                if ($this->failBooks && $file === 'library/books.csv') {
                    throw new StorageException('disk full');
                }
                $this->inner->write($file, $header, $rows, $delimiter, $enclosure);
            }
        };
        $om = new ObjectManager($storage);

        // Author is managed first, so its file is written first.
        $om->persist(new Author(1, 'a'));
        self::set($om->find(Book::class, 9), 'title', 'Changed');

        try {
            $om->flush();
            self::fail('Expected a StorageException.');
        } catch (StorageException) {
        }
        self::assertFileExists($this->dir . '/author.csv');
        self::assertSame(self::BOOKS, $this->books());

        $storage->failBooks = false;
        $om->flush();

        self::assertStringContainsString("\n9;Changed;", $this->books());
        self::assertSame("id,birth_date,priority,name\n1,,,a\n", file_get_contents($this->dir . '/author.csv'), 'author row not duplicated');
    }

    public function testClearDiscardsPendingChangesAndRereadsFiles(): void
    {
        $book = $this->om->find(Book::class, 9);
        self::set($book, 'title', 'Never saved');
        $this->om->persist(new Author(1, 'never saved'));

        $this->om->clear();

        self::assertFalse($this->om->contains($book));
        $this->om->flush();
        self::assertSame(self::BOOKS, $this->books());
        self::assertFileDoesNotExist($this->dir . '/author.csv');

        $this->putCsv('library/books.csv', str_replace('Hyperion', 'Edited outside', self::BOOKS));
        $reloaded = $this->om->find(Book::class, 9);
        self::assertNotSame($book, $reloaded);
        self::assertSame('Edited outside', self::get($reloaded, 'title'));
    }

    public function testDetachedObjectIsIgnoredByFlush(): void
    {
        $book = $this->om->find(Book::class, 9);
        $this->om->clear();
        self::set($book, 'title', 'Changed after clear');
        $this->om->flush();

        self::assertSame(self::BOOKS, $this->books());
    }
}
