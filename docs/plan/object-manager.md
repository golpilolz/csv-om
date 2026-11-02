# Plan: Doctrine-like Object Manager for CSV files

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
- **Associations**: out of scope for v1 (later: `#[ManyToOne]` storing the related id, lazy loaded).
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
4. **Types & hydration**
   - `TypeConverter` (to PHP / to CSV string), `ObjectHydrator` (hydrate / extract).
5. **Read path**
   - `UnitOfWork` identity map + table cache; `ObjectManager::find`, `getRepository`;
     `CsvRepository::find/findAll/findBy/findOneBy/count`.
   - Milestone: `/csv` route in the test app returns `Test#1 margot`.
6. **Write path**
   - `persist`, `remove`, `flush`, `clear`, `contains`; dirty checking with snapshots; duplicate id detection on insert.
7. **Bundle integration**
   - `Configuration`, extension services, autowiring aliases, repository tag + compiler pass.
   - Update test app: `config/packages/golpilolz_csv_om.yaml`, drop `DATABASE_CSV_DIR`, update `App\Entity\Test`.
8. **Docs**
   - README: installation, configuration, mapping reference, supported types, limitations (whole-file loading,
     no concurrent-write guarantees beyond `flock`, no associations).

## Out of scope (v1)

Associations, lazy proxies, lifecycle events, migrations/schema tooling, query language, multi-file transactions
(flush writes files one by one; a failure mid-flush can leave earlier files written).
