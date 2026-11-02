# Symfony Flex recipe

Recipe for `golpilolz/csv-om`, laid out as in
[symfony/recipes-contrib](https://github.com/symfony/recipes-contrib):
`golpilolz/csv-om/1.0/{manifest.json,config/,csv/,post-install.txt}`.

## What it does

- registers `GolpilolzCsvOmBundle` in `config/bundles.php`;
- creates `config/packages/golpilolz_csv_om.yaml` with the default options;
- creates the `csv/` data directory (the bundle reports a missing directory as an error);
- prints a short getting-started message.

## Publish it

The package must first be on Packagist (it currently is not).

1. Fork `symfony/recipes-contrib`.
2. Copy `recipe/1.0/` to `golpilolz/csv-om/1.0/` in the fork.
3. Open a PR; Flex's CI validates the manifest.

## Use it before it is accepted

Point the application at a recipe endpoint you host, keeping the defaults:

```json
"extra": {
    "symfony": {
        "endpoint": [
            "https://api.github.com/repos/<you>/recipes/contents/index.json?ref=flex/main",
            "flex://defaults"
        ]
    }
}
```

or copy `recipe/1.0/config/*` and `csv/` into the application by hand.
