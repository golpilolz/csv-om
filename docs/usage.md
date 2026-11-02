# Usage

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

## Reading

```php
$om->find(Book::class, 7);   // null if absent
$repository = $om->getRepository(Book::class);

$repository->findAll();      // in file order
$repository->findBy(['status' => BookStatus::Published], ['publishedAt' => 'DESC'], limit: 10, offset: 20);
$repository->findOneBy(['title' => 'Dune']);
$repository->count(['price' => null]);

// Magic finders on any mapped property (findBy<Property> / findOneBy<Property>)
$repository->findOneById(7);
$repository->findByStatus(BookStatus::Published, ['id' => 'DESC'], 10);  // value, orderBy, limit, offset
$repository->findOneByTitle('Dune');
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

## Criteria and operators

`findBy()` only does equality. For anything else, build a `Criteria` from `Expr` expressions and run it with
`matching()`:

```php
use Golpilolz\CsvOm\Om\Query\Criteria;
use Golpilolz\CsvOm\Om\Query\Expr;

$books = $repository->matching(
    Criteria::create()
        ->where(Expr::gte('price', 10))
        ->andWhere(Expr::orX(
            Expr::eq('status', BookStatus::Published),
            Expr::startsWith('title', 'The '),
        ))
        ->orderBy(['price' => 'DESC'])
        ->setFirstResult(20)
        ->setMaxResults(10),
);

$repository->count(Expr::between('publishedAt', new \DateTimeImmutable('1950-01-01'), new \DateTimeImmutable('1959-12-31')));
```

| Expression | Matches |
|---|---|
| `eq`, `neq` | equal / not equal. `neq` also matches empty cells. |
| `gt`, `gte`, `lt`, `lte`, `between` | typed comparison (numbers numerically, dates chronologically, enums by backing value). Never matches an empty cell. `between` is inclusive. |
| `in`, `notIn` | one of / none of a list |
| `isNull`, `isNotNull` | empty / non-empty cell |
| `contains`, `startsWith`, `endsWith` | case-sensitive text match on the cell. Never matches an empty cell. |
| `andX`, `orX`, `not` | combine expressions (an empty `andX` matches everything, an empty `orX` nothing) |

Operands are typed like `findBy()` values (`'7'` for an int field, an enum case for an enum field, an object or
id for an owning to-one association). Ordering and text operators don't apply to associations, and ordering
operators reject a `null` operand. Invalid fields, operands and operators throw a `QueryException`. `Criteria`
objects are mutable and reusable; like `findBy()`, they read the data as loaded from the file. `count()` also
accepts an expression.

## Writing

```php
$om->persist(new Book(8, 'Second Foundation')); // id must be set (unless the id is #[GeneratedValue])
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
- `persist()` throws a `PersistenceException` when the id is not set and not generated, or when it is already managed or present
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

