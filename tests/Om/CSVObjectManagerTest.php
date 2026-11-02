<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Tests\Om;

use Golpilolz\CsvOm\Exception\MappingException;
use Golpilolz\CsvOm\Om\CSVObjectManager;
use Golpilolz\CsvOm\Om\Metadata\MetadataFactory;
use Golpilolz\CsvOm\Om\Repository\CSVRepository;
use Golpilolz\CsvOm\Tests\Fixtures\Entity\Book;
use Golpilolz\CsvOm\Tests\Fixtures\Entity\BookRepository;
use Golpilolz\CsvOm\Tests\Fixtures\Entity\ProductCategory;
use PHPUnit\Framework\TestCase;

final class CSVObjectManagerTest extends TestCase
{
    public function testClassMetadataComesFromFactory(): void
    {
        $factory = new MetadataFactory();
        $om = new CSVObjectManager(metadataFactory: $factory);

        self::assertSame($factory->getMetadataFor(Book::class), $om->getClassMetadata(Book::class));
    }

    public function testRepositoryIsBuiltFromMetadataAndCached(): void
    {
        $om = new CSVObjectManager();

        $default = $om->getRepository(ProductCategory::class);
        self::assertInstanceOf(CSVRepository::class, $default);
        self::assertSame(ProductCategory::class, $default->className);
        self::assertSame('product_category.csv', $default->prefix);
        self::assertSame($default, $om->getRepository(ProductCategory::class));

        self::assertInstanceOf(BookRepository::class, $om->getRepository(Book::class));
    }

    public function testNonEntityIsRejected(): void
    {
        $this->expectException(MappingException::class);
        (new CSVObjectManager())->getRepository(\stdClass::class);
    }
}
