# Limitations

- **Row index in memory.** CSV has no indexes, so a file is scanned once per object manager (per request) and
  every row is validated. Rows are not kept: only the byte offset of each one is (a few dozen bytes per row), and
  a row is read from the file when an object is built from it. A 160 MB file of 2 million rows runs within a
  256 MB `memory_limit`, but the scan takes seconds. Converted values are derived per field, on demand, when a
  query filters or sorts on it, and kept for the request. `findAll()` and wide queries still build one object
  per matching row, so they need memory in proportion to the result. A cell with an unclosed quote swallows the
  rest of the file as one value.
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

