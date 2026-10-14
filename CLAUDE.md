# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

`thumbregen` is a CLI-only PrestaShop module (no back-office page, hooks, DB tables or configuration) that registers two Symfony console commands to regenerate product thumbnails selectively by product, category (± subtree) and image type. It is meant for large catalogs and cron runs. It is its own git repository (github.com/marsaldev/thumbregen), usually developed in place as `modules/thumbregen/` inside a PrestaShop 8 store checkout. The store root is `../../` (it may have its own `CLAUDE.md` with store-wide context). `README.md` is the user-facing spec: options, exit codes, output verbosity and technical behavior. `README-IT.md` is its Italian translation. Keep both in sync when behavior changes.

Hard invariants from the README:
- **Read-only on the DB and on originals.** It never writes to the database or config, and never touches `{id_image}.jpg`. Only derived thumbnails are written or deleted.
- `--delete-old` deletes only thumbnails of the **selected** types (all of jpg/png/webp/avif plus 2x variants). Other types stay untouched.
- `--dry-run` must not write anything, including with `--delete-old`.
- Exit codes: `0` ok (warnings allowed), `1` invalid args/fatal, `2` completed with per-image errors.

## Compatibility constraints

- Targets **PrestaShop 8.0–9.0** and **PHP >= 7.2.5** (`composer.json` pins `platform.php` to 7.2.5). The existing code uses no scalar/return type declarations, no typed properties and no `strict_types`. Don't introduce PHP 7.4+/8.x syntax. The local CLI may be a much newer PHP, so a passing `php -l` doesn't prove 7.2 compatibility.
- Symfony 4.4 on PS 8.0: there are no `Command::SUCCESS/FAILURE` constants, so the command defines its own `EXIT_*` constants.
- Every service in `config/services.yml` needs an explicit `class:` because module services load after Symfony's `ResolveClassPass` (PS 8.0 container compilation fails otherwise).
- Core version differences are confined to `ThumbnailRegenerator::resolveGenerationFormats()`: PS 8.0 is jpg only, and 8.1+ (9.0 included, where the feature flag is gone) uses `ImageFormatConfiguration` (looked up via `class_exists`) for webp/avif/png. Keep version branching there.

## Architecture

PSR-4 `Marsaldev\Module\ThumbRegen\` → `src/`. Wiring is entirely in `config/services.yml`, and commands are tagged `console.command`. `thumbregen.php` only does install/uninstall and requires `vendor/autoload.php`.

Flow of `thumbregen:products:regenerate` (`src/Command/RegenerateProductThumbnailsCommand.php`):
1. Parse and validate options, then apply `set_time_limit(0)` and `memory_limit`. Load the shop context via `prestashop.adapter.legacy_context_loader` (`--shop-id`, default `PS_SHOP_DEFAULT`).
2. Build a `Model\SelectionCriteria` (product | category ± subcategories via nested set `nleft/nright` | whole catalog). Category matching uses `category_product`, not `id_category_default`.
3. `Service\ProductSelector` pages product IDs with **keyset pagination** (`id_product > last`), filtered through `product_shop`/`image_shop` for the shop. The full catalog is never loaded.
4. For each batch, `DatabaseClient::ensureConnection()` runs, then `Service\ThumbnailRegenerator::processImage()` handles each image, then `Cache::clean('*')` + `gc_collect_cycles()`.
5. `Model\ImageResult` (per image) is aggregated into `Model\RunReport` (counters and capped messages), which drives the summary and the exit code.

Key design points spread across files:
- **`ThumbnailRegenerator` mirrors the core BO path** (`AdminImagesController::_regenerateNewImages` + `_regenerateWatermark`). It calls `ImageManager::resize()` per type × format, plus 2x when `PS_HIGHT_DPI` is on. The real encoding of `.jpg` follows `PS_IMAGE_QUALITY`. It then calls `hookActionWatermark` directly on the modules hooked to `actionWatermark` in the shop, passing only the selected types. `Image` objects are built by hand (no `new Image($id)` DB round-trip) and no `Product` is ever instantiated. Preserve this to keep memory flat.
- **`guard()`/`guardOnce()`** wrap all core and third-party calls. They install a temporary error handler that respects `@` and ignores deprecations, and they catch throwables so that one bad image never stops the run. On a DB connection error they call `DatabaseClient::recover()` and retry once (resize and watermark are idempotent).
- **`DatabaseClient`** is a read-only wrapper over legacy `Db`. On "server has gone away"/"lost connection" (MySQL codes plus PDO messages) it reconnects with exponential backoff (up to 3 attempts) and retries. All module SQL should go through it, not `Db::getInstance()` directly.
- Error reporting: missing or corrupt original → warning + skip. Resize failure → error, and the run continues. Errors go to stderr so they still show with `-q`.
- The official `watermark` 2.0.0 module ignores the `image_type` filter, so it rewrites all of its configured types on every image. This is known and documented, matches BO behavior, and is not a bug to fix here.

## Commands

From the store root (`../../`):

```bash
php bin/console cache:clear --no-warmup               # required after any change to services.yml / new classes / install
php bin/console list thumbregen                        # verify commands are registered
php bin/console thumbregen:products:list-types
php bin/console thumbregen:products:regenerate --product=42 --type=home_default --dry-run -v
php bin/console --env=prod --no-debug thumbregen:products:regenerate ...   # long runs (dev debug turns warnings into exceptions)
```

`bin/console` defaults to the **dev** env unless `SYMFONY_ENV` is set, so clear the cache of the env you actually run in. If the commands don't appear, check `var/cache/<env>`, check that `ps_module.active = 1`, and check that `vendor/` was deployed.

From this module directory:

```bash
composer dump-autoload -o    # after cloning, editing composer.json or adding classes; vendor/ is gitignored but shipped inside the release zip
php -l src/Service/ThumbnailRegenerator.php
```

Integration tests (Docker required): `tests/integration/run.sh <prestashop/prestashop tag>` (for example `8.0-7.2`, `8.2-8.1`, `9.0-8.4`). The script installs PrestaShop with its demo catalog in throwaway containers, installs the module from the working tree (it generates `vendor/` with `COMPOSER_BIN`, default `composer`) and asserts on exit codes, output and the thumbnail files. Set `KEEP=1` to keep the containers for debugging. A PrestaShop install takes about 20–60 s. `.github/workflows/ci.yml` runs the same script on the PS × PHP matrix listed in the README compatibility table, plus `php -l` on PHP 7.2–8.4. Keep that table, the workflow matrix and the README badges in sync.

There are no unit tests, and the store-root `composer php-cs-fixer`/`phpstan` configs don't cover `modules/`.
