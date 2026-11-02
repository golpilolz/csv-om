# csv-om

[![Tests](https://github.com/golpilolz/csv-om/actions/workflows/tests.yml/badge.svg?branch=main)](https://github.com/golpilolz/csv-om/actions/workflows/tests.yml?query=branch%3Amain)

A Doctrine-style object manager for Symfony, backed by CSV files: one file is one table, one row is one
object. You map classes with attributes, then use `find()`, repositories, `persist()` and `flush()` as you
would with Doctrine ORM.

```php
$book = $om->find(Book::class, 7);
$book->setTitle('Foundation and Empire');

$om->persist(new Book(8, 'Second Foundation'));
$om->flush(); // rewrites library/books.csv
```

Requires PHP 8.3+, Symfony 7.4 or 8.x (Symfony 8 needs PHP 8.4+) and [league/csv](https://csv.thephpleague.com/) 9.28+.

## Installation

The package is not on Packagist. Add the repository, then require it:

```json
{
    "repositories": [
        { "type": "vcs", "url": "git@github.com:golpilolz/csv-om.git" }
    ]
}
```

```bash
composer require golpilolz/csv-om:dev-main
```

A draft Flex recipe is in [`recipe/`](recipe/README.md) (not published yet, so it is not applied automatically). Register the bundle in `config/bundles.php`:

```php
return [
    // ...
    Golpilolz\CsvOm\GolpilolzCsvOmBundle::class => ['all' => true],
];
```

## Documentation

- [Configuration](docs/configuration.md): directory, delimiter, enclosure.
- [Mapping](docs/mapping.md): `#[Entity]` (a file or a directory of files), `#[Id]`, `#[Column]` and supported types.
- [Usage](docs/usage.md): reading, writing and custom repositories.
- [Associations](docs/associations.md): `ManyToOne`, `OneToMany`, `OneToOne`, `ManyToMany`.
- [Lifecycle events](docs/events.md): entity callbacks and listeners (`prePersist`, `postLoad`, ...).
- [File format and errors](docs/file-format.md): CSV rules and exception reference.
- [Limitations](docs/limitations.md) and deprecations.
- [Development](docs/development.md): tests, Rector, `make:csv-entity`.
- [Cookbook](docs/cookbook.md): worked examples, testing, troubleshooting.
- [Architecture](docs/architecture.md): code map and invariants, for contributors.
