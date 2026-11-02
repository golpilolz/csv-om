# Architecture

For contributors. The design decisions and their history are in
[`plan/object-manager.md`](plan/object-manager.md); this page is the map of the code as it is today.

## Layers

```
Controller / service
        │  ObjectManagerInterface
        ▼
   ObjectManager ── getRepository() ──► CSVRepository (find, findBy, …)
        │                                      │
        ▼                                      ▼
   UnitOfWork  ◄──────────── delegates findBy / count
   (identity map, entity states, dirty checking)
        │            │
        │            └─► Table (loaded file: raw rows, converted values, canonical strings)
        ▼
 MetadataFactory ─► ClassMetadata / FieldMapping     TypeConverter     ObjectHydrator
 (reads attributes)  (cached, one per class)        (cell ↔ PHP)      (row ↔ object)
        │
        ▼
   CsvStorage (CsvStorageInterface) ── league/csv, atomic writes, flock
        │
        ▼
     *.csv files
```

## Source map

| Path | Responsibility |
|---|---|
| `GolpilolzCsvOmBundle`, `DependencyInjection/` | Bundle, `Configuration` (`directory`, `delimiter`, `enclosure`), extension, `RepositoryPass` |
| `Resources/config/services.yaml` | Service definitions; `golpilolz_csv_om.object_manager` is public and aliased to `ObjectManagerInterface` |
| `Om/Mapping/` | `Entity`, `Id`, `Column`, `Types`: attributes holding scalars only |
| `Om/Metadata/` | `MetadataFactory` builds cached `ClassMetadata` / `FieldMapping`, inferring type and nullability from PHP property types; throws `MappingException` |
| `Om/ObjectManager`, `Om/UnitOfWork`, `Om/Table` | Public API, state tracking, loaded-file snapshot |
| `Om/Type/TypeConverter` | Strict cell string ↔ PHP value; `canonicalize()` |
| `Om/Hydrator/ObjectHydrator` | Row ↔ object without calling the constructor |
| `Om/Repository/` | `RepositoryInterface`, `CSVRepository` |
| `Storage/` | `CsvStorage`, `CsvTable`: all file I/O |
| `Exception/` | One exception per failure family |

## Lifecycle of a request

**Read.** `find()` / `findBy()` → `UnitOfWork` loads the entity's file once through `CsvStorage` into a
`Table`, converting every row with `TypeConverter` and hydrating objects with `ObjectHydrator`. Objects
go in the identity map keyed by canonical id, so later reads return the same instance.

**Write.** `persist()` / `remove()` change an entity's state (managed, new, removed, tracked per
`spl_object_id`); property edits on managed objects are found at `flush()`. `flush()`:

1. extracts each managed object's canonical cells and compares them with the row in the `Table`;
2. builds the new content of every changed file (changed rows get mapped cells replaced; unchanged rows and
   unmapped cells are written back verbatim; new rows are appended);
3. validates **all** files before writing any;
4. commits file by file through `CsvStorage` (temp file, `fsync`, `rename`, under `flock` on the
   `.<file>.lock` sidecar).

## Invariants to preserve

- **Canonicalization.** Compare file data and criteria only through `TypeConverter::canonicalize`; it is why
  `7`, `'7'` and `'007'` are one id.
- **Queries see loaded data**, not unflushed object changes (Doctrine semantics).
- **Unmapped columns survive writes**; the raw row in `Table` is their source.
- **Ids are application-assigned** and immutable once managed.
- **Strict conversion**: never coerce silently; throw `ConversionException` with entity, id, field, value.
- **Column order = property declaration order**, own properties first. Constructor promotion refactors that
  move properties change it (the reason Rector's promotion rule is skipped).
- **Escape char.** `CsvStorage` uses RFC 4180 quoting with escape `''`; league's backslash default corrupts
  values ending in `\`.
- **Repository naming.** `CSVRepository` keeps its name: a case-only rename to `CsvRepository` would break
  autoloading on case-sensitive filesystems.

## Extension points

- **Custom repositories**: extend `CSVRepository`; services implementing `RepositoryInterface` are
  autoconfigured with the `golpilolz_csv_om.repository` tag and reachable via `getRepository()`.
- **Storage**: `CsvStorageInterface` is injected into `ObjectManager`, which makes it replaceable in tests.
- **Deprecated**: `CSVObjectManagerInterface` (interface and service alias) stays for BC.

## Working on the bundle

Tests live in `tests/` (fixtures in `tests/Fixtures`, `CsvDirectoryTrait` for temporary directories); see the
README's *Development* section for the Docker command. PHPUnit fails on risky tests, warnings and any
deprecation. CI runs PHP 8.3 to 8.5 against Symfony 7.4 and 8, a `--prefer-lowest` job and a Rector dry run.

Keep the README in sync when behavior, options or exceptions change, and check that the harness app's
`/csv` route still works (`App\Entity\Test`, `config/packages/golpilolz_csv_om.yaml`).

## Follow-up ideas

- Optimistic concurrency: remember each file's mtime/size (or hash) at load and make `flush()` fail, under the
  write lock, if the file changed since, turning today's silent lost update into an error.
