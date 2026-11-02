# Mapping

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

## `#[Entity]`

| Option | Default | Description |
|---|---|---|
| `file` | short class name in snake_case + `.csv` (`ProductCategory` → `product_category.csv`) | Path relative to the configured directory. Absolute paths and `..` are rejected. Missing subdirectories are created on write. |
| `directory` | none | Directory, relative to the configured directory, whose `.csv` files all hold rows of the entity; see [A directory of files](#a-directory-of-files). Exclusive with `file`. |
| `recursive` | `true` | With `directory`: also read the subdirectories. |
| `newFile` | none | With `directory`: file, relative to the directory, that receives new objects without a `#[SourceFile]` value. |
| `repositoryClass` | `CSVRepository` | Must implement `RepositoryInterface`; see [Custom repositories](usage.md#custom-repositories). |
| `delimiter`, `enclosure` | bundle configuration | One character each. |

## A directory of files

Map an entity to a directory instead of one file, and every `.csv` file in it, **and in its subdirectories**,
is read as one table:

```php
#[CsvOm\Entity(directory: 'logs', newFile: 'inbox.csv')]
class LogEntry
{
    #[CsvOm\Id] public int $id;
    #[CsvOm\Column] public string $message = '';

    #[CsvOm\SourceFile] public ?string $source = null;   // 'inbox.csv', '2024/march.csv', ...
}
```

```
csv/logs/inbox.csv
csv/logs/2024/march.csv        ← all of these are LogEntry rows
csv/logs/2024/archive/old.csv
```

- **What is read.** Files ending in `.csv` (any case), sorted by path; rows keep their file order, file after
  file. Hidden files and directories (names starting with `.`), other extensions and symbolic links are
  skipped. A missing directory is an empty table.
- **One table.** `find()`, `findBy()`, `matching()`, `count()` and associations see all files at once. Ids must
  be unique across **all** files (a duplicate is a `StorageException` naming both files, `persist()` refuses an
  id found in any file). Every file needs the mapped columns, but each may order them differently and carry its
  own unmapped columns, which survive writes as usual.
- **Updates and removals** rewrite only the file the row comes from; the other files are not touched.
- **New objects** are added to the file named by their `#[SourceFile]` property (created, with its
  subdirectories, if missing; its header is the mapped columns). With no value, the entity's `newFile` is used and
  written back to the property; with neither, `persist()` throws. A path must be relative to the directory, end
  with `.csv`, and contain no `..`, no hidden part and no `\`; without `recursive`, no subdirectory either.
- **`#[SourceFile]`** is an optional `?string` property that is not a column. It is filled when an object is
  loaded. Rows are never moved between files: changing it on a managed object throws a `PersistenceException`
  at `flush()`.
- **Concurrency.** Each file is checked like a single-file entity (see [Limitations](limitations.md)), and so is
  the list of files: a file created or deleted in the directory after the load makes `flush()` throw a
  `ConcurrentModificationException`.
- A many-to-many join file defaults to `<directory>_<property>.csv` next to the directory, so it is never
  mistaken for a data file; `make:csv-entity` only generates single-file entities.

## `#[Id]`

Marks the identifier. Exactly one property per entity carries it. It must be a non-nullable `int` or
`string` (or a backed enum). `#[Id]` implies `#[Column]`, so add `#[Column]` only to change its options.

By default ids are **assigned by the application**: set the id before `persist()`, and don't change it once
the object is managed. Add `#[GeneratedValue]` to have it generated instead.

### `#[GeneratedValue]`

```php
#[CsvOm\Id]
#[CsvOm\GeneratedValue]                                      // INCREMENT (default), int id
public int $id;

#[CsvOm\Id]
#[CsvOm\GeneratedValue(strategy: CsvOm\GeneratedValue::UUID)] // random UUID v4, string id
public string $id;
```

Leave the id property uninitialized: `persist()` generates it, so `$object->id` is available right after
`persist()`, before `flush()`. An id that is already set is kept (and checked for duplicates as usual).

- `INCREMENT`: highest id known in the file or among persisted objects + 1 (1 for an empty file). Ids of rows
  removed in the same unit of work are not reused. Two processes persisting at the same time can pick the same
  id and the last flush wins (see [Limitations](limitations.md)): use `UUID` when several writers share a file.
- `UUID`: needs a `string` id. Enum ids are not supported.

## `#[Column]`

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

## Types

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

