# Configuration

Every option is optional; these are the defaults:

```yaml
# config/packages/golpilolz_csv_om.yaml
golpilolz_csv_om:
  # Directory holding the CSV files. Entity "file" options are relative to it.
  # It must exist: a missing directory is reported as an error, not as empty tables.
  directory: '%kernel.project_dir%/csv'
  # Default field delimiter and enclosure (one character each), overridable per entity.
  delimiter: ','
  enclosure: '"'
```

