# thumbregen

[![CI](https://github.com/marsaldev/thumbregen/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/marsaldev/thumbregen/actions/workflows/ci.yml)
[![Latest release](https://img.shields.io/github/v/release/marsaldev/thumbregen)](https://github.com/marsaldev/thumbregen/releases/latest)
[![PrestaShop](https://img.shields.io/badge/PrestaShop-8.0%20%7C%208.1%20%7C%208.2%20%7C%209.0-DF0067)](#compatibility)
[![PHP](https://img.shields.io/badge/PHP-%E2%89%A5%207.2.5-777BB4?logo=php&logoColor=white)](#compatibility)
[![License](https://img.shields.io/github/license/marsaldev/thumbregen)](LICENSE)
[![Downloads](https://img.shields.io/github/downloads/marsaldev/thumbregen/total)](https://github.com/marsaldev/thumbregen/releases)

*Versione italiana: [README-IT.md](README-IT.md)*

PrestaShop module **with no back-office interface** that adds two Symfony Console commands to regenerate product image thumbnails selectively (by product, by category, by image type). It is designed for large catalogs and for running from cron.

- PrestaShop **8.0 → 9.0**, PHP **>= 7.2.5** (see [Compatibility](#compatibility))
- No hooks, no tables, no configuration: installing the module only registers the commands.
- Original images are never modified: only the derived thumbnails are regenerated.

## Compatibility

| PrestaShop | PHP supported by PrestaShop | Thumbnail formats generated | Tested in CI on |
|---|---|---|---|
| 8.0.x | 7.2 – 8.1 | jpg | ✅ PHP 7.2, 8.1 |
| 8.1.x | 7.2 – 8.1 | jpg + webp/avif/png enabled in *Design > Image Settings* (requires the *Multiple image formats* feature flag) | ✅ PHP 7.2, 8.1 |
| 8.2.x | 7.2 – 8.1 | same as 8.1 | ✅ PHP 7.2, 8.1 |
| 9.0.x | 8.1 – 8.4 | jpg + webp/avif/png enabled in *Design > Image Settings* | ✅ PHP 8.1, 8.4 |
| 1.7.x and older | | | ❌ not supported |

Every push and a weekly scheduled run install each PrestaShop version above with its demo catalog in Docker ([official `prestashop/prestashop` images](https://hub.docker.com/r/prestashop/prestashop)), install the module and run the commands against it ([`tests/integration/run.sh`](tests/integration/run.sh)). The tests cover:
- regeneration by product, by category with subcategories and for the whole catalog (product counts checked against the database);
- `--type`, `--delete-old` and `--dry-run`, plus webp generation on 8.1+;
- invalid arguments (exit `1`), corrupt originals (skipped, exit `0`) and unwritable image folders (exit `2`).

The PHP files are also linted on every PHP version from 7.2 to 8.4.

To run the integration tests locally (requires Docker):

```bash
tests/integration/run.sh 8.2-8.1    # any prestashop/prestashop image tag, <PrestaShop>-<PHP>
```

## Installation

1. Download `thumbregen-X.Y.Z.zip` from the [GitHub releases](https://github.com/marsaldev/thumbregen/releases) and extract it into `modules/`, or upload it from the back office (*Modules > Module Manager > Upload a module*). The release zip already includes `vendor/autoload.php`, so no `composer install` is needed on the server.
   From a git clone instead, run `composer dump-autoload -o` in the module folder first: `vendor/` is not committed.
2. Install the module from the back office or from the CLI:
   ```bash
   php bin/console prestashop:module install thumbregen
   ```
3. **Clear the Symfony cache**, otherwise the commands do not show up:
   ```bash
   php bin/console cache:clear --no-warmup            # dev environment (the console default)
   php bin/console cache:clear --no-warmup --env=prod # if you run the command with --env=prod
   ```
4. Verify:
   ```bash
   php bin/console list thumbregen
   ```

The same `cache:clear` is needed after every module update.

For long runs, use `--env=prod --no-debug`: in the dev environment Symfony's debug mode uses more memory and turns PHP warnings into exceptions (the module catches them image by image anyway).

## Commands

### `thumbregen:products:list-types`

Lists the image types enabled for products (name, size, 2x variant if high resolution is enabled) and the formats that will be generated.

```bash
php bin/console thumbregen:products:list-types
```

### `thumbregen:products:regenerate`

```bash
php bin/console thumbregen:products:regenerate [options]
```

| Option | Description |
|---|---|
| `--product=ID` | Limit to a single product |
| `--category=ID` | Limit to the products associated with the category (`category_product`, so products with a different default category are included too) |
| `--with-subcategories` | With `--category`, include the subcategories (nested set `nleft`/`nright`). It must be specified explicitly: without it, subcategories are excluded |
| `--type=NAME` | Thumbnail type (e.g. `home_default`). Repeatable, also accepts `--type=a,b`. Default: all product types |
| `--shop-id=ID` | Shop context in multistore. Default: `PS_SHOP_DEFAULT` |
| `--delete-old` | First delete the existing thumbnails **of the selected types only** (all jpg/png/webp/avif extensions and the 2x variants). Default: overwrite |
| `--dry-run` | List the products, images and files that would be generated/deleted, without writing anything (also applies with `--delete-old`) |
| `--batch-size=N` | Products per batch, with memory released between batches. Default `50` |
| `--memory-limit=VAL` | PHP `memory_limit` for the run. Default `1G` (`-1` = unlimited). The PrestaShop + Symfony bootstrap alone already takes 50-110 MB, so do not go below `256M` |

`--product` and `--category` are mutually exclusive; `--with-subcategories` requires `--category`. With neither, the whole catalog is processed.

#### Examples

| Case | Command |
|---|---|
| All images of all products | `thumbregen:products:regenerate` |
| All images of one product | `thumbregen:products:regenerate --product=42` |
| All images of one category | `thumbregen:products:regenerate --category=12` |
| Same, with subcategories | `thumbregen:products:regenerate --category=12 --with-subcategories` |
| One thumbnail for all products | `thumbregen:products:regenerate --type=home_default` |
| One thumbnail for one product | `thumbregen:products:regenerate --product=42 --type=home_default` |
| One thumbnail for one category (± subcategories) | `thumbregen:products:regenerate --category=12 --with-subcategories --type=home_default` |
| Preview what would be done | add `--dry-run -v` to any of the above |

#### Cron

```cron
# Every night at 3:15: errors in the log, cron mail only if the exit code is not 0
15 3 * * * cd /var/www/shop && php bin/console --env=prod --no-debug thumbregen:products:regenerate --type=home_default -q >> var/logs/thumbregen.log 2>&1 || echo "thumbregen exit $? - see var/logs/thumbregen.log"
```

cron ignores the exit code: it sends the mail (to `MAILTO`) only if the command produces output. With `>> … 2>&1` everything goes to the log, so without the `|| echo` a run with errors would be indistinguishable from a successful one. With the `|| echo`, nothing is sent when the run succeeds (`0`); with `1` or `2` you get a mail with the code, and the errors are in the log.

## Output

- Progress bar over the products (not in `--dry-run`, where the list of products with their images is printed instead).
- Final summary: products, products without images, images, thumbnails generated, old thumbnails deleted, skipped images, warnings, errors, DB reconnections, total time, peak memory. Skipped images and errors are listed (up to 200 messages each).
- `-v`: one line per image (in `--dry-run` also the planned files); `-vv`: every generated/deleted file; `-q`: errors only (on stderr).

### Exit codes

| Code | Meaning |
|---|---|
| `0` | All good, even with warnings (e.g. missing or corrupt original images, skipped) |
| `1` | Invalid arguments or fatal error (e.g. image directory not writable, DB unreachable) |
| `2` | Run completed, but with errors on some images (e.g. resize failed, permissions) |

What leads to `2` and what stays a plain warning (exit `0`):

| Error (exit `2`) | Warning / skipped (exit `0`) |
|---|---|
| Resize failed for a format or size | Original `{id_image}.jpg` missing, empty or corrupt (image skipped) |
| Image folder not writable | Watermark module returning `false` |
| Old thumbnail cannot be deleted (`--delete-old`) | |
| Exception thrown by a watermark module (thumbnail generated but not watermarked) | |

A fatal error halfway through the run (e.g. DB still unreachable after the reconnection attempts) returns `1` even if some products were already processed: the printed summary shows how far it got. `--dry-run` writes nothing, so the only possible outcomes are `0` and `1`.

## Technical behavior

- **Limits**: at startup `set_time_limit(0)` and `ini_set('memory_limit', …)`; if `ini_set` is disabled or refused, a warning is printed and the run continues.
- **Selection**: pagination by product ID (keyset), the whole catalog is never loaded in memory. In multistore, products and images are filtered with `product_shop` / `image_shop` of the selected shop.
- **Regeneration**: same path as the back office (`AdminImagesController::_regenerateNewImages`): `ImageManager::resize()` on the original `{id_image}.jpg` with the width/height of the `ImageType`, for every configured format and, if `PS_HIGHT_DPI` is enabled, the `2x` variant too. The actual format of the `.jpg` files is still decided by `PS_IMAGE_QUALITY`, as in the core.
- **Version differences** (isolated in `ThumbnailRegenerator::resolveGenerationFormats()`):
  - PS 8.0: `.jpg` only.
  - PS 8.1 / 8.2: `jpg` plus the formats enabled in *Design > Image Settings* (webp, avif, png) through `ImageFormatConfiguration`, if the `multiple_image_format` feature flag is enabled.
  - PS 9.0: same as 8.1 / 8.2, without the feature flag.
- **Watermark**: after generation, for each image the module calls `hookActionWatermark` on the active modules hooked to `actionWatermark` in the shop, passing only the selected types in `image_type` (as the core does). The official `watermark` module applies the watermark to the types configured in its panel: if a selected type is among them, the thumbnail is overwritten with the watermarked version, as happens from the back office.
  **Warning**: the `watermark` module 2.0.0 does not really filter by `image_type` (its `array_intersect` on arrays of arrays always matches), so for every processed image it also rewrites the other types configured in its panel (`{id}-{type}.jpg` and `{id}-{type}-{hash}.jpg`), with the same result as before. The back office behaves the same way. The watermark module only works on `.jpg` files (neither webp/avif nor 2x).
- **Per-image errors**: missing or corrupt original ⇒ warning, image skipped, run continues. Resize failed ⇒ error, run continues. PHP warnings and exceptions from core/third-party modules are caught per image.
- **Resources**: after each batch `Cache::clean('*')` and `gc_collect_cycles()`; no `Product` object is instantiated, and `Image` objects are built without queries and released immediately.
- **DB connection**: the connection is checked at startup and before each batch. On "MySQL server has gone away" / "Lost connection" the module reconnects (up to 3 attempts, with backoff) and retries the query. If the connection drops inside core or third-party module code (e.g. the watermark module instantiating `Image`), that call is also retried once after reconnecting.
- **No writes**: the module does not write to the database or to configuration files, and does not touch the original images.

## Structure

```
thumbregen/
├── thumbregen.php                 # module class (install/uninstall only)
├── composer.json                  # PSR-4 autoload Marsaldev\Module\ThumbRegen\ => src/
├── config/services.yml            # services + commands (console.command tag)
├── vendor/                        # generated autoload (composer dump-autoload -o), not committed, included in the release zip
└── src/
    ├── Command/
    │   ├── RegenerateProductThumbnailsCommand.php
    │   └── ListImageTypesCommand.php
    ├── Model/
    │   ├── ImageResult.php        # outcome of a single image
    │   ├── RunReport.php          # run counters and messages
    │   └── SelectionCriteria.php  # product / category (± subcategories) / catalog
    └── Service/
        ├── DatabaseClient.php     # read-only queries with reconnection
        ├── ImageTypeProvider.php  # product image types and --type validation
        ├── ProductSelector.php    # batched product and image selection
        └── ThumbnailRegenerator.php
```

After editing `composer.json` or adding classes: `composer dump-autoload -o` in the module folder.
