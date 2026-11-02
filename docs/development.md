# Development

PHPUnit 11 tests live in `tests/`. Without a local PHP, run them in a container:

```bash
docker run --rm -u $(id -u):$(id -g) -e COMPOSER_HOME=/tmp/c -v "$PWD":/app -w /app composer:2 \
  sh -c 'composer install -q && vendor/bin/phpunit'
```

`config.platform.php` is pinned to 8.3 so dependencies resolve for the minimum supported PHP version
(and Symfony 7.4). PHPUnit fails on any deprecation, including Symfony's silenced ones.

[Rector](https://getrector.com/) keeps the code free of deprecated PHP, Symfony and PHPUnit usage
(`rector.php`, upgrade rules only). CI runs the dry run; apply its changes with:

```bash
docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app composer:2 composer rector:fix
```

## Generating entities (MakerBundle)

With `symfony/maker-bundle` installed, `make:csv-entity` generates a mapped class and creates the CSV file with its header row when it doesn't exist:

```bash
bin/console make:csv-entity Book
```

The command asks, like `make:entity`, for the class name, the CSV file (default: snake_case class name + `.csv`), then loops on "New field name" (empty to stop), its type (`string`, `int`, `float`, `bool`, `datetime_immutable`) and whether it is nullable. An `id` int field is added when missing.
