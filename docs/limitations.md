# Limitations

- **Whole files in memory.** CSV has no indexes: a file is loaded completely, once per object manager (per
  request). Only the raw cells are kept (about 1 KB per row for a 9-column file: 100,000 rows load in
  ~110 MB); every row is still validated on load, and converted values are derived per field, on demand,
  when a query filters or sorts on it. `findAll()` also builds one object per row. It suits small to medium
  data sets; raise PHP's `memory_limit` for bigger ones.
- **No protection against lost updates between processes.** Writes are atomic (temporary file, `fsync`, then
  `rename()`, so readers never see a partial file), and concurrent writers are serialized with `flock()` on a
  `.<file>.lock` file kept next to each data file. But two processes that both load and then flush the same file
  each write their own version, and the last one wins.
- **`flush()` is not a transaction across files.** All changes are validated before any file is written, so a
  bad value writes nothing. If a write fails on disk, files already written keep their changes and the rest stay
  pending for the next `flush()`.
- **Associations** are resolved through the identity map without proxies: to-one relations load eagerly, to-many
  collections on first access. See [Associations](associations.md) for the limits.
- **Not supported:** lazy proxies, lifecycle events and listeners, generated ids, composite ids,
  query language or query builder, schema tooling.

## Deprecations

- `Golpilolz\CsvOm\Om\CSVObjectManagerInterface` (interface and service alias): type-hint
  `ObjectManagerInterface` instead. The service still implements it.

