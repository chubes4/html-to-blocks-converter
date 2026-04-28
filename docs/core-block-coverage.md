# Core Block Coverage Matrix

This matrix is the source of truth for which WordPress core blocks
`html-to-blocks-converter` may infer from raw HTML.

`html-to-blocks-converter` is a deterministic raw-transform layer. It converts
HTML only when the fragment itself contains enough signal to choose a block
without site, template, query, or editor state. Unsupported or ambiguous
fragments are preserved as `core/html` rather than guessed.

## Status Definitions

| Status | Meaning |
|---|---|
| `supported` | h2bc has a deterministic raw transform for this block family. |
| `fallback-observed` | h2bc intentionally preserves this HTML as `core/html` when no safe transform matches. |
| `context-required` | The block needs site, template, query, post, comment, navigation, or theme context that raw HTML does not carry. |
| `future candidate` | A deterministic transform may be possible later, but no transform ships today. |

## Supported Static Transforms

| Block name/family | Status | Required HTML signal | Test coverage file | Notes |
|---|---|---|---|---|
| `core/heading` | `supported` | `<h1>` through `<h6>` | Transform registry; exercised indirectly by `tests/smoke-layout-transforms.php` | Always maps to a static heading. Site, post, and query title identity is context-required. |
| `core/paragraph` | `supported` | Plain text or `<p>` content that does not match a higher-priority transform | Transform registry; exercised indirectly by `tests/smoke-action-text-transforms.php`, `tests/smoke-layout-transforms.php`, `tests/smoke-media-embed-transforms.php` | Lowest-priority text fallback before `core/html`. |
| `core/list`, `core/list-item` | `supported` | `<ul>` or `<ol>` with `<li>` children | Transform registry | Nested lists are supported. |
| `core/quote` | `supported` | `<blockquote>` without an explicit pullquote signal | `tests/smoke-action-text-transforms.php` | Nested static content is routed back through the raw handler. |
| `core/image` | `supported` | `<img>` or `<figure><img>` | `tests/smoke-media-embed-transforms.php` | Preserves static image attributes and captions when present; does not infer media-library attachment identity unless a safe `wp-image-*` class is present. |
| `core/code` | `supported` | `<pre><code>` | Transform registry | Code-specific transform wins over plain preformatted text. |
| `core/preformatted` | `supported` | `<pre>` without a verse or code signal | Transform registry | Preserves preformatted static text. |
| `core/separator` | `supported` | `<hr>` | Transform registry | Direct static transform. |
| `core/table` | `supported` | `<table>` | Transform registry | Static table transform only; no data-source or query inference. |
| `core/shortcode` | `supported` | WordPress shortcode text matched by `get_shortcode_regex()` | `tests/GutenbergRawHandlerParityUnitTest.php` | Mirrors Gutenberg rawHandler's shortcode conversion path for WordPress shortcodes. |
| `core/group` | `supported` | High-confidence semantic wrapper such as `<section>` or explicit grouping classes | `tests/smoke-layout-transforms.php` | Arbitrary `<div>` does not qualify. Inner content recurses through the raw handler. |
| `core/columns`, `core/column` | `supported` | Explicit row/grid wrapper plus direct column-like children | `tests/smoke-layout-transforms.php` | Requires layout classes such as `row` and `col-*`; ambiguous wrappers fall back. |
| `core/cover` | `supported` | Hero/cover wrapper with explicit background image or color signal | `tests/smoke-layout-transforms.php` | Preserves static background values and routes inner content through the raw handler. |
| `core/spacer` | `supported` | Empty explicit spacer element with an explicit height | `tests/smoke-layout-transforms.php` | Content-bearing or heightless wrappers do not qualify. |
| `core/buttons`, `core/button` | `supported` | Button-like anchor, such as `.btn`, `.button`, or `.wp-block-button__link` | `tests/smoke-action-text-transforms.php` | Ordinary inline links stay inside paragraph content. |
| `core/details` | `supported` | `<details>` with optional `<summary>` | `tests/smoke-action-text-transforms.php` | Summary becomes an attribute; body content recurses through the raw handler. |
| `core/pullquote` | `supported` | `<blockquote>` with explicit pullquote signal, such as `.wp-block-pullquote` | `tests/smoke-action-text-transforms.php` | Ordinary blockquotes remain `core/quote`. |
| `core/verse` | `supported` | `<pre>` with explicit verse signal, such as `.wp-block-verse` | `tests/smoke-action-text-transforms.php` | Preserves line breaks and inline `<br>` tags. |
| `core/video` | `supported` | `<video src>`, `<video><source src>`, or `<figure>` containing a video with a source | `tests/smoke-media-embed-transforms.php` | Preserves static media attributes and figure captions where safe. |
| `core/audio` | `supported` | `<audio src>`, `<audio><source src>`, or `<figure>` containing audio with a source | `tests/smoke-media-embed-transforms.php` | Static transform only; no media-library identity inference. |
| `core/gallery` | `supported` | Gallery-like wrapper class plus multiple images | `tests/smoke-media-embed-transforms.php` | Builds `core/image` inner blocks. Ambiguous image collections without a gallery signal do not qualify. |
| `core/media-text` | `supported` | `.wp-block-media-text` or `media-text` wrapper containing image or video media plus content | `tests/smoke-media-embed-transforms.php` | Inner text content recurses through the raw handler. |
| `core/file` | `supported` | Anchor whose `href` has a recognized downloadable file extension | `tests/smoke-media-embed-transforms.php` | Ordinary CTA or navigation links do not become file blocks. |
| `core/embed` | `supported` | `<iframe src>` for a recognized provider URL | `tests/smoke-media-embed-transforms.php` | Recognized providers are normalized into static embed URLs; unknown iframes fall back. |

## Observed Fallbacks

| Block name/family | Status | Required HTML signal | Test coverage file | Notes |
|---|---|---|---|---|
| `core/html` | `fallback-observed` | Any unsupported or intentionally ambiguous top-level HTML fragment | `tests/smoke-unsupported-html-fallback-hook.php`, `tests/smoke-media-embed-transforms.php`, `tests/smoke-layout-transforms.php` | Fallback is the safe answer when no registered transform matches. The `html_to_blocks_unsupported_html_fallback` action makes these cases observable. |
| Unknown `<iframe>` providers | `fallback-observed` | `<iframe src>` whose provider cannot be mapped to a supported embed provider | `tests/smoke-media-embed-transforms.php`, `tests/smoke-unsupported-html-fallback-hook.php` | Preserving the iframe as custom HTML is safer than inventing an embed provider. |
| Arbitrary wrappers | `fallback-observed` | Generic `<div>` or wrapper markup without a high-confidence layout signal | `tests/smoke-layout-transforms.php` | Avoids treating every wrapper as a group, columns, cover, or spacer block. |
| Ordinary links | `fallback-observed` | `<a href>` without button or downloadable-file signal | `tests/smoke-action-text-transforms.php`, `tests/smoke-media-embed-transforms.php` | Links usually remain inline paragraph HTML or custom HTML depending on their surrounding fragment. |

## Context-Required And FSE Blocks

These block families are intentionally outside h2bc's raw-transform boundary. A
future full-site-editing compiler can choose them after it has site or template
intent, then delegate static fragments back to h2bc.

| Block name/family | Status | Required HTML signal | Test coverage file | Notes |
|---|---|---|---|---|
| `core/template-part` | `context-required` | None in raw HTML alone | `docs/fse-boundary.md` | Requires header, footer, sidebar, or named template-part role. |
| `core/navigation*` | `context-required` | None in raw HTML alone | `docs/fse-boundary.md` | Requires menu intent, route knowledge, link hierarchy, and often persistent navigation entities. |
| `core/site-title`, `core/site-logo`, `core/site-tagline` | `context-required` | None in raw HTML alone | `docs/fse-boundary.md` | Site identity blocks require site metadata. A rendered heading or image is not enough. |
| `core/post-title`, `core/post-content`, `core/post-excerpt`, `core/post-featured-image`, and related post-data blocks | `context-required` | None in raw HTML alone | `docs/fse-boundary.md` | Post title, date, author, excerpt, featured image, and content blocks require current post/template context. |
| `core/query*` | `context-required` | None in raw HTML alone | `docs/fse-boundary.md` | Query, query title, post template, pagination, and related blocks require loop intent and content-model context. |
| `core/comments*` | `context-required` | None in raw HTML alone | `docs/fse-boundary.md` | Comment template blocks require comment-query context and per-comment state. |
| Dynamic utility blocks | `context-required` | None in raw HTML alone | `docs/fse-boundary.md` | Latest posts, archives, categories, RSS, tag cloud, loginout, search, calendar, and similar blocks require site data intent. |

## Future Candidates

| Block name/family | Status | Required HTML signal | Test coverage file | Notes |
|---|---|---|---|---|
| Additional embed providers | `future candidate` | Provider-specific URL pattern that can be normalized safely | None | Add only when provider detection is deterministic and fallback remains lossless for unknown providers. |
| Additional static layout patterns | `future candidate` | Explicit class, ARIA, or structural signal that is not shared by generic wrappers | None | Must not regress arbitrary wrapper fallback behavior. |
| Static social links | `future candidate` | Explicit social-links wrapper plus provider-identifiable anchors | None | Needs a conservative signal so ordinary navigation does not become social links. |
