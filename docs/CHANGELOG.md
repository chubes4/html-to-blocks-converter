# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - 2024-11-26

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
