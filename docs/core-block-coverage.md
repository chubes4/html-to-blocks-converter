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
| `explicit-marker supported` | h2bc may produce this block only when explicit source markup names the exact static structure. |
| `compiler-only` | A higher-level compiler may produce this block after it has site, template, or content-model intent; h2bc must not infer it from rendered HTML. |
| `unsupported` | h2bc and the FSE compiler should not produce this block from raw HTML without a separate product/design contract. |
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
| `core/navigation`, `core/navigation-link`, `core/navigation-submenu` | `explicit-marker supported` | One `<nav>` with exactly one direct `<ul>` or `<ol>` whose direct `<li>` children contain one `<a href>` plus an optional nested list | `tests/smoke-static-navigation-transforms.php` | Static inline navigation only. h2bc never sets `ref`, creates `wp_navigation` posts, chooses menu locations, or infers site routes. Mixed-content nav falls back. |

## Mechanical Block Support Mappings

h2bc maps only direct, mechanical HTML attributes into block support attributes.
It does not infer theme tokens, palette slugs, typography presets, or creative
layout intent.

Supported direct mappings:

- `alignwide`, `alignfull`, `alignleft`, `aligncenter`, and `alignright` classes map to `align` where the target transform opts in.
- Safe source classes map to `className`; generated `wp-block-*` classes, alignment classes, and invalid class names are discarded.
- `id` maps to `anchor` where the target block supports anchors.
- `text-align: left|center|right` maps to `textAlign` on text transforms.
- `color` and `background` / `background-color` map to `style.color` custom values.
- `margin`, `margin-*`, `padding`, and `padding-*` map to `style.spacing` custom values.
- `border-color`, `border-style`, `border-width`, and `border-radius` map to `style.border` custom values.
- Semantic container tags (`section`, `main`, `article`, `aside`, `header`, `footer`, `nav`) map to `tagName` on group-like transforms that support wrapper semantics.
- Safe supported ARIA wrapper attributes (`aria-label`) are preserved on group-like transforms.

Intentionally deferred mappings:

- Theme palette, spacing, typography, or border preset token inference.
- Arbitrary CSS properties such as transforms, positioning, display, and custom properties.
- Layout intent that is not already explicit in the selected transform's source signal.

## Observed Fallbacks

| Block name/family | Status | Required HTML signal | Test coverage file | Notes |
|---|---|---|---|---|
| `core/html` | `fallback-observed` | Any unsupported or intentionally ambiguous top-level HTML fragment | `tests/smoke-unsupported-html-fallback-hook.php`, `tests/smoke-media-embed-transforms.php`, `tests/smoke-layout-transforms.php` | Fallback is the safe answer when no registered transform matches. The `html_to_blocks_unsupported_html_fallback` action makes these cases observable. |
| Unknown `<iframe>` providers | `fallback-observed` | `<iframe src>` whose provider cannot be mapped to a supported embed provider | `tests/smoke-media-embed-transforms.php`, `tests/smoke-unsupported-html-fallback-hook.php` | Preserving the iframe as custom HTML is safer than inventing an embed provider. |
| Arbitrary wrappers | `fallback-observed` | Generic `<div>` or wrapper markup without a high-confidence layout signal | `tests/smoke-layout-transforms.php` | Avoids treating every wrapper as a group, columns, cover, or spacer block. |
| Ordinary links | `fallback-observed` | `<a href>` without button or downloadable-file signal | `tests/smoke-action-text-transforms.php`, `tests/smoke-media-embed-transforms.php` | Links usually remain inline paragraph HTML or custom HTML depending on their surrounding fragment. |
| Mixed-content navigation | `fallback-observed` | `<nav>` that contains anything beyond one direct static list, or list items without direct links | `tests/smoke-static-navigation-transforms.php` | Preserving the fragment is safer than guessing whether non-list content is branding, search, actions, or persistent menu state. |

## Context-Required And FSE Blocks

These block families are intentionally outside h2bc's raw-transform boundary. A
future full-site-editing compiler can choose them after it has site or template
intent, then delegate static fragments back to h2bc.

| Block name/family | Status | Required HTML signal | Test coverage file | Notes |
|---|---|---|---|---|
| `core/template-part` | `compiler-only` | None in raw HTML alone | `docs/fse-boundary.md` | Requires header, footer, sidebar, or named template-part role. Explicit markers belong to a higher-level FSE compiler, not the raw handler. |
| Persistent `core/navigation` entities | `compiler-only` | None in raw HTML alone | `docs/fse-boundary.md` | Requires menu intent, route knowledge, menu-location selection, and `wp_navigation` post lifecycle. h2bc supports only inline static nav markup listed above. |
| `core/site-title`, `core/site-logo`, `core/site-tagline` | `compiler-only` | None in raw HTML alone | `docs/fse-boundary.md` | Site identity blocks require site metadata. A rendered heading or image is not enough. |
| `core/post-title`, `core/post-content`, `core/post-excerpt`, `core/post-featured-image`, and related post-data blocks | `compiler-only` | None in raw HTML alone | `docs/fse-boundary.md` | Post title, date, author, excerpt, featured image, and content blocks require current post/template context. |
| `core/query*` | `compiler-only` | None in raw HTML alone | `docs/fse-boundary.md` | Query, query title, post template, pagination, and related blocks require loop intent and content-model context. |
| `core/comments*` | `compiler-only` | None in raw HTML alone | `docs/fse-boundary.md` | Comment template blocks require comment-query context and per-comment state. |
| Dynamic utility blocks | `context-required` | None in raw HTML alone | `docs/fse-boundary.md` | Latest posts, archives, categories, RSS, tag cloud, loginout, search, calendar, and similar blocks require site data intent. |

## Theme And Context Block Classification

This classification separates explicit static markers from compiler-only theme
intent. h2bc can support explicit markers only when the source fragment fully
describes a side-effect-free block. It must not infer global site intent from
rendered output.

| Block family | Classification | h2bc boundary |
|---|---|---|
| Static `core/navigation` with `core/navigation-link` / `core/navigation-submenu` children | `explicit-marker supported` | Supported only for one direct list inside `<nav>`. Output is inline block markup with no `ref` and no `wp_navigation` persistence. |
| Persistent `core/navigation` refs | `compiler-only` | Requires a higher-level integration that owns `wp_navigation` creation/reuse and menu-location policy. |
| `core/site-title`, `core/site-logo`, `core/site-tagline` | `compiler-only` | Requires site identity metadata. Explicit HTML markers should be consumed by an FSE compiler that knows the target site. |
| `core/post-title`, `core/post-content`, `core/post-excerpt`, `core/post-featured-image` | `compiler-only` | Requires current post/template context. Rendered headings, images, and excerpts remain static blocks in h2bc. |
| `core/query`, `core/post-template`, query pagination/title blocks | `compiler-only` | Requires loop intent, query args, and content-model context. Repeated cards remain static layout/content blocks. |
| `core/comments` and `core/comment-*` blocks | `compiler-only` | Requires comment-query context and per-comment state. Rendered comment HTML is not enough. |
| `core/template-part` | `compiler-only` | Requires named template-part role and theme file placement. Region splitting belongs above h2bc. |
| Dynamic utility blocks (`core/latest-posts`, `core/archives`, `core/categories`, `core/rss`, `core/tag-cloud`, `core/loginout`, `core/search`, `core/calendar`) | `unsupported` | h2bc has no site-data intent or runtime state. A separate product contract must choose these blocks deliberately. |

## Future Candidates

| Block name/family | Status | Required HTML signal | Test coverage file | Notes |
|---|---|---|---|---|
| Additional embed providers | `future candidate` | Provider-specific URL pattern that can be normalized safely | None | Add only when provider detection is deterministic and fallback remains lossless for unknown providers. |
| Additional static layout patterns | `future candidate` | Explicit class, ARIA, or structural signal that is not shared by generic wrappers | None | Must not regress arbitrary wrapper fallback behavior. |
| Static social links | `future candidate` | Explicit social-links wrapper plus provider-identifiable anchors | None | Needs a conservative signal so ordinary navigation does not become social links. |
