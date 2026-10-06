# Contributing

Thanks for helping. This document explains how to set up a development environment, run the checks and submit changes.

By contributing you agree that your contribution is licensed under GPL-3.0-or-later, the license of this project.

## Development setup

### Unit tests, static analysis, coding style (no database)

The package has its own `vendor/`. PHP 8.3+ and Composer 2 are enough:

```bash
git clone <your fork> elevate-dxp && cd elevate-dxp
composer install
composer test      # PHPUnit
composer stan      # PHPStan level 6 on src/ and tests/
composer cs        # PHP-CS-Fixer, dry run with diff
composer cs-fix    # apply the coding style
```

Run only the unit tests:

```bash
vendor/bin/phpunit --testsuite unit
vendor/bin/phpunit --filter ExperimentAssignerTest
```

### Working inside an OpenDXP application (Docker skeleton)

For admin UI and end-to-end work, use the [OpenDXP skeleton](https://github.com/open-dxp/skeleton) with its `docker-compose.yaml` and require this repository as a path repository:

```bash
composer create-project open-dxp/skeleton opendxp-app
cd opendxp-app
docker compose up -d
docker compose exec php vendor/bin/opendxp-install    # follow the prompts

# mount or copy this repository into the container, e.g. at /var/www/packages/elevate-dxp
docker compose exec php composer config repositories.elevate path /var/www/packages/elevate-dxp
docker compose exec php composer config minimum-stability dev
docker compose exec php composer config prefer-stable true
docker compose exec php composer require 'elevate-dxp/elevate-bundle:*@dev' 'open-dxp/personalization-bundle:^1.0'
```

Register the bundles in `config/bundles.php` (newsletter, personalization, then `ElevateDxp\ElevateDxpBundle`), enable targeting, and install:

```bash
docker compose exec php bin/console opendxp:bundle:install OpenDxpNewsletterBundle
docker compose exec php bin/console opendxp:bundle:install OpenDxpPersonalizationBundle
docker compose exec php bin/console opendxp:bundle:install ElevateDxpBundle
docker compose exec php bin/console assets:install public
docker compose exec php bin/console elevate-dxp:experiments:demo-seed
```

Add the bind mount for `/var/www/packages/elevate-dxp` in a `docker-compose.override.yaml` so that changes are picked up without copying. With a path repository Composer symlinks the package; after changing services or configuration run `bin/console cache:clear`. After changing files in `public/`, run `assets:install` again unless it created symlinks.

To run the bundle's unit tests with the application's autoloader:

```bash
docker compose exec -e ELEVATE_DXP_AUTOLOAD=/var/www/html/vendor/autoload.php php \
    vendor/bin/phpunit -c /var/www/packages/elevate-dxp/phpunit.xml.dist --testsuite unit
```

Adjust the paths to your container layout.

### Integration test

`tests/Integration/install-and-smoke.sh` creates a fresh skeleton, requires this bundle from a path repository, installs OpenDXP and the bundles, lints the container, dumps the configuration, runs `elevate-dxp:diagnostics` and the experiments demo seed, and runs HTTP smoke tests (`tests/Integration/smoke.php`) against the PHP built-in server. It needs a MariaDB/MySQL database:

```bash
BUNDLE_DIR=$PWD APP_DIR=/tmp/opendxp-app \
DB_HOST=127.0.0.1 DB_PORT=3306 DB_USER=opendxp DB_PASSWORD=opendxp DB_NAME=opendxp \
tests/Integration/install-and-smoke.sh
```

The script deletes and recreates `APP_DIR` and installs into the given database. Use a throwaway database.

## Conventions

### Code

- PHP 8.3 syntax, `declare(strict_types=1);` in every file, `final` classes unless extension is intended, `readonly` constructor properties.
- Coding style: `@PER-CS2.0` + `@Symfony` (see `.php-cs-fixer.dist.php`). Run `composer cs-fix` before committing.
- PHPStan level 6 must pass without new baseline entries.
- Namespaces: `ElevateDxp\<Module>\...`, mirrored in `tests/<Module>/`.
- Keep pure logic (statistics, matching, mapping, validation) free of OpenDXP and database calls, so it can be unit-tested.

### Modules and admin resources

- Configuration belongs under `elevate_dxp.<module>`; every option needs a default and an `info()` text. Use underscores in keys.
- Tables use the `edxp_` prefix. Initial DDL is idempotent (`CREATE TABLE IF NOT EXISTS`) in the module's `ModuleInstaller`; later changes ship as Doctrine migrations.
- Each module has its own permission (`elevate_dxp_<module>`). Never make a resource available without one.
- Prefer schema-driven admin resources over custom JavaScript. Escape all dynamic values returned with `Action::html()`.
- Throw `\InvalidArgumentException` (or `\DomainException`) for user errors; the controller turns them into readable 400 responses.
- Public endpoints: deny by default, uniform error responses, rate limits or tokens where applicable, no secrets in responses or logs, audit through `AuditLoggerInterface`.
- See [docs/architecture.md](docs/architecture.md) for the full recipe.

### Documentation

- Update `docs/` in the same pull request as the code: configuration keys, defaults, commands, admin actions.
- Add an entry under `[Unreleased]` in `CHANGELOG.md` (Added, Changed, Deprecated, Removed, Fixed, Security).
- English, concise, with examples that work against the current code.

### Commits

Use [Conventional Commits](https://www.conventionalcommits.org/en/v1.0.0/):

```
<type>(<scope>): <summary in the imperative, lower case, no period>

<body: what and why>

<footer: BREAKING CHANGE: ..., Refs #123>
```

- Types: `feat`, `fix`, `docs`, `refactor`, `perf`, `test`, `build`, `ci`, `chore`, `revert`.
- Scope: the module key (`core`, `experiments`, `insights`, `feed`, `export`, `datahub`, `webhook`, `automation`, `statistics`, `dam_metadata`, `translation`, `portal`, `workflow`, `sso`) or `docs`, `deps`.

Examples:

```
feat(experiments): add time window targeting condition
fix(feed): return 404 for tokens shorter than 16 characters
docs(webhook): document signature verification in n8n
```

## Pull requests

1. Open an issue first for larger changes, so the design can be discussed.
2. Branch from `main`, keep pull requests focused on one topic.
3. Make sure `composer test`, `composer stan` and `composer cs` pass.
4. Describe what changed, why, and how you tested it (unit tests, manual steps in the admin).
5. Do not include unrelated formatting changes or generated files (`vendor/`, `var/`, caches).

## Reporting bugs and security issues

- Bugs: open an issue with OpenDXP version, PHP version, Elevate DXP version, configuration excerpt (without secrets), steps to reproduce, expected and actual result.
- Security issues: **do not open a public issue**. Follow [SECURITY.md](SECURITY.md).
