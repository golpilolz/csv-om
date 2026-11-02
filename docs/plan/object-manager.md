# Plan: Doctrine-like Object Manager for CSV files

> **Status:** all v1 steps are done (see ✅ below). User documentation is in `README.md`.

Goal: map PHP classes to CSV files (one file = one table, one row = one object) and expose a
Doctrine-style API: `find`, `getRepository`, `findBy`, `persist`, `remove`, `flush`.

```php
#[CsvOm\Entity(file: 'test.csv')]
class Test
{
    #[CsvOm\Id]
    #[CsvOm\Column(type: 'int')]
    public int $id;

    #[CsvOm\Column]
    public string $name;
}

$test = $om->find(Test::class, 1);
$all  = $om->getRepository(Test::class)->findBy(['name' => 'arthur'], ['id' => 'DESC']);

$new = new Test(); $new->id = 4; $new->name = 'lea';
$om->persist($new);
$om->flush();
```

## Current state (to fix)

The code was adapted from a Redis OM and the CSV side is not implemented:

- `CSVRepository` is created by the `#[Entity]` attribute with no client/prefix/class → `find()` fatals on a null client.
- `CSVRepository::find()` returns the `array` from `search()` while declared `?object` → TypeError.
- `CSVClient::search()` is a stub (`var_dump`, returns `[]`); CSV reading is commented out.
- Base dir read from `$_SERVER['DATABASE_CSV_DIR']`: the value `%kernel.project_dir%/csv` is never resolved.
- `QueryBuilder` calls `customSearch()`, which is not on `CSVClientInterface`.
- `Property(index)` is a Redis leftover; no column name/type mapping; no hydration.
- Identity map never filled; `$snapshots` referenced but undefined; error message mentions `RedisOm`.
- `composer.json` lacks `php` and `symfony/*` requirements; `tests/` does not exist.

## Target architecture

| Doctrine concept | csv-om class | Responsibility |
|---|---|---|
| Mapping attributes | `Om\Mapping\Entity`, `Om\Mapping\Id`, `Om\Mapping\Column`, `Om\Mapping\Types` | Declarative mapping. `Entity(file, repositoryClass, delimiter, enclosure)`, `Column(name, type, nullable)`. No runtime objects inside attributes. |
| ClassMetadata | `Om\Metadata\ClassMetadata`, `Om\Metadata\FieldMapping` | File name, id field, field → column map, field types. |
| MetadataFactory | `Om\Metadata\MetadataFactory` | Builds metadata from attributes via reflection, cached per class. |
| Connection / DBAL | `Storage\CsvStorage` (+ interface) | Reads/writes a file with league/csv. Base directory injected. |
| Types | `Type\TypeConverter` | `string`, `int`, `float`, `bool`, `datetime_immutable`, `BackedEnum`; `''` ↔ `null` when nullable. |
| Hydrator | `Hydrator\ObjectHydrator` | Row ↔ object through reflection (private props supported, constructor bypassed). |
| UnitOfWork | `UnitOfWork` | Identity map, snapshots, scheduled inserts/updates/deletes, computes changes on flush. |
| EntityManager | `ObjectManager` (+ `ObjectManagerInterface`) | Public API: `find`, `getRepository`, `persist`, `remove`, `flush`, `clear`, `contains`. |
| EntityRepository | `Repository\CsvRepository` | `find`, `findAll`, `findBy`, `findOneBy`, `count`. Extensible like `ServiceEntityRepository`. |

### Key decisions

- **File naming**: `Entity(file:)` optional; default = snake_case short class name + `.csv` (`Test` → `test.csv`).
- **Header row** is required; columns are matched by name, not position.
- **IDs compared as strings** (CSV values are strings) and the identity map is keyed by the normalized string id.
- **Load strategy**: a file is fully loaded once per request into an in-memory table indexed by id.
  CSV has no indexes, so a full scan is unavoidable; caching makes repeated lookups free and simplifies flush.
- **Querying**: `findBy` filters/sorts/limits in memory on the loaded table (equality and `IN` via array value
  for criteria). No custom query language; `QueryBuilder` is dropped for now.
- **Writes**: `flush()` rewrites only the files with pending changes: write to a temp file in the same
  directory, under `flock`, then `rename()` (atomic). Header order is taken from metadata.
- **Dirty checking**: snapshot of the dehydrated row on load; on flush, compare current dehydrated row to snapshot.
- **Associations**: out of scope for v1; planned as step 9 (relations between entities, below).
- **doctrine/persistence interfaces**: not in v1 (large surface, e.g. `ClassMetadata` ~15 methods).
  The API is kept compatible in naming/signatures so implementing them later is additive. *Pending confirmation.*

### Symfony integration

```yaml
# config/packages/golpilolz_csv_om.yaml
golpilolz_csv_om:
  directory: '%kernel.project_dir%/csv'
  delimiter: ','          # default for all entities, overridable per entity
```

- `DependencyInjection\Configuration` + extension wiring `CsvStorage(directory)`, `MetadataFactory`, `ObjectManager`.
- Alias `ObjectManagerInterface` for autowiring (keep `CSVObjectManagerInterface` as deprecated alias during transition).
- Custom repositories: classes extending `CsvRepository` are autoconfigured with a tag
  (`golpilolz_csv_om.repository`); a compiler pass registers them in a repository locator used by `getRepository()`.

## Implementation steps

Each step ends with passing PHPUnit tests (using fixture CSV files in `tests/Fixtures`).

1. **Housekeeping** — ✅ done
   - `composer.json`: require `php >=8.3`, `league/csv ^9.28`, `symfony/config|dependency-injection|http-kernel ^6.4|^7.0`;
     dev: `phpunit/phpunit`; add `phpunit.xml.dist`, `tests/`.
   - Remove Redis leftovers and `var_dump`s.
2. **Mapping & metadata** — ✅ done
   - Attributes `Entity`, `Id`, `Column` (replace `Property`).
   - `Column` infers `type`/`nullable` from the property type; backed enums map to their backing type + `enumType`.
   - Column name defaults to the property name; `#[Id]` alone implies a column; id must be non-nullable `int`/`string`.
   - `ClassMetadata`, `MetadataFactory` with validation (exactly one `#[Id]`, entity attribute present) and clear exceptions.
3. **Storage** — ✅ done
   - `CsvStorageInterface::read(string $file, ...): iterable<array<string,string>>` and `write(...)`.
   - Atomic write with lock; exception when file missing on read (or empty table, configurable).
   - Done as: `read()` returns a `CsvTable` (header + rows keyed by column) and is strict (wrong field count,
     duplicate header → `StorageException`); missing file throws, `exists()` lets callers decide (steps 5/6).
     RFC 4180 quoting (escape char `''`). Writers lock a sidecar `.<file>.lock`; temp file keeps the target's mode.
     `Client\CSVClient` is still there, unused by storage; it is removed when repositories are wired in step 5.
4. **Types & hydration** — ✅ done
   - `TypeConverter` (to PHP / to CSV string), `ObjectHydrator` (hydrate / extract).
   - Done as: strict parsing (`ConversionException` names entity, id, field, value); `Column(format:)` added for
     dates (default ATOM, `!`-prefixed strict parse); floats written with `var_export` (round-trip precision);
     bool written `1`/`0`, read `1/0/true/false`. Hydration bypasses the constructor and sets properties via a
     closure bound to the declaring class (private/readonly OK). Column order = reflection order: own properties
     first, then inherited ones. `extractId()` returns the id as written in CSV (null when unset).
5. **Read path** — ✅ done
   - Decide how a file header that differs from the mapped columns is handled. Mapped column missing → error.
     Extra, unmapped columns: since `flush()` rewrites the file from mapped columns only, they would be silently
     dropped. Either reject them on read or keep them per row and write them back (preferred: no data loss).
     **Decided: keep them per row and write them back.** `Table::$extraColumns` + raw rows hold them; step 6 merges.
   - `UnitOfWork` identity map + table cache; `ObjectManager::find`, `getRepository`;
     `CsvRepository::find/findAll/findBy/findOneBy/count`.
   - Milestone: `/csv` route in the test app returns `Test#1 margot`. ✅ verified.
   - Done as: `UnitOfWork` loads a file once per object manager and converts every row up front (errors surface
     on first use, with the row id); identity map keyed by canonical id (`7`, `'7'`, `'007'` are the same).
     Duplicate ids and missing mapped columns throw. Missing file = empty table; missing base directory throws.
     `findBy` matches canonical values (typed criteria, list = IN, null = empty cell), sorts on PHP values
     (stable, nulls first ASC), and works on loaded data (unflushed changes are not considered, like Doctrine).
     `find('abc')` on an int id returns null. `CSVClient` and `QueryBuilder` removed; `CSVRepository` is
     extendable (`__construct(objectManager, className)`). Storage directory is hard-coded to
     `%kernel.project_dir%/csv` in `services.yaml` until step 7.
6. **Write path** — ✅ done
   - `persist`, `remove`, `flush`, `clear`, `contains`; dirty checking with snapshots; duplicate id detection on insert.
   - Done as: the loaded `Table` is the snapshot (no separate copy); changed rows get mapped cells replaced, unmapped
     cells and unchanged rows are written back exactly as read. Ids are application-assigned: `persist()` requires
     one and rejects ids already managed or in the file; changing a managed id fails at flush. `flush()` computes and
     validates all files before writing any; on an I/O failure, files already written are committed and the rest stay
     pending. `remove()` of an unflushed new object cancels it; `clear()` also drops loaded tables (files re-read).
     A removed id can only be reused after the removal is flushed.
7. **Bundle integration** — ✅ done
   - `Configuration`, extension services, autowiring aliases, repository tag + compiler pass.
   - Update test app: `config/packages/golpilolz_csv_om.yaml`, drop `DATABASE_CSV_DIR`, update `App\Entity\Test`.
   - Done as: `Configuration` validates single-character delimiter/enclosure. `CSVObjectManager` renamed
     `ObjectManager`, new `ObjectManagerInterface`; `CSVObjectManagerInterface` kept as deprecated interface and
     deprecated service alias. Service repositories use Doctrine's constructor pattern; `RepositoryPass` exposes
     them through a lazy locator so `getRepository()` returns the autowired instance (entity class checked).
     `CSVRepository` keeps its name (a case-only rename to `CsvRepository` would break PSR-4 autoloading of old
     references on case-sensitive filesystems). App verified: `debug:config` and `/csv` OK.
8. **Docs** — ✅ done
   - README: installation, configuration, mapping reference, supported types, limitations (whole-file loading,
     no concurrent-write guarantees beyond `flock`, no associations).

9. **Associations** — ⏳ planned
   Goal: relate entities like Doctrine does, with the same attribute names, so that
   `$book->author` / `$author->books` work and `flush()` keeps the CSV files consistent.

   ```php
   #[CsvOm\Entity] class Book {
       #[CsvOm\Id] public int $id;
       #[CsvOm\ManyToOne(targetEntity: Author::class, inversedBy: 'books')]
       #[CsvOm\JoinColumn(name: 'author_id', nullable: true)]   // column holds the author id
       public ?Author $author = null;
   }
   #[CsvOm\Entity] class Author {
       #[CsvOm\Id] public int $id;
       #[CsvOm\OneToMany(targetEntity: Book::class, mappedBy: 'author')]
       public Collection $books;                                  // inverse side: no column
   }
   ```

   Relations and where they are stored (CSV has no foreign keys, so the owning side stores the related id):

   | Attribute | Owning side stores | Notes |
   |---|---|---|
   | `ManyToOne` | one column with the target id (`JoinColumn(name, nullable)`) | always owning |
   | `OneToOne` | one column with the target id | owning side; inverse side uses `mappedBy` and has no column |
   | `OneToMany` | nothing | always inverse of a `ManyToOne` (`mappedBy` required) |
   | `ManyToMany` | a join file, default `<owner>_<property>.csv`, two id columns (`JoinTable(file, joinColumn, inverseJoinColumn)`) | owning side declares `inversedBy`; inverse side `mappedBy` |

   Steps:
   a. **Mapping & metadata**: attributes `OneToOne`, `ManyToOne`, `OneToMany`, `ManyToMany`, `JoinColumn`,
      `JoinTable`; `AssociationMapping` in `ClassMetadata` (separate from `FieldMapping`: not a scalar column).
      `MetadataFactory` validates: target is a mapped entity, `mappedBy`/`inversedBy` point to a matching
      association on the other side, collection properties are typed `Collection`/`array`, owning side
      identified, join column does not clash with a `#[Column]`. Id type of the target drives the column type.
   b. **Storage of the foreign key**: the join column is an extra mapped column (`''` ↔ `null`), read and written
      with the existing table machinery; `ManyToMany` join files are tables handled like entity files
      (header required, unmapped columns kept, atomic write).
   c. **Hydration**: a relation is resolved through the identity map, so the same id gives the same instance.
      Decide eager vs lazy: **start eager-on-access via a lazy collection / reference** (no proxies in v1 of this
      step): `ManyToOne`/`OneToOne` set the target found by `find()` (missing target → `HydrationException`
      naming entity, id, field, value); `OneToMany`/`ManyToMany` get a `PersistentCollection` (Doctrine-like
      `Collection`, implementing `ArrayAccess`, `Countable`, `IteratorAggregate`) that loads on first use by
      scanning the target table (`findBy([mappedBy => owner])`). Proper lazy proxies stay out of scope.
   d. **Write path**: dehydrate relation to the target's id; the target must be managed or persisted
      (`persist()` of a source pointing to an unmanaged target throws, unless `cascade: ['persist']`). Dirty
      checking compares the id cell, so reassigning `$book->author` is a normal update. Only the owning side is
      written; changes made only on the inverse side are ignored (like Doctrine) and documented. `ManyToMany`
      diffs the collection against the loaded join rows (insert/delete rows). Order of writes within `flush()`
      does not matter (no database constraints) but every file is still computed and validated before any write.
   e. **Integrity on remove**: `remove()` of a target still referenced → choose per association
      `onDelete: 'RESTRICT'` (default, throws at flush), `'SET NULL'` (nullable join column only) or `'CASCADE'`
      (also removes referencing objects; `ManyToMany` join rows always removed). Check done at flush, on loaded
      data (references in files not yet loaded are found by loading the referencing table).
   f. **Querying**: `findBy`/`count` criteria accept an association (owning side, `ManyToOne`/`OneToOne`) by
      object or by id (`findBy(['author' => $author])`, list = IN); magic finders work too (`findByAuthor($a)`).
      Ordering by an association is by target id. No joins or paths (`author.name`): out of scope.
   g. **Maker & docs**: `MakeCsvEntity` can add a relation; README section "Associations" (relation table,
      owning vs inverse side, onDelete, limitations); update the "Not supported" list; fixtures `Author`/`Book`
      already exist in `tests/Fixtures` and are the base for tests.
   Tests: metadata validation errors, hydration identity (same instance across both sides), update/insert/
   delete of FK and join rows, `onDelete` behaviours, dangling reference error, criteria by object, flush
   atomicity with several files, round-trip keeps unmapped columns.
   Open questions: use `doctrine/collections` as a dependency for `Collection` (preferred, matches the
   Doctrine-like API) or ship a minimal own implementation; composite/`ManyToMany` extra columns on the join
   file (not supported initially); self-referencing relations (supported by design, test needed).

## Follow-up ideas

- Optimistic concurrency: remember each file's mtime/size (or hash) at load and make `flush()` fail, under the
  write lock, if the file changed since — turns today's silent lost update between processes into an error.

## Out of scope (v1)

Lazy proxies (associations themselves are step 9), lifecycle events, migrations/schema tooling, query language, multi-file transactions
(flush writes files one by one; a failure mid-flush can leave earlier files written).
