# HTML to Blocks Converter

A WordPress plugin that automatically converts raw HTML to Gutenberg blocks when inserting posts via the REST API or `wp_insert_post()`.

## Description

This plugin provides server-side HTML-to-blocks conversion using WordPress Core's HTML API (`WP_HTML_Processor`) for spec-compliant HTML5 parsing. Inspired by Gutenberg's client-side `rawHandler` function from [`packages/blocks/src/api/raw-handling`](https://github.com/WordPress/gutenberg/tree/trunk/packages/blocks/src/api/raw-handling), it enables programmatic content creation with proper block structure without requiring the block editor.

### Use Cases

- Migrating legacy content to Gutenberg blocks
- Importing content from external sources via REST API
- Programmatically creating posts with block-based content
- Converting HTML from headless CMS or content pipelines

## Supported Block Transforms

The plugin converts the following HTML elements to their corresponding Gutenberg blocks:

| HTML Element | Block Type |
|-------------|------------|
| `<h1>` - `<h6>` | `core/heading` |
| `<p>` | `core/paragraph` |
| `<ul>`, `<ol>` | `core/list` with `core/list-item` children |
| `<blockquote>` | `core/quote` |
| `<figure><img>` | `core/image` |
| `<img>` | `core/image` |
| `<pre><code>` | `core/code` |
| `<pre>` | `core/preformatted` |
| `<hr>` | `core/separator` |
| `<table>` | `core/table` |

Nested lists and blockquotes with multiple paragraphs are fully supported.

## Installation

1. Download the plugin zip file
2. Navigate to Plugins > Add New > Upload Plugin
3. Upload the zip file and activate

Or clone directly to your plugins directory:

```bash
cd wp-content/plugins
git clone https://github.com/chubes4/html-to-blocks-converter.git
```

## Usage

The plugin hooks into `wp_insert_post_data` and automatically converts HTML content to blocks. No configuration required.

### Programmatic Usage

```php
// Content will be automatically converted to blocks
wp_insert_post([
    'post_title'   => 'My Post',
    'post_content' => '<h1>Hello World</h1><p>This is my content.</p>',
    'post_status'  => 'publish',
    'post_type'    => 'post',
]);
```

### REST API Usage

```bash
curl -X POST https://yoursite.com/wp-json/wp/v2/posts \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "My Post",
    "content": "<h1>Hello World</h1><p>This is my content.</p>",
    "status": "publish"
  }'
```

### Direct Conversion

```php
$html = '<h1>Title</h1><p>Paragraph with <strong>bold</strong> text.</p>';
$blocks = html_to_blocks_raw_handler(['HTML' => $html]);
$block_content = serialize_blocks($blocks);
```

## Filters

### `html_to_blocks_supported_post_types`

Modify which post types support automatic HTML-to-blocks conversion.

```php
add_filter('html_to_blocks_supported_post_types', function($post_types) {
    $post_types[] = 'custom_post_type';
    return $post_types;
});
```

Default: `['post', 'page']`

## Architecture

The plugin uses WordPress Core's HTML API for parsing:

- **HTML Element Adapter** - DOM-like interface over `WP_HTML_Processor` for familiar traversal methods
- **Transform Registry** - PHP port of block transforms from `packages/block-library/src/*/transforms.js`
- **Block Factory** - Creates block arrays compatible with `serialize_blocks()`
- **Raw Handler** - Main conversion pipeline using `WP_HTML_Processor::create_fragment()`
- **Attribute Parser** - Extracts block attributes from HTML using WordPress HTML API

### Why WordPress HTML API?

- HTML5 spec-compliant parsing that matches browser behavior
- Proper UTF-8 character encoding handling
- Correct handling of implied/virtual tags
- WordPress Core maintained and security hardened
- Future-proof as the API continues to improve

## Requirements

- WordPress 6.4+ (required for `WP_HTML_Processor`)
- PHP 7.4+

## License

GPL v2 or later

## Credits

Directly inspired by the [Gutenberg](https://github.com/WordPress/gutenberg) project's client-side raw handling implementation.

## Author

[Chris Huber](https://chubes.net)
