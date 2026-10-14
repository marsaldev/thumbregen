# Changelog

All notable changes to this module are documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2026-10-14

### Added
- `thumbregen:products:regenerate` console command: regenerates product thumbnails by product (`--product`), category (`--category`, `--with-subcategories`) and image type (`--type`), with `--delete-old`, `--dry-run`, `--shop-id`, `--batch-size` and `--memory-limit`.
- `thumbregen:products:list-types` console command: lists the product image types and the formats that will be generated.
- Support for PrestaShop 8.0 (jpg only) and 8.1+ (jpg plus the webp/avif/png formats enabled in the image settings), high-DPI `2x` variants and `actionWatermark` modules.
- Automatic reconnection on "MySQL server has gone away" during long runs.
- Exit codes: `0` success, `1` invalid arguments or fatal error, `2` completed with errors on some images.

[Unreleased]: https://github.com/marsaldev/thumbregen/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/marsaldev/thumbregen/releases/tag/v1.0.0
