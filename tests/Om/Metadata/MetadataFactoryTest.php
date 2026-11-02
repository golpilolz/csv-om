<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Tests\Om\Metadata;

use Golpilolz\CsvOm\Exception\MappingException;
use Golpilolz\CsvOm\Om\Mapping as CsvOm;
use Golpilolz\CsvOm\Om\Mapping\Types;
use Golpilolz\CsvOm\Om\Metadata\MetadataFactory;
use Golpilolz\CsvOm\Om\Repository\CSVRepository;
use Golpilolz\CsvOm\Tests\Fixtures\Entity\Book;
use Golpilolz\CsvOm\Tests\Fixtures\Entity\BookRepository;
use Golpilolz\CsvOm\Tests\Fixtures\Entity\BookStatus;
use Golpilolz\CsvOm\Tests\Fixtures\Entity\Priority;
use Golpilolz\CsvOm\Tests\Fixtures\Entity\ProductCategory;
use Golpilolz\CsvOm\Tests\Fixtures\Entity\PureEnum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MetadataFactoryTest extends TestCase
{
    private MetadataFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new MetadataFactory();
    }

    public function testEntityOptions(): void
    {
        $metadata = $this->factory->getMetadataFor(Book::class);

        self::assertSame(Book::class, $metadata->className);
        self::assertSame('library/books.csv', $metadata->file);
        self::assertSame(BookRepository::class, $metadata->repositoryClass);
        self::assertSame(';', $metadata->delimiter);
        self::assertSame("'", $metadata->enclosure);
        self::assertSame('id', $metadata->idField);
        self::assertSame('id', $metadata->getIdColumn());
    }

    public function testFieldMappings(): void
    {
        $metadata = $this->factory->getMetadataFor(Book::class);

        // Declaration order is kept; unmapped properties are ignored.
        self::assertSame(
            ['id', 'book_title', 'price', 'available', 'published_at', 'status', 'priority', 'notes', 'legacyCode'],
            $metadata->getColumnNames(),
        );
        self::assertFalse($metadata->hasField('computed'));

        $expected = [
            // field => [column, type, nullable, enumType]
            'id' => ['id', Types::INT, false, null],
            'title' => ['book_title', Types::STRING, false, null],
            'price' => ['price', Types::FLOAT, true, null],
            'available' => ['available', Types::BOOL, false, null],
            'publishedAt' => ['published_at', Types::DATETIME_IMMUTABLE, true, null],
            'status' => ['status', Types::STRING, false, BookStatus::class],
            'priority' => ['priority', Types::INT, true, Priority::class],
            'notes' => ['notes', Types::STRING, true, null],
            'legacyCode' => ['legacyCode', Types::INT, false, null],
        ];
        foreach ($expected as $field => [$column, $type, $nullable, $enumType]) {
            $mapping = $metadata->getFieldMapping($field);
            self::assertSame($field, $mapping->fieldName);
            self::assertSame($column, $mapping->columnName, $field);
            self::assertSame($type, $mapping->type, $field);
            self::assertSame($nullable, $mapping->nullable, $field);
            self::assertSame($enumType, $mapping->enumType, $field);
        }

        self::assertSame(\DateTimeInterface::ATOM, $metadata->getFieldMapping('publishedAt')->format);
        self::assertNull($metadata->getFieldMapping('title')->format);

        self::assertSame('title', $metadata->getFieldForColumn('book_title'));
        self::assertNull($metadata->getFieldForColumn('title'));
    }

    public function testDefaults(): void
    {
        $metadata = $this->factory->getMetadataFor(ProductCategory::class);

        self::assertSame('product_category.csv', $metadata->file);
        self::assertSame(CSVRepository::class, $metadata->repositoryClass);
        self::assertNull($metadata->delimiter);
        self::assertNull($metadata->enclosure);
        self::assertSame('code', $metadata->getIdColumn());
        self::assertSame(Types::STRING, $metadata->getIdMapping()->type);
    }

    public function testMetadataIsCached(): void
    {
        self::assertSame(
            $this->factory->getMetadataFor(Book::class),
            $this->factory->getMetadataFor('\\' . Book::class),
        );
    }

    public function testUnknownFieldThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->factory->getMetadataFor(Book::class)->getFieldMapping('nope');
    }

    public function testIsEntity(): void
    {
        self::assertTrue($this->factory->isEntity(Book::class));
        self::assertFalse($this->factory->isEntity(\stdClass::class));
        self::assertFalse($this->factory->isEntity('App\\DoesNotExist'));
    }

    #[DataProvider('provideDefaultFileNames')]
    public function testDefaultFileName(string $shortName, string $expected): void
    {
        self::assertSame($expected, MetadataFactory::defaultFileName($shortName));
    }

    public static function provideDefaultFileNames(): iterable
    {
        yield ['Test', 'test.csv'];
        yield ['ProductCategory', 'product_category.csv'];
        yield ['HTMLPage', 'html_page.csv'];
        yield ['Address2Line', 'address2_line.csv'];
    }

    #[DataProvider('provideInvalidMappings')]
    public function testInvalidMapping(string $className, string $messagePart): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage($messagePart);

        $this->factory->getMetadataFor($className);
    }

    public static function provideInvalidMappings(): iterable
    {
        yield 'missing class' => ['App\\DoesNotExist', 'does not exist'];
        yield 'no entity attribute' => [\stdClass::class, 'is not a CSV entity'];
        yield 'enum' => [BookStatus::class, 'is not a CSV entity'];

        yield 'no id' => [(new #[CsvOm\Entity] class {
            #[CsvOm\Column] public string $name;
        })::class, 'has no identifier'];

        yield 'two ids' => [(new #[CsvOm\Entity] class {
            #[CsvOm\Id] public int $a;
            #[CsvOm\Id] public int $b;
        })::class, 'several identifiers (a, b)'];

        yield 'float id' => [(new #[CsvOm\Entity] class {
            #[CsvOm\Id] public float $id;
        })::class, 'must be of type "int" or "string", "float" given'];

        yield 'nullable id' => [(new #[CsvOm\Entity] class {
            #[CsvOm\Id] public ?int $id;
        })::class, 'cannot be nullable'];

        yield 'static property' => [(new #[CsvOm\Entity] class {
            #[CsvOm\Id] public int $id;
            #[CsvOm\Column] public static string $shared;
        })::class, 'Static property'];

        yield 'unknown explicit type' => [(new #[CsvOm\Entity] class {
            #[CsvOm\Id] public int $id;
            #[CsvOm\Column(type: 'json')] public string $data;
        })::class, 'Unknown type "json"'];

        yield 'uninferable type' => [(new #[CsvOm\Entity] class {
            #[CsvOm\Id] public int $id;
            #[CsvOm\Column] public array $tags;
        })::class, 'Cannot infer a column type from "array"'];

        yield 'union without explicit type' => [(new #[CsvOm\Entity] class {
            #[CsvOm\Id] public int $id;
            #[CsvOm\Column] public int|string $code;
        })::class, 'Cannot infer a column type'];

        yield 'pure enum' => [(new #[CsvOm\Entity] class {
            #[CsvOm\Id] public int $id;
            #[CsvOm\Column] public PureEnum $kind;
        })::class, 'pure enum'];

        yield 'enum type mismatch' => [(new #[CsvOm\Entity] class {
            #[CsvOm\Id] public int $id;
            #[CsvOm\Column(type: 'int')] public BookStatus $status;
        })::class, 'backed enum of type "string"'];

        yield 'nullable mismatch' => [(new #[CsvOm\Entity] class {
            #[CsvOm\Id] public int $id;
            #[CsvOm\Column(nullable: true)] public string $name;
        })::class, 'does not allow null'];

        yield 'format on non date column' => [(new #[CsvOm\Entity] class {
            #[CsvOm\Id] public int $id;
            #[CsvOm\Column(format: 'Y-m-d')] public string $day;
        })::class, 'Option "format" on'];

        yield 'duplicate column' => [(new #[CsvOm\Entity] class {
            #[CsvOm\Id] public int $id;
            #[CsvOm\Column(name: 'id')] public string $other;
        })::class, 'Column "id" is mapped more than once'];

        yield 'invalid repository' => [(new #[CsvOm\Entity(repositoryClass: \stdClass::class)] class {
            #[CsvOm\Id] public int $id;
        })::class, 'must exist and implement RepositoryInterface'];

        yield 'invalid delimiter' => [(new #[CsvOm\Entity(delimiter: ';;')] class {
            #[CsvOm\Id] public int $id;
        })::class, 'Option "delimiter"'];

        yield 'absolute file' => [(new #[CsvOm\Entity(file: '/etc/passwd')] class {
            #[CsvOm\Id] public int $id;
        })::class, 'must be a relative path'];

        yield 'parent directory file' => [(new #[CsvOm\Entity(file: 'data/../../secret.csv')] class {
            #[CsvOm\Id] public int $id;
        })::class, 'must be a relative path'];
    }

    public function testAbstractClassIsRejected(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('must be a concrete class');

        $this->factory->getMetadataFor(AbstractEntity::class);
    }
}

#[CsvOm\Entity]
abstract class AbstractEntity
{
    #[CsvOm\Id]
    public int $id;
}
