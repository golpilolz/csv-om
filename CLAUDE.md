# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Context

`golpilolz/csv-om` is a Symfony bundle (PHP >= 8.3, Symfony 7.4|8, `league/csv`) providing a Doctrine-like object manager backed by CSV files. This directory is its own git repo, installed as a Composer VCS clone inside the dev harness app at `../../../` (`website/`). The harness app exercises the bundle via `App\Entity\Test` and data in `website/csv/`; see the harness's `CLAUDE.md` for the Docker setup.

## Tests

PHP isn't installed on the host. Run PHPUnit (11) in a throwaway container from this directory:

```bash
docker run --rm -u $(id -u):$(id -g) -e COMPOSER_HOME=/tmp/c -v "$PWD":/app -w /app composer:2 \
  sh -c 'composer install -q && vendor/bin/phpunit'
# single test / class:
docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app composer:2 \
  vendor/bin/phpunit --filter testExtensionRegistersServicesAndAliases
```

`phpunit.xml.dist` sets `failOnRisky` and `failOnWarning`. Tests live under `tests/` (namespace `Golpilolz\CsvOm\Tests\`). No linter or static analysis is configured. `config.platform.php` is pinned to 8.3.0 so dependencies resolve for the minimum supported PHP; also check on `php:8.3-cli` (`vendor/bin/phpunit` only, no composer in that image).

CI (`.github/workflows/tests.yml`) runs PHP 8.3/8.4/8.5 × Symfony 7.4/8 (no Symfony 8 on PHP 8.3) with the platform pin removed, a `--prefer-lowest` job (PHP 8.3, Symfony 7.4) that guards the lower bounds in `composer.json` (when raising or adding a requirement, keep that job green), and a Rector dry-run job.

Deprecations fail the suite: `phpunit.xml.dist` has `failOnDeprecation` and `ignoreSuppressionOfDeprecations` (Symfony triggers them with `@trigger_error`). PHPUnit prints "OK, but there were issues!" yet exits 1 in that case.

Rector (`rector.php`, `composer rector` / `composer rector:fix`) is upgrade-only: PHP sets from the `php` constraint, Symfony/PHPUnit sets from installed versions — run it with the default install (platform pin → Symfony 7.4) so it never applies 8.x-only rules. Skipped on purpose (reasons in `rector.php`): `ReadOnlyPropertyRector` (entities are mutated from outside), `ClassPropertyAssignToConstructorPromotionRector` (changes property order = CSV column order), `ContainerBuilderCompileEnvArgumentRector`. Lint workflow changes with `docker run --rm -v "$PWD":/repo -w /repo rhysd/actionlint:1.7.7`.

## Architecture & status

All v1 steps of **`docs/plan/object-manager.md`** are done; it records the design decisions (and follow-up ideas), read it before structural changes. Its "Current state" section describes the original Redis-derived starting point, not today's code. `README.md` is the user documentation: keep it in sync when behavior, options or exceptions change.

Current wiring:
- `GolpilolzCsvOmBundle` → `GolpilolzCsvOmExtension` processes `Configuration` (`golpilolz_csv_om.directory`/`delimiter`/`enclosure`), loads `Resources/config/services.yaml` and injects the config into `golpilolz_csv_om.storage`. `golpilolz_csv_om.object_manager` (`Om\ObjectManager`, public) is aliased to `Om\ObjectManagerInterface`; `Om\CSVObjectManagerInterface` is a deprecated interface + deprecated alias kept for BC (`ObjectManager` implements it).
- Repositories: `RepositoryInterface` implementations are autoconfigured with tag `golpilolz_csv_om.repository`; `Compiler\RepositoryPass` builds a lazy service locator keyed by repository class, injected as `$repositoryLocator`. `getRepository()` prefers the service (and checks its `getClassName()` matches), else does `new $repositoryClass($om, $className)`. Service repositories follow Doctrine's pattern: `__construct(ObjectManagerInterface $om) { parent::__construct($om, Entity::class); }`.
- Mapping: `Om\Mapping\{Entity, Id, Column, Types}` attributes hold only scalars. `Om\Metadata\MetadataFactory` turns them into cached `ClassMetadata`/`FieldMapping`, inferring column type/nullability from PHP property types and throwing `Exception\MappingException` (named constructors) on invalid mappings.
- `ObjectManager(storage, ?metadataFactory, ?converter, ?repositoryLocator)` owns a `UnitOfWork` and caches one repository per entity. `UnitOfWork` loads each file once into a `Table` (raw rows incl. unmapped columns, converted values, canonical strings), keeps the identity map keyed by canonical id, and implements `findBy`/`count`. Canonicalization (`TypeConverter::canonicalize`) is what makes `7`, `'7'` and `'007'` the same id/criterion — use it for any comparison with file data.
- Write path: `persist/remove/flush/clear/contains`. Entity states (managed/new/removed) are tracked per `spl_object_id`; the loaded `Table` is the change-tracking snapshot (compare `extract()` canonical cells to the row's canonical values). `flush()` validates every file before writing any, then commits per file. Keep these invariants: unchanged rows and unmapped cells are written back verbatim; ids are application-assigned and immutable once managed.
- Read semantics follow Doctrine: queries see loaded file data, not unflushed object changes. Unmapped file columns must survive writes (user decision) — the raw row in `Table` is the source for them.
- `Storage\CsvStorage` (behind `CsvStorageInterface`) does all file IO: strict reads into `CsvTable`, atomic writes (temp file + `fsync` + `rename`, sidecar `.<file>.lock` flock), RFC 4180 quoting with escape `''` (league's backslash default corrupts values ending in `\`). Paths are relative to the base directory; absolute/`..` paths are rejected. Registered as `golpilolz_csv_om.storage`.
- `Om\Type\TypeConverter` (cell string ↔ PHP value, strict, throws `ConversionException`) and `Om\Hydrator\ObjectHydrator` (row ↔ object without the constructor, via closures bound to the declaring class so private/readonly work; `HydrationException` for missing columns, uninitialized non-nullable props, wrong class). Floats are written with `var_export` on purpose (round-trip precision), dates with `Column(format:)` (default ATOM).

Target design (per the plan): attributes hold no runtime objects; `MetadataFactory`/`ClassMetadata`, `CsvStorage` (league/csv, atomic temp-file + `flock` + `rename` writes), `TypeConverter`, `ObjectHydrator`, `UnitOfWork` (identity map keyed by string id, snapshots for dirty checking), `ObjectManager` (`find/getRepository/persist/remove/flush/clear/contains`), and `CsvRepository` (`find/findAll/findBy/findOneBy/count`, in-memory filtering over a whole-file load). CSV files require a header row; columns matched by name. Each plan step should end with passing tests using fixtures in `tests/Fixtures`.

When changing the public API or config, also update the harness app (`App\Entity\Test`, `config/packages/`, `.env`) so the `/csv` route keeps working.
