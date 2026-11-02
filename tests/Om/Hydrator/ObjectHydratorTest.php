<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Tests\Om\Hydrator;

use Golpilolz\CsvOm\Exception\ConversionException;
use Golpilolz\CsvOm\Exception\HydrationException;
use Golpilolz\CsvOm\Om\Hydrator\ObjectHydrator;
use Golpilolz\CsvOm\Om\Metadata\MetadataFactory;
use Golpilolz\CsvOm\Tests\Fixtures\Entity\Author;
use Golpilolz\CsvOm\Tests\Fixtures\Entity\Book;
use Golpilolz\CsvOm\Tests\Fixtures\Entity\BookStatus;
use Golpilolz\CsvOm\Tests\Fixtures\Entity\Priority;
use Golpilolz\CsvOm\Tests\Fixtures\Entity\ProductCategory;
use PHPUnit\Framework\TestCase;

final class ObjectHydratorTest extends TestCase
{
    private MetadataFactory $metadata;
    private ObjectHydrator $hydrator;

    private const array BOOK_ROW = [
        'id' => '7',
        'book_title' => 'Dune',
        'price' => '9.99',
        'available' => '0',
        'published_at' => '1965-08-01T00:00:00+00:00',
        'status' => 'published',
        'priority' => '',
        'notes' => '',
        'legacyCode' => '12',
    ];

    protected function setUp(): void
    {
        $this->metadata = new MetadataFactory();
        $this->hydrator = new ObjectHydrator();
    }

    private static function read(object $object, string $field): mixed
    {
        return (new \ReflectionProperty($object, $field))->getValue($object);
    }

    public function testHydrateSetsPrivatePropertiesWithoutCallingConstructor(): void
    {
        // Book has a private constructor: it can only be built by the hydrator.
        $book = $this->hydrator->hydrate($this->metadata->getMetadataFor(Book::class), self::BOOK_ROW + ['unmapped' => 'ignored']);

        self::assertInstanceOf(Book::class, $book);
        self::assertSame(7, self::read($book, 'id'));
        self::assertSame('Dune', self::read($book, 'title'));
        self::assertSame(9.99, self::read($book, 'price'));
        self::assertFalse(self::read($book, 'available'));
        self::assertSame('1965-08-01', self::read($book, 'publishedAt')->format('Y-m-d'));
        self::assertSame(BookStatus::Published, self::read($book, 'status'));
        self::assertNull(self::read($book, 'priority'));
        self::assertNull($book->notes);
        self::assertSame(12, $book->legacyCode);
        self::assertSame('', $book->computed, 'unmapped property keeps its default');
    }

    public function testHydrateReadonlyAndInheritedProperties(): void
    {
        $author = $this->hydrator->hydrate(
            $this->metadata->getMetadataFor(Author::class),
            ['id' => '3', 'name' => 'Ursula', 'birth_date' => '1929-10-21', 'priority' => '2'],
        );

        self::assertSame(3, $author->id);
        self::assertSame('Ursula', $author->getName());
        self::assertSame('1929-10-21 00:00:00', $author->birthDate->format('Y-m-d H:i:s'));
        self::assertSame(Priority::High, $author->priority);
        self::assertFalse($author->constructed, 'constructor is not called');
    }

    public function testExtractReturnsRowInHeaderOrder(): void
    {
        $metadata = $this->metadata->getMetadataFor(Book::class);
        $book = $this->hydrator->hydrate($metadata, self::BOOK_ROW);

        $row = $this->hydrator->extract($metadata, $book);

        self::assertSame($metadata->getColumnNames(), array_keys($row));
        self::assertSame([
            'id' => '7',
            'book_title' => 'Dune',
            'price' => '9.99',
            'available' => '0',
            'published_at' => '1965-08-01T00:00:00+00:00',
            'status' => 'published',
            'priority' => null,
            'notes' => null,
            'legacyCode' => '12',
        ], $row);
    }

    public function testExtractObjectBuiltByConstructor(): void
    {
        $metadata = $this->metadata->getMetadataFor(Author::class);
        $author = new Author(5, 'Iain', new \DateTimeImmutable('1954-02-16 13:45'));
        $author->priority = Priority::Low;

        // Own properties come before inherited ones (reflection order), so "name" is last.
        self::assertSame(
            ['id' => '5', 'birth_date' => '1954-02-16', 'priority' => '1', 'name' => 'Iain'],
            $this->hydrator->extract($metadata, $author),
        );
        self::assertSame('5', $this->hydrator->extractId($metadata, $author));
    }

    public function testExtractUninitializedProperties(): void
    {
        $metadata = $this->metadata->getMetadataFor(ProductCategory::class);
        $category = new ProductCategory();

        self::assertNull($this->hydrator->extractId($metadata, $category));

        $this->expectException(HydrationException::class);
        $this->expectExceptionMessage('ProductCategory::$id" is not nullable and must be initialized');
        $this->hydrator->extract($metadata, $category);
    }

    public function testUninitializedNullablePropertyIsWrittenAsNull(): void
    {
        $metadata = $this->metadata->getMetadataFor(Author::class);
        $author = (new \ReflectionClass(Author::class))->newInstanceWithoutConstructor();
        $set = \Closure::bind(static function (Author $a): void {
            $a->id = 1;
            $a->name = 'x';
        }, null, Author::class);
        $set($author);

        self::assertSame(['id' => '1', 'birth_date' => null, 'priority' => null, 'name' => 'x'], $this->hydrator->extract($metadata, $author));
    }

    public function testExtractRejectsWrongClass(): void
    {
        $this->expectException(HydrationException::class);
        $this->expectExceptionMessage('Expected an instance of "' . Book::class . '"');

        $this->hydrator->extract($this->metadata->getMetadataFor(Book::class), new ProductCategory());
    }

    public function testMissingColumnThrows(): void
    {
        $row = self::BOOK_ROW;
        unset($row['price']);

        $this->expectException(HydrationException::class);
        $this->expectExceptionMessage('Column "price" mapped by entity "' . Book::class . '" is missing from the row (id "7")');

        $this->hydrator->hydrate($this->metadata->getMetadataFor(Book::class), $row);
    }

    public function testConversionErrorNamesEntityAndId(): void
    {
        $this->expectException(ConversionException::class);
        $this->expectExceptionMessage('Cannot convert value "cheap" of field "price" to float. (entity "' . Book::class . '", id "7")');

        $this->hydrator->hydrate($this->metadata->getMetadataFor(Book::class), ['price' => 'cheap'] + self::BOOK_ROW);
    }

    public function testWriteConversionErrorNamesEntityAndId(): void
    {
        $metadata = $this->metadata->getMetadataFor(Book::class);
        $book = $this->hydrator->hydrate($metadata, self::BOOK_ROW);
        $book->legacyCode = 'not-an-int';

        $this->expectException(ConversionException::class);
        $this->expectExceptionMessage('field "legacyCode": expected int, string given. (entity "' . Book::class . '", id "7")');

        $this->hydrator->extract($metadata, $book);
    }

    public function testRoundTripThroughRowIsLossless(): void
    {
        $metadata = $this->metadata->getMetadataFor(Book::class);
        $row = $this->hydrator->extract($metadata, $this->hydrator->hydrate($metadata, self::BOOK_ROW));
        $again = $this->hydrator->extract($metadata, $this->hydrator->hydrate($metadata, array_map(static fn (?string $v) => $v ?? '', $row)));

        self::assertSame($row, $again);
    }
}
