# Cookbook

Worked examples for common tasks. The [documentation](../README.md#documentation) is the reference; this page shows how the
pieces fit together. All examples use these entities and this data:

```php
use Golpilolz\CsvOm\Om\Mapping as CsvOm;

enum BookStatus: string { case Draft = 'draft'; case Published = 'published'; }

#[CsvOm\Entity(file: 'library/books.csv', repositoryClass: BookRepository::class)]
class Book
{
    #[CsvOm\Id]
    private int $id;
    #[CsvOm\Column(name: 'book_title')]
    private string $title;
    #[CsvOm\Column]
    private ?float $price = null;
    #[CsvOm\Column(name: 'published_at', format: 'Y-m-d')]
    private ?\DateTimeImmutable $publishedAt = null;
    #[CsvOm\Column]
    private BookStatus $status = BookStatus::Draft;

    public function __construct(int $id, string $title) { $this->id = $id; $this->title = $title; }
    // getters / setters omitted
}
```

```csv
id,book_title,isbn,price,published_at,status
7,Foundation,978-0553,7.5,1951-06-01,published
8,Dune,978-0441,,,draft
```

## Create, update, delete in one request

```php
public function __invoke(ObjectManagerInterface $om): Response
{
    $book = $om->find(Book::class, 7);
    $book->setPrice(8.0);                       // tracked automatically, no persist()

    $om->persist(new Book(9, 'Hyperion'));      // the id is yours to choose
    $om->remove($om->find(Book::class, 8));

    $om->flush();                               // one atomic rewrite of library/books.csv
    // ...
}
```

Generate ids yourself, for instance from the repository:

```php
$nextId = max(array_map(fn (Book $b) => $b->getId(), $repository->findAll()) ?: [0]) + 1;
```

This is not safe if two requests create rows at the same time; see
[Concurrency](#concurrency). Prefer a UUID string id when several writers are possible.

## Look up with several criteria

```php
$repository = $om->getRepository(Book::class);

// published AND (price unset OR price in the list)
$repository->findBy(['status' => BookStatus::Published, 'price' => [null, 7.5, 9.99]]);

// the 10 most recent, second page
$repository->findBy([], ['publishedAt' => 'DESC'], limit: 10, offset: 10);

$repository->count(['status' => 'draft']);      // string form of the enum is accepted too
```

Criteria use **property names** (`title`), not column names (`book_title`). For anything a
criterion can't express (ranges, text search), filter in PHP:

```php
$cheap = array_filter($repository->findAll(), fn (Book $b) => $b->getPrice() !== null && $b->getPrice() < 5);
```

## Put queries in a custom repository

```php
final class BookRepository extends CSVRepository
{
    public function __construct(ObjectManagerInterface $objectManager)
    {
        parent::__construct($objectManager, Book::class);
    }

    /** @return list<Book> */
    public function findPublishedBefore(\DateTimeImmutable $date): array
    {
        return array_values(array_filter(
            $this->findBy(['status' => BookStatus::Published], ['publishedAt' => 'ASC']),
            fn (Book $b) => $b->getPublishedAt() < $date,
        ));
    }
}
```

Inject `BookRepository` directly in controllers and services; it is the same instance as
`$om->getRepository(Book::class)`.

## Map dates, enums and nullable values

| Goal | Mapping | Cell |
|---|---|---|
| Date only | `#[Column(format: 'Y-m-d')] ?\DateTimeImmutable` | `1951-06-01` |
| Full timestamp (default) | `#[Column] \DateTimeImmutable` | `1951-06-01T00:00:00+00:00` |
| European dates | `#[Column(format: 'd/m/Y')]` | `01/06/1951` |
| Enum | `#[Column] BookStatus` | `published` |
| Optional value | `?float` | empty cell = `null` |
| Union type | `#[Column(type: Types::INT)] int\|string` | required, types are not inferred |

Date parsing is strict: a cell that doesn't match `format` throws a `ConversionException`
naming the entity, id, field and value.

## Keep extra columns and column order

Only mapped columns are touched. `isbn` in the example survives every write even though `Book`
doesn't map it. To expose it later, add a `#[Column]` property; existing rows keep their value.

Written columns follow **property declaration order** (own properties before inherited ones), and
new files get that order as header. Reordering properties therefore changes the header of files
created from then on, but never reorders an existing file (columns are matched by name).

## Use a different directory or delimiter per entity

```yaml
# config/packages/golpilolz_csv_om.yaml
golpilolz_csv_om:
    directory: '%kernel.project_dir%/var/data'
    delimiter: ';'          # for every entity...
```

```php
#[CsvOm\Entity(file: 'exports/legacy.csv', delimiter: "\t")]   // ...unless overridden
```

`file` is always relative to `directory`; subdirectories are created on first write.

## Test code that uses the object manager

Build a real object manager on a temporary directory instead of mocking it:

```php
use Golpilolz\CsvOm\Om\ObjectManager;
use Golpilolz\CsvOm\Storage\CsvStorage;

final class BookServiceTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/csv-om-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/library', recursive: true);
        file_put_contents($this->dir . '/library/books.csv', "id,book_title,price,published_at,status\n7,Foundation,7.5,1951-06-01,published\n");
    }

    protected function tearDown(): void
    {
        // remove $this->dir recursively (data files and their .lock sidecars)
    }

    public function testPriceIsUpdated(): void
    {
        $om = new ObjectManager(new CsvStorage($this->dir));
        (new BookService($om))->discount(7);

        $om->clear();   // forget loaded files: the next read hits the disk
        self::assertSame(5.0, $om->find(Book::class, 7)->getPrice());
    }
}
```

The bundle's own `tests/CsvDirectoryTrait.php` and `tests/Fixtures/` show a fuller setup.

## Concurrency

Writes are atomic and serialized, but two processes that load then flush the same file each write
their own version: **the last one wins** and the first one's change is lost silently. Mitigations:

- keep one writer per file (a CLI import, a single admin user);
- re-read just before writing: `$om->clear(); $book = $om->find(...)` then change and `flush()` right away;
- use string ids (UUID) so concurrent inserts don't pick the same number.

## Troubleshooting

| Symptom | Likely cause |
|---|---|
| `StorageException` "directory does not exist" | create the `directory` (default `<project>/csv`); it is never created for you |
| `StorageException` on a missing column | the file header lacks a mapped column, or `name:` differs from the header |
| `ConversionException` at the first query | a bad cell **anywhere** in the file: the whole file is converted on first use |
| `PersistenceException` on `persist()` | id not set, or already in the file (even if never loaded) |
| Query doesn't see my unflushed change | by design: queries read the data as loaded, like Doctrine |
| `find('abc')` returns `null` | the value can't be an `int` id |
| Edits are not visible after changing the CSV by hand | the file is loaded once per object manager; call `clear()` or start a new request |
