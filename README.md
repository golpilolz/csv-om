# csv-om

[![Tests](https://github.com/golpilolz/csv-om/actions/workflows/tests.yml/badge.svg?branch=main)](https://github.com/golpilolz/csv-om/actions/workflows/tests.yml?query=branch%3Amain)

A Doctrine-style object manager for Symfony, backed by CSV files: one file is one table, one row is one
object. You map classes with attributes, then use `find()`, repositories, `persist()` and `flush()` as you
would with Doctrine ORM.

```php
$book = $om->find(Book::class, 7);
$book->setTitle('Foundation and Empire');

$om->persist(new Book(8, 'Second Foundation'));
$om->flush(); // rewrites library/books.csv
```

Requires PHP 8.3+, Symfony 7.4 or 8.x (Symfony 8 needs PHP 8.4+) and [league/csv](https://csv.thephpleague.com/) 9.28+.

## Installation

The package is not on Packagist. Add the repository, then require it:

```json
{
    "repositories": [
        { "type": "vcs", "url": "git@github.com:golpilolz/csv-om.git" }
    ]
}
```

```bash
composer require golpilolz/csv-om:dev-main
```

There is no Flex recipe. Register the bundle in `config/bundles.php`:

```php
return [
    // ...
    Golpilolz\CsvOm\GolpilolzCsvOmBundle::class => ['all' => true],
];
```

## Configuration

Every option is optional; these are the defaults:

```yaml
# config/packages/golpilolz_csv_om.yaml
golpilolz_csv_om:
  # Directory holding the CSV files. Entity "file" options are relative to it.
  # It must exist: a missing directory is reported as an error, not as empty tables.
  directory: '%kernel.project_dir%/csv'
  # Default field delimiter and enclosure (one character each), overridable per entity.
  delimiter: ','
  enclosure: '"'
```

## Mapping

```php
use Golpilolz\CsvOm\Om\Mapping as CsvOm;

#[CsvOm\Entity(file: 'library/books.csv', repositoryClass: BookRepository::class, delimiter: ';')]
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
    private BookStatus $status = BookStatus::Draft; // a backed enum

    public function __construct(int $id, string $title)
    {
        $this->id = $id;
        $this->title = $title;
    }

    // getters and setters omitted
}
```

`library/books.csv` then looks like this:

```csv
id;book_title;price;published_at;status
7;Foundation;7.5;1951-06-01;published
```

### `#[Entity]`

| Option | Default | Description |
|---|---|---|
| `file` | short class name in snake_case + `.csv` (`ProductCategory` → `product_category.csv`) | Path relative to the configured directory. Absolute paths and `..` are rejected. Missing subdirectories are created on write. |
| `repositoryClass` | `CSVRepository` | Must implement `RepositoryInterface`; see [Custom repositories](#custom-repositories). |
| `delimiter`, `enclosure` | bundle configuration | One character each. |

### `#[Id]`

Marks the identifier. Exactly one property per entity carries it. It must be a non-nullable `int` or
`string` (or a backed enum). `#[Id]` implies `#[Column]`, so add `#[Column]` only to change its options.

Ids are **assigned by the application**: there is no auto-increment. Set the id before `persist()`, and
don't change it once the object is managed.

### `#[Column]`

| Option | Default | Description |
|---|---|---|
| `name` | property name | Column name in the header. |
| `type` | inferred from the property type | One of the `Types` constants, see below. Required for union types. |
| `nullable` | inferred (`?Type`, untyped) | Whether an empty cell means `null`. `nullable: true` on a non-nullable property is a mapping error. |
| `format` | `DateTimeInterface::ATOM` | Date format, for date columns only. |

Properties without `#[Column]` or `#[Id]` are not mapped. Columns are written in property declaration order,
with the class's own properties before inherited ones. Static properties can't be mapped.

Objects are created without calling their constructor, so entities can have any constructor. Private,
protected, inherited and `readonly` properties are supported.

### Types

| Property type | `Types` constant | Read from the file | Written as |
|---|---|---|---|
| `string`, untyped | `STRING` | as is | as is (`int`, `float` and `Stringable` values are cast) |
| `int` | `INT` | optional sign, digits, leading zeros allowed (`007` → 7); overflow rejected | `-12` |
| `float` | `FLOAT` | numeric string without spaces (`1.5`, `1e3`); `INF`/`NAN` rejected | shortest exact form (`0.1`, `3.0`, `1.0E+25`) |
| `bool` | `BOOL` | `1`, `0`, `true`, `false` (any case) | `1` / `0` |
| `DateTimeImmutable`, `DateTimeInterface` | `DATETIME_IMMUTABLE` | strict parse with `format`; `tomorrow` or `2024-02-30` are rejected | `format` |
| backed enum | backing type | backing value, must be a case | backing value |

**Empty cells** are `null` for nullable fields, `''` for a non-nullable `string`, and an error for any other
non-nullable type. CSV can't tell an empty string from a missing value, so `null` is written as an empty cell.

A value that doesn't match its type throws a `ConversionException` naming the entity, id, field and value.
Nothing is coerced silently.

## Usage

Inject `Golpilolz\CsvOm\Om\ObjectManagerInterface`:

```php
use Golpilolz\CsvOm\Om\ObjectManagerInterface;

final class BookController extends AbstractController
{
    #[Route('/books/{id}')]
    public function show(ObjectManagerInterface $om, string $id): Response
    {
        $book = $om->find(Book::class, $id) ?? throw $this->createNotFoundException();
        // ...
    }
}
```

### Reading

```php
$om->find(Book::class, 7);   // null if absent
$repository = $om->getRepository(Book::class);

$repository->findAll();      // in file order
$repository->findBy(['status' => BookStatus::Published], ['publishedAt' => 'DESC'], limit: 10, offset: 20);
$repository->findOneBy(['title' => 'Dune']);
$repository->count(['price' => null]);
```

- **One object per id.** `find()` and every finder return the same instance for the same id. Ids are
  normalized, so `find(7)`, `find('7')` and a row stored as `007` all give the same object. A value that can't
  be an id (`find('abc')` for an `int` id) returns `null`.
- **Criteria use property names**, not column names. Values are typed (`BookStatus::Draft`, a `DateTimeImmutable`,
  `false`) or their string form (`'draft'`, `'7'`, `'true'`). A list means "one of" (`['id' => [1, 2]]`; an empty
  list matches nothing), `null` matches empty cells, and several criteria must all match. Unknown fields and
  values of the wrong type throw a `QueryException`.
- **Sorting** compares typed values: numbers numerically, dates chronologically, enums by backing value. `null`
  comes first in ascending order, and equal values keep file order.
- **Queries read the data as loaded from the file**, the way Doctrine queries the database: a change to an
  object that isn't flushed yet doesn't affect which objects match or their order.

### Writing

```php
$om->persist(new Book(8, 'Second Foundation')); // id must be set
$om->remove($om->find(Book::class, 9));
$book->setPrice(4.0);                            // managed objects are tracked, no persist() needed

$om->flush();
```

- `flush()` compares each managed object to its row and rewrites only the files that changed. **Unchanged rows
  are written back exactly as read** (`007` stays `007`); changed rows get their mapped cells in canonical form.
- **Columns that aren't mapped are kept.** Extra columns in a file survive every write; new rows get empty cells
  for them.
- New rows are appended in `persist()` order. If the file doesn't exist, it is created with the mapped columns as
  header.
- `persist()` throws a `PersistenceException` when the id is not set, or when it is already managed or present
  in the file, even if that row was never loaded. To reuse the id of a removed entity, flush the removal first.
- `remove()` on a new, unflushed object cancels its insertion; `persist()` on a removed object cancels the removal.
- `contains($object)` tells whether an object is managed and not scheduled for removal.
- `clear()` detaches every object, discards pending changes and forgets loaded files: the next read sees the files
  as they are now.

## Custom repositories

Extend `CSVRepository` and point the entity at it with `repositoryClass`. Give it a constructor that passes the
entity class, like Doctrine's `ServiceEntityRepository`, and it becomes an autowirable service:

```php
use Golpilolz\CsvOm\Om\ObjectManagerInterface;
use Golpilolz\CsvOm\Om\Repository\CSVRepository;

/** @extends CSVRepository<Book> */
final class BookRepository extends CSVRepository
{
    public function __construct(ObjectManagerInterface $objectManager)
    {
        parent::__construct($objectManager, Book::class);
    }

    /** @return list<Book> */
    public function findPublished(): array
    {
        return $this->findBy(['status' => BookStatus::Published], ['publishedAt' => 'DESC']);
    }
}
```

Repositories registered as services (the default for classes under `src/` with autoconfiguration) are tagged
automatically, and `getRepository(Book::class)` returns the same instance that autowiring injects. A repository
service that passes another entity class to its parent constructor is a `MappingException`. A repository class
that isn't a service is instantiated with `new BookRepository($objectManager, Book::class)`.

## File format

- The first record is the header. Columns are matched by name, so their order in the file doesn't matter.
  A file missing a mapped column is an error.
- Quoting follows RFC 4180: fields containing the delimiter, the enclosure, spaces or line breaks are enclosed,
  and enclosures inside a field are doubled. There is no backslash escaping.
- A UTF-8 BOM and blank lines are skipped. Line endings are read as `\n` or `\r\n` and written as `\n`.
- Files are read as bytes: no encoding conversion is done, use UTF-8.
- A row with more or fewer fields than the header, a duplicate header column, or a duplicate id is an error.

Reads are strict on purpose: the whole file is converted when its entity is first used, so a bad value anywhere
in it is reported with its row id right away instead of being guessed at.

## Errors

All exceptions are in `Golpilolz\CsvOm\Exception`:

| Exception | Base class | When |
|---|---|---|
| `MappingException` | `LogicException` | invalid attributes, non-entity class, repository/entity mismatch |
| `StorageException` | `RuntimeException` | missing directory, unreadable or malformed file, missing mapped column, duplicate id, failed write |
| `ConversionException` | `RuntimeException` | a cell or property value that doesn't match its type |
| `HydrationException` | `RuntimeException` | uninitialized non-nullable property on write, wrong object class |
| `QueryException` | `InvalidArgumentException` | unknown field, invalid criterion, order direction, limit or offset |
| `PersistenceException` | `RuntimeException` | missing or duplicate id on `persist()`, unmanaged object on `remove()`, id changed |

## Limitations

- **Whole files in memory.** CSV has no indexes: a file is loaded completely, once per object manager (per
  request), along with its converted values. It suits small to medium data sets.
- **No protection against lost updates between processes.** Writes are atomic (temporary file, `fsync`, then
  `rename()`, so readers never see a partial file), and concurrent writers are serialized with `flock()` on a
  `.<file>.lock` file kept next to each data file. But two processes that both load and then flush the same file
  each write their own version, and the last one wins.
- **`flush()` is not a transaction across files.** All changes are validated before any file is written, so a
  bad value writes nothing. If a write fails on disk, files already written keep their changes and the rest stay
  pending for the next `flush()`.
- **Not supported:** associations, lazy loading, lifecycle events and listeners, generated ids, composite ids,
  query language or query builder, schema tooling.

## Deprecations

- `Golpilolz\CsvOm\Om\CSVObjectManagerInterface` (interface and service alias): type-hint
  `ObjectManagerInterface` instead. The service still implements it.

## More documentation

- [Cookbook](docs/cookbook.md): worked examples, testing, troubleshooting.
- [Architecture](docs/architecture.md): code map and invariants, for contributors.

## Development

PHPUnit 11 tests live in `tests/`. Without a local PHP, run them in a container:

```bash
docker run --rm -u $(id -u):$(id -g) -e COMPOSER_HOME=/tmp/c -v "$PWD":/app -w /app composer:2 \
  sh -c 'composer install -q && vendor/bin/phpunit'
```

`config.platform.php` is pinned to 8.3 so dependencies resolve for the minimum supported PHP version
(and Symfony 7.4). PHPUnit fails on any deprecation, including Symfony's silenced ones.

[Rector](https://getrector.com/) keeps the code free of deprecated PHP, Symfony and PHPUnit usage
(`rector.php`, upgrade rules only). CI runs the dry run; apply its changes with:

```bash
docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app composer:2 composer rector:fix
```
