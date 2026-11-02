# File format

- The first record is the header. Columns are matched by name, so their order in the file doesn't matter.
  A file missing a mapped column is an error.
- Quoting follows RFC 4180: fields containing the delimiter, the enclosure, spaces or line breaks are enclosed,
  and enclosures inside a field are doubled. There is no backslash escaping.
- A UTF-8 BOM and blank lines are skipped. Line endings are read as `\n` or `\r\n` and written as `\n`.
- Files are read as bytes: no encoding conversion is done, use UTF-8.
- A row with more or fewer fields than the header, a duplicate header column, or a duplicate id is an error.

Reads are strict on purpose: the whole file is converted when its entity is first used, so a bad value anywhere
in it is reported with its row id right away instead of being guessed at.

## Errors

All exceptions are in `Golpilolz\CsvOm\Exception`:

| Exception | Base class | When |
|---|---|---|
| `MappingException` | `LogicException` | invalid attributes, non-entity class, repository/entity mismatch |
| `StorageException` | `RuntimeException` | missing directory, unreadable or malformed file, missing mapped column, duplicate id, failed write |
| `ConcurrentModificationException` | `RuntimeException` | `flush()` on a file that changed since it was loaded (nothing is written); also when a file is replaced while its rows are still being read |
| `ConversionException` | `RuntimeException` | a cell or property value that doesn't match its type |
| `HydrationException` | `RuntimeException` | uninitialized non-nullable property on write, wrong object class |
| `QueryException` | `InvalidArgumentException` | unknown field, invalid criterion, order direction, limit or offset |
| `PersistenceException` | `RuntimeException` | missing or duplicate id on `persist()`, unmanaged object on `remove()`, id changed |
