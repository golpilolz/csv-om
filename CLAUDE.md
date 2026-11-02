# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Context

`golpilolz/csv-om` is a Symfony bundle (PHP >= 8.3, Symfony 6.4|7, `league/csv`) providing a Doctrine-like object manager backed by CSV files. This directory is its own git repo, installed as a Composer VCS clone inside the dev harness app at `../../../` (`website/`). The harness app exercises the bundle via `App\Entity\Test` and data in `website/csv/`; see the harness's `CLAUDE.md` for the Docker setup.

## Tests

PHP isn't installed on the host. Run PHPUnit (11) in a throwaway container from this directory:

```bash
docker run --rm -u $(id -u):$(id -g) -e COMPOSER_HOME=/tmp/c -v "$PWD":/app -w /app composer:2 \
  sh -c 'composer install -q && vendor/bin/phpunit'
# single test / class:
docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app composer:2 \
  vendor/bin/phpunit --filter testExtensionRegistersObjectManager
```

`phpunit.xml.dist` sets `failOnRisky` and `failOnWarning`. Tests live under `tests/` (namespace `Golpilolz\CsvOm\Tests\`). No linter or static analysis is configured.

## Architecture & status

The code was ported from a Redis OM and is mostly a skeleton — **`docs/plan/object-manager.md` is the roadmap and the source of truth for the target design**; read it before making structural changes. Its "Current state" section is partly outdated (housekeeping step 1 — composer requirements, `tests/`, `var_dump` removal — is done).

Current wiring:
- `GolpilolzCsvOmBundle` → `DependencyInjection\GolpilolzCsvOmExtension` loads `Resources/config/services.yaml`, which registers `golpilolz_csv_om.object_manager` (`Om\CSVObjectManager`, public) aliased to `Om\CSVObjectManagerInterface`. No `Configuration` class yet.
- `CSVObjectManager::find()` reads the `#[Mapping\Entity]` attribute by reflection (cached per class) and delegates to the repository instance **created inside the attribute** (`Entity` instantiates a bare `CSVRepository` with no client/prefix — so `find()` currently fails on a null client). The identity map is never populated.
- `Client\CSVClient` reads its base dir from `$_SERVER['DATABASE_CSV_DIR']`; `search()`/`customSearch()` are stubs returning `[]`.
- `Mapping\Property(index)` is a Redis leftover, planned to be replaced by `Mapping\Column(name, type, nullable)`; `QueryBuilder` is planned to be dropped.

Target design (per the plan): attributes hold no runtime objects; `MetadataFactory`/`ClassMetadata`, `CsvStorage` (league/csv, atomic temp-file + `flock` + `rename` writes), `TypeConverter`, `ObjectHydrator`, `UnitOfWork` (identity map keyed by string id, snapshots for dirty checking), `ObjectManager` (`find/getRepository/persist/remove/flush/clear/contains`), and `CsvRepository` (`find/findAll/findBy/findOneBy/count`, in-memory filtering over a whole-file load). CSV files require a header row; columns matched by name. Bundle config will be `golpilolz_csv_om.directory`/`delimiter`, replacing `DATABASE_CSV_DIR`. Each plan step should end with passing tests using fixtures in `tests/Fixtures`.

When changing the public API or config, also update the harness app (`App\Entity\Test`, `config/packages/`, `.env`) so the `/csv` route keeps working.
