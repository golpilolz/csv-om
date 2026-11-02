<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Tests\Om;

use Golpilolz\CsvOm\Exception\ConversionException;
use Golpilolz\CsvOm\Exception\MappingException;
use Golpilolz\CsvOm\Exception\QueryException;
use Golpilolz\CsvOm\Exception\StorageException;
use Golpilolz\CsvOm\Om\ObjectManager;
use Golpilolz\CsvOm\Om\Metadata\MetadataFactory;
use Golpilolz\CsvOm\Om\Repository\CSVRepository;
use Golpilolz\CsvOm\Storage\CsvStorage;
use Golpilolz\CsvOm\Tests\CsvDirectoryTrait;
use Golpilolz\CsvOm\Tests\Fixtures\Entity\Author;
use Golpilolz\CsvOm\Tests\Fixtures\Entity\Book;
use Golpilolz\CsvOm\Tests\Fixtures\Entity\BookRepository;
use Golpilolz\CsvOm\Tests\Fixtures\Entity\BookStatus;
use Golpilolz\CsvOm\Tests\Fixtures\Entity\Priority;
use Golpilolz\CsvOm\Tests\Fixtures\Entity\ProductCategory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ObjectManagerTest extends TestCase
{
    use CsvDirectoryTrait;

    // Book: ";" delimiter, "'" enclosure, unmapped "isbn" column, id "007" stored with leading zeros.
    private const string BOOKS = <<<CSV
        id;book_title;isbn;price;available;published_at;status;priority;notes;legacyCode
        10;Dune;978-0441;9.99;1;1965-08-01T00:00:00+00:00;published;2;;1
        9;Hyperion;978-0553;;0;;draft;;'a; note';2
        007;Foundation;978-0553;7.5;1;1951-06-01T00:00:00+00:00;published;1;;3
        1;Neuromancer;;12;1;1984-07-01T00:00:00+00:00;published;;;4

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

    /** @param list<object> $books */
    private static function titles(array $books): array
    {
        return array_map(static fn (Book $b) => (new \ReflectionProperty($b, 'title'))->getValue($b), $books);
    }

    public function testClassMetadataComesFromFactory(): void
    {
        $factory = new MetadataFactory();
        $om = new ObjectManager(new CsvStorage($this->dir), $factory);

        self::assertSame($factory->getMetadataFor(Book::class), $om->getClassMetadata(Book::class));
    }

    public function testRepositoryIsBuiltFromMetadataAndCached(): void
    {
        $default = $this->om->getRepository(ProductCategory::class);
        self::assertInstanceOf(CSVRepository::class, $default);
        self::assertSame(ProductCategory::class, $default->getClassName());
        self::assertSame($default, $this->om->getRepository(ProductCategory::class));

        $custom = $this->om->getRepository(Book::class);
        self::assertInstanceOf(BookRepository::class, $custom);
        self::assertSame(['Neuromancer', 'Foundation', 'Dune'], self::titles($custom->findPublished()), 'custom finder, ordered by id');
    }

    public function testNonEntityIsRejected(): void
    {
        $this->expectException(MappingException::class);
        $this->om->getRepository(\stdClass::class);
    }

    public function testFindHydratesRow(): void
    {
        $book = $this->om->find(Book::class, 10);

        self::assertInstanceOf(Book::class, $book);
        self::assertSame(['Dune'], self::titles([$book]));
        self::assertSame(BookStatus::Published, (new \ReflectionProperty($book, 'status'))->getValue($book));
        self::assertSame(Priority::High, (new \ReflectionProperty($book, 'priority'))->getValue($book));
    }

    public function testFindUsesIdentityMapAndNormalizesIds(): void
    {
        $book = $this->om->find(Book::class, 7);

        self::assertNotNull($book);
        self::assertSame($book, $this->om->find(Book::class, '7'));
        self::assertSame($book, $this->om->find(Book::class, '007'));
        self::assertSame($book, $this->om->getRepository(Book::class)->find(7));
        self::assertSame($book, $this->om->getRepository(Book::class)->findOneBy(['id' => 7]));
    }

    public function testFindReturnsSameInstanceAsFindBy(): void
    {
        $all = $this->om->getRepository(Book::class)->findAll();

        self::assertSame($all[0], $this->om->find(Book::class, 10));
    }

    #[DataProvider('provideUnknownIds')]
    public function testFindUnknownIdReturnsNull(mixed $id): void
    {
        self::assertNull($this->om->find(Book::class, $id));
    }

    public static function provideUnknownIds(): iterable
    {
        yield 'absent' => [42];
        yield 'not an int' => ['abc'];
        yield 'empty string' => [''];
    }

    public function testFindRejectsNonScalarId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a scalar, array given');

        $this->om->find(Book::class, [1]);
    }

    public function testFindAllKeepsFileOrder(): void
    {
        self::assertSame(['Dune', 'Hyperion', 'Foundation', 'Neuromancer'], self::titles($this->om->getRepository(Book::class)->findAll()));
    }

    #[DataProvider('provideCriteria')]
    public function testFindByAndCount(array $criteria, array $expectedTitles): void
    {
        $repository = $this->om->getRepository(Book::class);

        self::assertSame($expectedTitles, self::titles($repository->findBy($criteria)));
        self::assertSame(count($expectedTitles), $repository->count($criteria));
    }

    public static function provideCriteria(): iterable
    {
        yield 'none' => [[], ['Dune', 'Hyperion', 'Foundation', 'Neuromancer']];
        yield 'string' => [['title' => 'Hyperion'], ['Hyperion']];
        yield 'enum' => [['status' => BookStatus::Draft], ['Hyperion']];
        yield 'enum backing value' => [['status' => 'draft'], ['Hyperion']];
        yield 'int enum' => [['priority' => Priority::Low], ['Foundation']];
        yield 'int as string' => [['id' => '9'], ['Hyperion']];
        yield 'int with leading zeros in file' => [['id' => 7], ['Foundation']];
        yield 'float' => [['price' => 12], ['Neuromancer']];
        yield 'bool' => [['available' => false], ['Hyperion']];
        yield 'bool as string' => [['available' => 'true'], ['Dune', 'Foundation', 'Neuromancer']];
        yield 'datetime' => [['publishedAt' => new \DateTimeImmutable('1965-08-01T00:00:00+00:00')], ['Dune']];
        yield 'null' => [['price' => null], ['Hyperion']];
        yield 'nullable enum null' => [['priority' => null], ['Hyperion', 'Neuromancer']];
        yield 'in list' => [['id' => [1, 10, 999]], ['Dune', 'Neuromancer']];
        yield 'in list with null' => [['priority' => [null, Priority::High]], ['Dune', 'Hyperion', 'Neuromancer']];
        yield 'empty list matches nothing' => [['id' => []], []];
        yield 'several criteria are ANDed' => [['status' => BookStatus::Published, 'priority' => null], ['Neuromancer']];
        yield 'no match' => [['title' => 'dune'], []];
    }

    #[DataProvider('provideOrders')]
    public function testFindByOrder(array $orderBy, array $expectedTitles): void
    {
        self::assertSame($expectedTitles, self::titles($this->om->getRepository(Book::class)->findBy([], $orderBy)));
    }

    public static function provideOrders(): iterable
    {
        yield 'int is numeric, not lexicographic' => [['id' => 'ASC'], ['Neuromancer', 'Foundation', 'Hyperion', 'Dune']];
        yield 'desc, case-insensitive direction' => [['id' => 'desc'], ['Dune', 'Hyperion', 'Foundation', 'Neuromancer']];
        yield 'nulls first ascending' => [['price' => 'ASC'], ['Hyperion', 'Foundation', 'Dune', 'Neuromancer']];
        yield 'nulls last descending' => [['price' => 'DESC'], ['Neuromancer', 'Dune', 'Foundation', 'Hyperion']];
        yield 'dates' => [['publishedAt' => 'ASC'], ['Hyperion', 'Foundation', 'Dune', 'Neuromancer']];
        yield 'enum then id, stable' => [['status' => 'ASC', 'id' => 'DESC'], ['Hyperion', 'Dune', 'Foundation', 'Neuromancer']];
        yield 'equal keys keep file order' => [['status' => 'DESC'], ['Dune', 'Foundation', 'Neuromancer', 'Hyperion']];
    }

    public function testLimitOffsetAndFindOneBy(): void
    {
        $repository = $this->om->getRepository(Book::class);

        self::assertSame(['Foundation', 'Hyperion'], self::titles($repository->findBy([], ['id' => 'ASC'], 2, 1)));
        self::assertSame(['Dune'], self::titles($repository->findBy([], ['id' => 'ASC'], null, 3)));
        self::assertSame([], $repository->findBy([], null, 0));
        self::assertSame(['Neuromancer'], self::titles([$repository->findOneBy(['status' => BookStatus::Published], ['id' => 'ASC'])]));
        self::assertNull($repository->findOneBy(['title' => 'none']));
    }

    #[DataProvider('provideInvalidQueries')]
    public function testInvalidQueries(array $criteria, ?array $orderBy, ?int $limit, ?int $offset, string $messagePart): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage($messagePart);

        $this->om->getRepository(Book::class)->findBy($criteria, $orderBy, $limit, $offset);
    }

    public static function provideInvalidQueries(): iterable
    {
        yield 'column name instead of field' => [['book_title' => 'Dune'], null, null, null, 'Unknown field "book_title"'];
        yield 'unknown order field' => [[], ['nope' => 'ASC'], null, null, 'Unknown field "nope"'];
        yield 'bad direction' => [[], ['id' => 'UP'], null, null, 'Invalid order direction "UP"'];
        yield 'bad criterion value' => [['id' => 'abc'], null, null, null, 'Invalid value for field "id"'];
        yield 'bad enum case' => [['status' => 'archived'], null, null, null, 'Invalid value for field "status"'];
        yield 'negative limit' => [[], null, -1, null, '"limit" must be a positive integer'];
        yield 'negative offset' => [[], null, null, -1, '"offset" must be a positive integer'];
    }

    public function testUnmappedColumnsAreKeptInOriginalRow(): void
    {
        $uow = $this->om->getUnitOfWork();
        $metadata = $this->om->getClassMetadata(Book::class);

        self::assertSame(['isbn'], $uow->getTable($metadata)->extraColumns);
        self::assertSame('978-0441', $uow->getOriginalRow($metadata, 10)['isbn']);
        self::assertSame('007', $uow->getOriginalRow($metadata, 7)['id'], 'raw cells are kept as read');
        self::assertNull($uow->getOriginalRow($metadata, 42));
    }

    public function testMissingFileIsAnEmptyTable(): void
    {
        $repository = $this->om->getRepository(Author::class);

        self::assertSame([], $repository->findAll());
        self::assertSame(0, $repository->count());
        self::assertNull($this->om->find(Author::class, 1));
        self::assertSame(
            $this->om->getClassMetadata(Author::class)->getColumnNames(),
            $this->om->getUnitOfWork()->getTable($this->om->getClassMetadata(Author::class))->header,
        );
    }

    public function testMissingBaseDirectoryThrows(): void
    {
        $om = new ObjectManager(new CsvStorage($this->dir . '/missing'));

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('does not exist');

        $om->find(Book::class, 1);
    }

    public function testFileMissingAMappedColumnThrows(): void
    {
        $this->putCsv('author.csv', "id,name\n1,Ursula\n");

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('is missing mapped columns: birth_date, priority');

        $this->om->find(Author::class, 1);
    }

    public function testDuplicateIdThrows(): void
    {
        $this->putCsv('author.csv', "id,name,birth_date,priority\n1,a,,\n01,b,,\n");

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('has duplicate id "1" (data row 2)');

        $this->om->getRepository(Author::class)->findAll();
    }

    public function testInvalidValueAnywhereInFileFailsOnFirstUse(): void
    {
        $this->putCsv('author.csv', "id,name,birth_date,priority\n1,a,,\n2,b,21/10/1929,\n");

        $this->expectException(ConversionException::class);
        $this->expectExceptionMessage('to a date with format "Y-m-d". (entity "' . Author::class . '", id "2")');

        // Row 1 is valid, but the whole file is converted when it is loaded.
        $this->om->find(Author::class, 1);
    }

    public function testFileIsReadOnce(): void
    {
        $this->putCsv('author.csv', "id,name,birth_date,priority\n1,a,,\n");
        $repository = $this->om->getRepository(Author::class);
        self::assertSame(1, $repository->count());

        $this->putCsv('author.csv', "id,name,birth_date,priority\n1,a,,\n2,b,,\n");

        self::assertSame(1, $repository->count(), 'the table is cached for the lifetime of the object manager');
    }
}
