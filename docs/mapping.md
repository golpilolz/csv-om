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
| `repositoryClass` | `CSVRepository` | Must implement `RepositoryInterface`; see [Custom repositories](usage.md#custom-repositories). |
| `delimiter`, `enclosure` | bundle configuration | One character each. |

## `#[Id]`

Marks the identifier. Exactly one property per entity carries it. It must be a non-nullable `int` or
`string` (or a backed enum). `#[Id]` implies `#[Column]`, so add `#[Column]` only to change its options.

Ids are **assigned by the application**: there is no auto-increment. Set the id before `persist()`, and
don't change it once the object is managed.

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

