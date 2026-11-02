# Associations

Relate entities with the Doctrine attribute names. CSV has no foreign keys, so the **owning side stores the
target id**.

```php
#[CsvOm\Entity]
class Book
{
    #[CsvOm\Id] public int $id;

    #[CsvOm\ManyToOne(Author::class, inversedBy: 'books')]
    #[CsvOm\JoinColumn(name: 'author_id', onDelete: CsvOm\JoinColumn::SET_NULL)]   // optional
    public ?Author $author = null;
}

#[CsvOm\Entity]
class Author
{
    #[CsvOm\Id] public int $id;

    #[CsvOm\OneToMany(Book::class, mappedBy: 'author', cascade: ['persist'])]
    public Collection $books;          // Golpilolz\CsvOm\Om\Collection\Collection

    public function __construct() { $this->books = new Collection(); }
}
```

| Attribute | Stored | Notes |
|---|---|---|
| `ManyToOne` | a column in the entity's file (default `<property>_id`, typed like the target id) | always owning; `#[JoinColumn(name, nullable, onDelete)]` |
| `OneToOne` | owning side: a column as above; inverse side (`mappedBy`): nothing | |
| `OneToMany` | nothing | always the inverse of a `ManyToOne` (`mappedBy` required) |
| `ManyToMany` | owning side: a join file with two id columns; inverse side (`mappedBy`): nothing | `#[JoinTable(file, joinColumn, inverseJoinColumn)]`; default file `<owner file>_<property>.csv`, columns `<owner>_id`, `<target>_id` (self-references need explicit names) |

- **Same instance everywhere.** Relations go through the identity map, so `$book->author` is the object
  `find(Author::class, …)` returns, and `$author->books` holds the same `Book` instances `findBy()` returns.
- **Loading.** To-one relations (and the inverse side of a one-to-one) are resolved when the object is loaded;
  a reference to an id missing from the target file throws `HydrationException`. To-many properties get a
  `Collection` that loads on first access. `Collection` offers `add`, `remove`, `contains`, `isEmpty`, `clear`,
  `toArray`, `count`, iteration and `$c[] = $object`. Type the property `Collection` (or leave it untyped).
- **Only the owning side is written.** Reassign `$book->author`, or change `$team->tags` (owning `ManyToMany`);
  changes made only to an inverse side (`$author->books`) are ignored on flush, like in Doctrine. Unmapped
  columns of entity and join files are kept.
- **Targets must be managed.** At `flush()`, a reference to an object that was never persisted throws
  `PersistenceException`, unless the association declares `cascade: ['persist']`: persisting the object then
  persists the related ones (to-many collections only if they were loaded or modified).
- **New objects own their collections.** `persist()` initializes empty collections that are still unset.
- **`onDelete`** (on `JoinColumn`, for owning to-one associations) tells what happens to the referencing object
  when its target is removed: `RESTRICT` (default, `flush()` throws), `SET NULL` (nullable, non-readonly
  properties only) or `CASCADE` (the referencing object is removed too). Join-file rows of a removed object are
  always deleted. The check sees the referencing objects through the inverse side declared on the removed
  class and through every entity already used in this object manager; a one-way `ManyToOne` from an entity that
  was never loaded in this request is not found.
- **Querying.** Owning to-one associations can be used in `findBy`, `findOneBy`, `count`, ordering (by target
  id) and magic finders, by object or by id: `findBy(['author' => $author])`, `findByAuthor(7)`,
  `findBy(['author' => null])`. Inverse sides and paths such as `author.name` are not queryable.
- **Column order.** Join columns come after the scalar columns when a file is created.

