# HTML to Blocks Converter

Legacy HTML-to-Gutenberg-blocks facade over Blocks Engine raw conversion.

## Architecture Overview

### Retained Facade Components

| File | Responsibility |
|------|---------------|
| `html-to-blocks-converter.php` | Plugin bootstrap, WordPress hook registration |
| `raw-handler.php` | Blocks Engine facade, shortcode handling, result envelope |
| `includes/class-html-element.php` | Retained facade helper for parsing source snippets needed by wrapper diagnostics |
| `includes/class-block-factory.php` | Retained facade helper for serializing wrapper-owned fallback blocks |

### Conversion Pipeline Flow

1. `html_to_blocks_convert_on_insert()` - Hooks into `wp_insert_post_data` filter
2. `html_to_blocks_raw_handler()` - Main entry point, handles shortcode preservation
3. `html_to_blocks_normalise_blocks()` - Preserves historical wrapper input behavior before delegation
4. `html_to_blocks_convert()` - Delegates conversion to Blocks Engine `HtmlTransformer`
5. H2BC adapts Blocks Engine results into legacy block arrays, fallback hooks, and result envelopes without owning the transform rules

### Key Technical Decisions

- **Blocks Engine delegation**: H2BC no longer owns the canonical conversion runtime
- **Facade compatibility**: Existing public raw-handler/result APIs remain backed by Blocks Engine output

## Delegated Block Output

Blocks Engine owns canonical HTML-to-block conversion. Keep h2bc documentation
and tests focused on the public facade behavior: raw-handler block arrays,
result envelopes, fallback hooks, metrics hooks, and automatic write/read hooks.

Use `docs/core-block-coverage.md` for the current Blocks Engine-backed support
matrix instead of documenting internal conversion priority here.

## Public API

### Functions

- `html_to_blocks_raw_handler( array $args )` - Direct HTML-to-blocks conversion
  - `$args['HTML']` - HTML string to convert
  - Returns: Array of block arrays

### Filters

- `html_to_blocks_supported_post_types` - Modify supported post types (default: `['post', 'page']`)

## Requirements

- WordPress 6.4+ (WP_HTML_Processor dependency)
- PHP 8.1+

## Class Reference

### HTML_To_Blocks_HTML_Element

DOM-like interface over WP_HTML_Processor:
- `from_html( string $html )` - Static constructor
- `get_tag_name()` - Returns uppercase tag name
- `get_attribute( string $name )` - Get attribute value
- `has_attribute( string $name )` - Check attribute exists
- `get_inner_html()` - Get inner HTML content
- `get_outer_html()` - Get full element HTML
- `get_text_content()` - Get stripped text content
- `query_selector( string $selector )` - Find descendant element
- `query_selector_all( string $selector )` - Find all matching descendants
- `get_child_elements()` - Get direct child elements

### HTML_To_Blocks_Block_Factory

- `create_block( string $name, array $attributes, array $inner_blocks )` - Creates block array structure
