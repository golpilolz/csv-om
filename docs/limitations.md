# Limitations

- **Row index in memory.** CSV has no indexes, so a file is scanned once per object manager (per request) and
  every row is validated. Rows are not kept: only the byte offset of each one is (a few dozen bytes per row), and
  a row is read from the file when an object is built from it. A 160 MB file of 2 million rows runs within a
  256 MB `memory_limit`, but the scan takes seconds. Converted values are derived per field, on demand, when a
  query filters or sorts on it, and kept for the request. `findAll()` and wide queries still build one object
  per matching row, so they need memory in proportion to the result. A cell with an unclosed quote swallows the
  rest of the file as one value.
- **Concurrent writers get an error, not a merge.** Writes are atomic (temporary file, `fsync`, then
  `rename()`, so readers never see a partial file) and serialized with `flock()` on a `.<file>.lock` file kept
  next to each data file. If a file changed since this object manager loaded it, `flush()` throws a
  `ConcurrentModificationException` and writes nothing to it, so no update is lost silently. There is no
  automatic retry or merge: `clear()` and redo the change (see [Concurrency](cookbook.md#concurrency)).
  The check compares a version token that every write of the bundle renews, plus the file's inode, size and
  modification time. An edit made outside the bundle that keeps the inode, the size and the modification
  second is not detected.
  `#[GeneratedValue]` increments are picked at `persist()` time, so concurrent processes can generate the same
  id; the second `flush()` then gets the exception. Use `UUID` for several writers.
- **`flush()` is not a transaction across files.** All changes are validated before any file is written, so a
  bad value writes nothing. If a write fails on disk, files already written keep their changes and the rest stay
  pending for the next `flush()`.
- **Directory entities** keep one open file handle per file whose rows were read, so a directory of thousands
  of files can hit the process limit on open files. Rows cannot be moved between the files of a directory.
- **Associations** are resolved through the identity map without proxies: to-one relations load eagerly, to-many
  collections on first access. See [Associations](associations.md) for the limits.
- **Not supported:** lazy proxies, composite ids,
  query language, joins and paths such as `author.name`, aggregates (`Criteria` and `Expr` cover filtering, ordering and paging), schema tooling.

## Deprecations

- `Golpilolz\CsvOm\Om\CSVObjectManagerInterface` (interface and service alias): type-hint
  `ObjectManagerInterface` instead. The service still implements it.

