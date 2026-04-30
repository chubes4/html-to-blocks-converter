# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.6.6] - 2026-04-29

### Fixed
- preserve navigation markup as fallback

## [0.6.5] - 2026-04-29

### Fixed
- include color support classes in static HTML

## [0.6.4] - 2026-04-29

### Fixed
- serialize paragraph style supports

## [0.6.3] - 2026-04-29

### Fixed
- split multi-anchor CTA rows into buttons
- preserve static navigation classes before serialization

## [0.6.2] - 2026-04-29

### Fixed
- preserve preformatted wrapper classes
- avoid duplicate classed list wrappers
- reduce static chrome html fallbacks

## [0.6.1] - 2026-04-29

### Changed
- align media-text fixture expectations
- gate core block coverage docs
- gate core block inventory classification
- tighten smoke harness lint contracts

### Fixed
- avoid duplicate descendants in raw conversion
- honor block attrs during static serialization
- clean production PHPStan findings

## [0.6.0] - 2026-04-28

### Added
- preserve explicit block support signals
- support explicit Site Editor markers
- convert static navigation HTML
- map mechanical block supports from HTML

### Changed
- cover explicit Site Editor primitive markers

## [0.5.1] - 2026-04-28

### Changed
- add core transform matrix smoke

## [0.5.0] - 2026-04-28

### Added
- expose unsupported html fallback hook
- add media embed raw transforms
- add action text raw transforms
- add conservative layout raw transforms
- support dual-mode package loading

### Changed
- isolate scoped REST smoke globals
- make scoped REST smoke prefix-safe
- keep scoped REST smoke namespace-safe
- add Gutenberg rawHandler parity fixtures
- run smoke tests on pull requests
- cover raw handler fixture fallbacks

### Fixed
- register scoped REST callback safely
- support landmark containers
- namespace-safe callback for php-scoper compatibility
- register hooks in package mode
- make package autoload no-op outside WordPress

## [0.4.0] - 2026-04-15

### Added
- HTML→blocks conversion on REST API read path for the block editor — posts with raw HTML in `content.raw` are automatically converted to block markup when the editor requests `context=edit`

### Fixed
- Register REST filters at `init` priority 20 so custom post types (e.g. Intelligence wiki) are available when `get_post_types()` is called

## [0.2.3] - 2026-01-18

### Fixed
- Fixed block detection to check if content contains blocks anywhere, not just at the start
- Added content loss prevention that aborts conversion when >70% of text content would be lost

## [0.2.2] - 2026-01-08

### Added

- Enhanced error logging and content loss detection
- Added validation checks for `WP_HTML_Processor` failures
- Improved handling of element extraction failures with detailed logs

### Fixed

- Added detection for significant content loss during conversion process
- Improved robustness of HTML fragment processing

## [0.2.1] - 2026-01-07

### Changed

- Default supported post types now include all public REST API post types (via `get_post_types()` with `public` + `show_in_rest`), instead of only `post` and `page`

## [0.2.0] - 2025-11-27

### Changed

- Migrated HTML parsing from PHP's DOMDocument to WordPress Core's HTML API (`WP_HTML_Processor`)
- HTML5 spec-compliant parsing that matches browser behavior
- Proper UTF-8 character encoding handling
- Improved handling of nested elements (lists, blockquotes, tables)

### Added

- `HTML_To_Blocks_HTML_Element` adapter class for DOM-like interface over WordPress HTML API
- WordPress 6.4+ version requirement check with admin notice

### Fixed

- Fixed duplicate content when processing multiple elements of the same tag type by using occurrence-based element tracking

### Technical

- Replaced `DOMDocument::loadHTML()` with `WP_HTML_Processor::create_fragment()`
- Replaced DOM traversal with token-based iteration
- Added occurrence-based element extraction for accurate sequential processing
- Transform callbacks now receive `HTML_To_Blocks_HTML_Element` instead of `DOMNode`

## [0.1.0] - 2025-11-26

### Added

- Initial release
- Server-side HTML to Gutenberg blocks conversion
- Support for core block transforms:
  - `core/heading` (h1-h6)
  - `core/paragraph` (p)
  - `core/list` and `core/list-item` (ul, ol, li with nested list support)
  - `core/quote` (blockquote with inner block support)
  - `core/image` (figure with img, standalone img)
  - `core/code` (pre > code)
  - `core/preformatted` (pre without code)
  - `core/separator` (hr)
  - `core/table` (table with thead, tbody, tfoot)
- Automatic conversion on `wp_insert_post()` and REST API post creation
- `html_to_blocks_supported_post_types` filter for customizing supported post types
- `html_to_blocks_raw_handler()` function for direct conversion
- Shortcode preservation during conversion
- Inline content normalization (wraps orphan text in paragraphs)
