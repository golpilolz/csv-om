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

`phpunit.xml.dist` sets `failOnRisky` and `failOnWarning`. Tests live under `tests/` (namespace `Golpilolz\CsvOm\Tests\`). No linter or static analysis is configured. `config.platform.php` is pinned to 8.3.0 so dependencies resolve for the minimum supported PHP; also check on `php:8.3-cli` (`vendor/bin/phpunit` only, no composer in that image).

## Architecture & status

The code was ported from a Redis OM and is mostly a skeleton — **`docs/plan/object-manager.md` is the roadmap and the source of truth for the target design**; read it before making structural changes. Steps are marked ✅ in the plan as they land; its "Current state" section describes the starting point, not today's code.

Current wiring:
- `GolpilolzCsvOmBundle` → `DependencyInjection\GolpilolzCsvOmExtension` loads `Resources/config/services.yaml`, which registers `golpilolz_csv_om.object_manager` (`Om\CSVObjectManager`, public) aliased to `Om\CSVObjectManagerInterface`. No `Configuration` class yet.
- Mapping: `Om\Mapping\{Entity, Id, Column, Types}` attributes hold only scalars. `Om\Metadata\MetadataFactory` turns them into cached `ClassMetadata`/`FieldMapping`, inferring column type/nullability from PHP property types and throwing `Exception\MappingException` (named constructors) on invalid mappings.
- `CSVObjectManager(storage, ?metadataFactory, ?converter)` owns a `UnitOfWork` and one repository per entity (`new $repositoryClass($om, $className)`). `UnitOfWork` loads each file once into a `Table` (raw rows incl. unmapped columns, converted values, canonical strings), keeps the identity map keyed by canonical id, and implements `findBy`/`count`. Canonicalization (`TypeConverter::canonicalize`) is what makes `7`, `'7'` and `'007'` the same id/criterion — use it for any comparison with file data.
- Read semantics follow Doctrine: queries see loaded file data, not unflushed object changes. Unmapped file columns must survive writes (user decision) — the raw row in `Table` is the source for them.
- `Storage\CsvStorage` (behind `CsvStorageInterface`) does all file IO: strict reads into `CsvTable`, atomic writes (temp file + `fsync` + `rename`, sidecar `.<file>.lock` flock), RFC 4180 quoting with escape `''` (league's backslash default corrupts values ending in `\`). Paths are relative to the base directory; absolute/`..` paths are rejected. Registered as `golpilolz_csv_om.storage` with a hard-coded `%kernel.project_dir%/csv` directory until step 7.
- `Om\Type\TypeConverter` (cell string ↔ PHP value, strict, throws `ConversionException`) and `Om\Hydrator\ObjectHydrator` (row ↔ object without the constructor, via closures bound to the declaring class so private/readonly work; `HydrationException` for missing columns, uninitialized non-nullable props, wrong class). Floats are written with `var_export` on purpose (round-trip precision), dates with `Column(format:)` (default ATOM).

Target design (per the plan): attributes hold no runtime objects; `MetadataFactory`/`ClassMetadata`, `CsvStorage` (league/csv, atomic temp-file + `flock` + `rename` writes), `TypeConverter`, `ObjectHydrator`, `UnitOfWork` (identity map keyed by string id, snapshots for dirty checking), `ObjectManager` (`find/getRepository/persist/remove/flush/clear/contains`), and `CsvRepository` (`find/findAll/findBy/findOneBy/count`, in-memory filtering over a whole-file load). CSV files require a header row; columns matched by name. Bundle config will be `golpilolz_csv_om.directory`/`delimiter`, replacing `DATABASE_CSV_DIR`. Each plan step should end with passing tests using fixtures in `tests/Fixtures`.

When changing the public API or config, also update the harness app (`App\Entity\Test`, `config/packages/`, `.env`) so the `/csv` route keeps working.
