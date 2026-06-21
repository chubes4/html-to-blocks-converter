# Core Block Coverage Matrix

This matrix is the human-readable summary of which WordPress core blocks
`html-to-blocks-converter` may infer from raw HTML. The machine-readable gate
is:

- Runtime-generated inventory from the WordPress core under test:
  `wp-includes/blocks/*/block.json`.
- `docs/core-block-classification.json` — the committed h2bc classification map
  that must cover every generated core block.

Generate a fresh inventory after changing the classification map or generator:

```bash
php tools/generate-core-block-inventory.php /path/to/wp-includes/blocks
```

`html-to-blocks-converter` is now the WordPress-facing facade around Blocks
Engine's deterministic raw conversion runtime. Blocks Engine converts HTML only
when the fragment itself contains enough signal to choose a block without site,
template, query, or editor state. Unsupported or ambiguous fragments are
preserved as `core/html` rather than guessed.

Blocks Engine PHP transformer owns the canonical raw conversion runtime. This
package keeps the historical `html_to_blocks_*` facade API, conversion result
shape, and compiler-facing diagnostics while Blocks Engine owns raw transform
selection.

## Status Definitions

| Status | Meaning |
|---|---|
| `supported` | Blocks Engine can emit this block family through the h2bc facade for deterministic raw HTML. |
| `fallback-observed` | h2bc intentionally preserves this HTML as `core/html` when Blocks Engine does not return a safe native block. |
| `context-required` | The block needs site, template, query, post, comment, navigation, or theme context that raw HTML does not carry. |
| `explicit-marker supported` | The facade may expose this block only when explicit source markup names the exact static structure. |
| `compiler-only` | A higher-level compiler may produce this block after it has site, template, or content-model intent; h2bc must not infer it from rendered HTML. |
| `unsupported` | h2bc and a block theme compiler should not produce this block from raw HTML without a separate product/design contract. |
| `future candidate` | Deterministic Blocks Engine support may be possible later, but no support ships today. |

## Supported Static Transforms

| Block name/family | Status | Required HTML signal | Test coverage file | Notes |
|---|---|---|---|---|
| `core/heading` | `supported` | `<h1>` through `<h6>` | `tests/smoke-blocks-engine-parity-facade.php` | Always maps to a static heading. Site, post, and query title identity is context-required. |
| `core/paragraph` | `supported` | Plain text or `<p>` content that does not match a higher-priority conversion | `tests/smoke-blocks-engine-parity-facade.php`, `tests/smoke-media-embed-transforms.php` | Lowest-priority text fallback before `core/html`. |
| `core/list`, `core/list-item` | `supported` | `<ul>` or `<ol>` with `<li>` children | `tests/smoke-definition-list-transforms.php`, `tests/smoke-visual-list-groups.php` | Nested lists are supported. |
| `core/quote` | `supported` | `<blockquote>` without an explicit pullquote signal | `tests/smoke-quote-attribution-wrapper.php`, `tests/smoke-quote-author-avatar-meta.php` | Nested static content is routed back through the raw handler. |
| `core/image` | `supported` | `<img>` or `<figure><img>` | `tests/smoke-media-embed-transforms.php` | Preserves static image attributes and captions when present; does not infer media-library attachment identity unless a safe `wp-image-*` class is present. |
| `core/code` | `supported` | `<pre><code>` | `tests/smoke-code-chrome-decorative-fallbacks.php` | Code-specific conversion wins over plain preformatted text. |
| `core/preformatted` | `supported` | `<pre>` without a verse or code signal | `tests/smoke-blocks-engine-parity-facade.php` | Preserves preformatted static text. |
| `core/separator` | `supported` | `<hr>` | `tests/smoke-blocks-engine-parity-facade.php` | Direct static conversion. |
| `core/table` | `supported` | `<table>` | `tests/smoke-blocks-engine-parity-facade.php` | Static table conversion only; no data-source or query inference. |
| `core/shortcode` | `supported` | WordPress shortcode text matched by `get_shortcode_regex()` | `tests/GutenbergRawHandlerParityUnitTest.php` | Mirrors Gutenberg rawHandler's shortcode conversion path for WordPress shortcodes. |
| `core/group` | `supported` | High-confidence semantic wrapper such as `<section>` or explicit grouping classes | `tests/smoke-nested-landing-layout-content.php`, `tests/smoke-decorative-div-fallbacks.php` | Arbitrary `<div>` does not qualify. Inner content recurses through the raw handler. |
| `core/columns`, `core/column` | `supported` | Explicit row/grid wrapper plus direct column-like children | `tests/smoke-nested-landing-layout-content.php` | Requires layout classes such as `row` and `col-*`; ambiguous wrappers fall back. |
| `core/cover` | `supported` | Hero/cover wrapper with explicit background image or color signal | `tests/smoke-blocks-engine-parity-facade.php` | Preserves static background values and routes inner content through the raw handler. |
| `core/spacer` | `supported` | Empty explicit spacer element with an explicit height | `tests/smoke-terminal-blank-spacer-spans.php`, `tests/smoke-blocks-engine-parity-facade.php` | Content-bearing or heightless wrappers do not qualify. |
| `core/buttons`, `core/button` | `supported` | Native WordPress button anchor, such as `.wp-block-button__link` or `.wp-element-button` | `tests/smoke-decorative-cta-wrapper-divs.php` | Ordinary inline links and custom class CTA anchors such as `.btn` stay inside paragraph/group content so anchor-targeted CSS still applies. |
| `core/details` | `supported` | `<details>` with optional `<summary>` | `tests/smoke-detail-br-wrapper-fallback.php` | Summary becomes an attribute; body content recurses through the raw handler. |
| `core/pullquote` | `supported` | `<blockquote>` with explicit pullquote signal, such as `.wp-block-pullquote` | `tests/smoke-blocks-engine-parity-facade.php` | Ordinary blockquotes remain `core/quote`. |
| `core/verse` | `supported` | `<pre>` with explicit verse signal, such as `.wp-block-verse` | `tests/smoke-blocks-engine-parity-facade.php` | Preserves line breaks and inline `<br>` tags. |
| `core/video` | `supported` | `<video src>`, `<video><source src>`, or `<figure>` containing a video with a source | `tests/smoke-media-embed-transforms.php` | Preserves static media attributes and figure captions where safe. |
| `core/audio` | `supported` | `<audio src>`, `<audio><source src>`, or `<figure>` containing audio with a source | `tests/smoke-media-embed-transforms.php` | Static transform only; no media-library identity inference. |
| `core/gallery` | `supported` | Gallery-like wrapper class plus multiple images | `tests/smoke-media-embed-transforms.php` | Builds `core/image` inner blocks. Ambiguous image collections without a gallery signal do not qualify. |
| `core/media-text` | `supported` | `.wp-block-media-text` or `media-text` wrapper containing image or video media plus content | `tests/smoke-media-embed-transforms.php` | Inner text content recurses through the raw handler. |
| `core/file` | `supported` | Anchor whose `href` has a recognized downloadable file extension | `tests/smoke-media-embed-transforms.php` | Ordinary CTA or navigation links do not become file blocks. |
| `core/embed` | `supported` | `<iframe src>` for a recognized provider URL | `tests/smoke-media-embed-transforms.php` | Recognized providers are normalized into static embed URLs; unknown iframes fall back. |
| `core/pattern` | `future-candidate` | Explicit marker such as `data-h2bc-pattern="namespace/slug"` or shared BFB alias `data-bfb-pattern="namespace/slug"` | None | Pattern markers are a wrapper compatibility gap while Blocks Engine owns raw conversion. h2bc does not emit `core/pattern` today or infer reusable patterns from repeated layout. |
| `core/template-part` | `future-candidate` | Explicit marker such as `data-h2bc-template-part="area-or-slug"` or shared BFB alias `data-bfb-template-part="area-or-slug"` | None | Template-part markers are a wrapper compatibility gap while Blocks Engine owns raw conversion. h2bc does not emit `core/template-part` today or split regions by visual similarity. |

## Mechanical Block Support Mappings

h2bc maps only direct, mechanical HTML attributes into block support attributes.
It does not infer theme tokens, palette slugs, typography presets, or creative
layout intent.

Supported direct mappings:

- `alignwide`, `alignfull`, `alignleft`, `aligncenter`, and `alignright` classes map to `align` where the target block supports it.
- Safe source classes map to `className`; generated `wp-block-*` classes, alignment classes, and invalid class names are discarded.
- `id` maps to `anchor` where the target block supports anchors.
- `text-align: left|center|right` maps to `textAlign` on text blocks.
- `color` and `background` / `background-color` map to `style.color` custom values.
- Explicit WordPress preset classes such as `has-primary-color`, `has-primary-background-color`, and `has-large-font-size` map back to their block-support preset slugs.
- `margin`, `margin-*`, `padding`, and `padding-*` map to `style.spacing` custom values; exact WordPress spacing preset vars such as `var(--wp--preset--spacing--40)` map to `var:preset|spacing|40`.
- `border-color`, `border-style`, `border-width`, and `border-radius` map to `style.border` custom values.
- Semantic container tags (`section`, `main`, `article`, `aside`, `header`, `footer`) map to `tagName` on group-like output that supports wrapper semantics. `nav` is excluded because rendered navigation falls back as preserved HTML.
- Safe supported ARIA wrapper attributes (`aria-label`) are preserved on group-like output.
- Explicit WordPress layout classes such as `is-layout-flex`, `is-vertical`, `is-nowrap`, and `is-content-justification-*` map to `layout` attributes on group-like output.

Intentionally deferred mappings:

- Theme palette, spacing, typography, or border preset token inference when the source does not already contain an exact WordPress preset class or var.
- Arbitrary CSS properties such as transforms, positioning, display, and custom properties.
- Layout intent that is not already explicit in the selected block's source signal.

## Observed Fallbacks

| Block name/family | Status | Required HTML signal | Test coverage file | Notes |
|---|---|---|---|---|
| `core/html` | `fallback-observed` | Any unsupported or intentionally ambiguous top-level HTML fragment | `tests/smoke-unsupported-html-fallback-hook.php`, `tests/smoke-media-embed-transforms.php`, `tests/smoke-inline-script-fallback-scope.php`, `tests/smoke-inline-svg-fallback-scope.php` | Fallback is the safe answer when Blocks Engine reports unconverted markup. The `html_to_blocks_unsupported_html_fallback` action makes these cases observable. |
| Unknown `<iframe>` providers | `fallback-observed` | `<iframe src>` whose provider cannot be mapped to a supported embed provider | `tests/smoke-media-embed-transforms.php`, `tests/smoke-unsupported-html-fallback-hook.php` | Preserving the iframe as custom HTML is safer than inventing an embed provider. |
| Arbitrary wrappers | `fallback-observed` | Generic `<div>` or wrapper markup without a high-confidence layout signal | `tests/smoke-decorative-div-fallbacks.php`, `tests/smoke-empty-bem-decorative-divs.php` | Avoids treating every wrapper as a group, columns, cover, or spacer block. |
| Ordinary links | `fallback-observed` | `<a href>` without button or downloadable-file signal | `tests/smoke-media-embed-transforms.php` | Links usually remain inline paragraph HTML or custom HTML depending on their surrounding fragment. |
| Navigation markup | `fallback-observed` | `<nav>` fragments, including simple static lists | `docs/site-editor-boundary.md` | Native `core/navigation` and `core/navigation-link` save output is not a valid static serialization boundary for default raw conversion. Preserving the fragment as `core/html` is safer than emitting editor-invalid navigation blocks. |
| Legacy and recovery blocks (`core/freeform`, `core/legacy-widget`, `core/missing`, `core/more`, `core/nextpage`, `core/text-columns`, `core/widget-group`) | `fallback-observed` | Legacy editor state or recovery markers, not raw rendered HTML structures | `docs/core-block-classification.json` | h2bc may preserve their rendered output as static blocks or `core/html`, but it does not create new legacy/editor-internal block state from raw HTML. |

## Context-Required And Site Editor Blocks

These block families are intentionally outside h2bc's raw conversion boundary. A
future block theme compiler can choose them after it has site or template intent,
then delegate static fragments back to h2bc.

| Block name/family | Status | Required HTML signal | Test coverage file | Notes |
|---|---|---|---|---|
| Native `core/navigation` blocks | `compiler-only` | None in raw HTML alone | `docs/site-editor-boundary.md` | Requires editor-valid serialization, menu intent, route knowledge, menu-location selection, and optional `wp_navigation` post lifecycle. h2bc preserves rendered navigation markup as `core/html` by default. |
| `core/navigation-link`, `core/navigation-submenu` | `compiler-only` | None in raw HTML alone | `docs/site-editor-boundary.md` | Navigation links and submenus require native navigation context and are not standalone raw conversions. Static links/lists stay preserved unless a compiler owns the native navigation contract. |
| `core/navigation-overlay-close` | `compiler-only` | None in raw HTML alone | `docs/site-editor-boundary.md` | Overlay controls require navigation UI state chosen by an editor or compiler, not rendered-content inference. |
| `core/site-title`, `core/site-logo`, `core/site-tagline`, `core/home-link` | `compiler-only` | None in raw HTML alone | `docs/site-editor-boundary.md` | Site identity blocks and home links require site metadata and URL context. A rendered heading, image, or link is not enough. |
| `core/avatar` | `compiler-only` | None in raw HTML alone | `docs/site-editor-boundary.md` | Avatar output requires author or commenter identity context. Static images stay `core/image`. |
| `core/block` | `compiler-only` | None in raw HTML alone | `docs/site-editor-boundary.md` | Reusable block references require a persistent ref chosen outside raw HTML. |
| `core/footnotes` | `compiler-only` | None in raw HTML alone | `docs/site-editor-boundary.md` | Footnotes require document-level references and editor-managed anchors. |
| `core/post-title`, `core/post-content`, `core/post-excerpt`, `core/post-featured-image`, `core/post-*`, `core/read-more` | `compiler-only` | None in raw HTML alone | `docs/site-editor-boundary.md` | Post title, date, author, excerpt, featured image, read-more links, and related post-data blocks require current post/template context. |
| `core/query*` | `compiler-only` | None in raw HTML alone | `docs/site-editor-boundary.md` | Query, query title, post template, pagination, and related blocks require loop intent and content-model context. |
| `core/comments*` | `compiler-only` | None in raw HTML alone | `docs/site-editor-boundary.md` | Comment template blocks require comment-query context and per-comment state. |
| Dynamic utility blocks (`core/latest-posts`, `core/latest-comments`, `core/archives`, `core/categories`, `core/rss`, `core/tag-cloud`, `core/loginout`, `core/search`, `core/calendar`, `core/page-list`, `core/page-list-item`) | `context-required` | None in raw HTML alone | `docs/site-editor-boundary.md` | These blocks require runtime site data, taxonomy/page hierarchy, authentication state, feed state, or search interaction intent. |
| `core/breadcrumbs` | `compiler-only` | None in raw HTML alone | `docs/site-editor-boundary.md` | Breadcrumbs require route, hierarchy, and site navigation context. |
| `core/terms-query`, `core/term-*` | `compiler-only` | None in raw HTML alone | `docs/site-editor-boundary.md` | Term query and term display blocks require taxonomy query intent and current term context. |
| WooCommerce product/catalog blocks | `compiler-only` | Explicit commerce/product context, not raw HTML alone | `docs/site-editor-boundary.md` | Static product grids/cards remain editable static blocks by default. Woo-native materialization requires importer-owned product identity and policy. |

## Theme And Context Block Classification

This classification separates explicit static markers from compiler-only theme
intent. The facade can support explicit markers only when the source fragment
fully describes a side-effect-free block. It must not infer global site intent
from rendered output.

| Block family | Classification | Facade boundary |
|---|---|---|
| Static rendered navigation markup | `fallback-observed` | Preserved as `core/html` because default raw conversion must not emit editor-invalid native navigation blocks. |
| `core/pattern` | `future-candidate` | Explicit markers such as `data-h2bc-pattern="namespace/slug"` remain a wrapper compatibility gap while Blocks Engine owns raw conversion. Repeated or pattern-looking static layout remains ordinary static blocks. |
| `core/template-part` | `future-candidate` | Explicit markers such as `data-h2bc-template-part="area-or-slug"` remain a wrapper compatibility gap while Blocks Engine owns raw conversion. Region detection remains compiler-only without the explicit marker. |
| Native `core/navigation` blocks | `compiler-only` | Requires a higher-level integration that owns editor-valid serialization, route knowledge, and optional `wp_navigation` creation/reuse policy. |
| `core/site-title`, `core/site-logo`, `core/site-tagline` | `compiler-only` | Requires site identity metadata. Explicit HTML markers should be consumed by a block theme compiler that knows the target site. |
| `core/post-title`, `core/post-content`, `core/post-excerpt`, `core/post-featured-image` | `compiler-only` | Requires current post/template context. Rendered headings, images, and excerpts remain static blocks. |
| `core/query`, `core/post-template`, query pagination/title blocks | `compiler-only` | Requires loop intent, query args, and content-model context. Repeated cards remain static layout/content blocks. |
| `core/comments` and `core/comment-*` blocks | `compiler-only` | Requires comment-query context and per-comment state. Rendered comment HTML is not enough. |
| Dynamic utility blocks (`core/latest-posts`, `core/archives`, `core/categories`, `core/rss`, `core/tag-cloud`, `core/loginout`, `core/search`, `core/calendar`) | `unsupported` | Raw conversion has no site-data intent or runtime state. A separate product contract must choose these blocks deliberately. |
| WooCommerce product/catalog blocks | `compiler-only` | Product-looking cards remain static editable content unless explicit commerce context is provided by the importer/compiler layer. |

## Future Candidates

| Block name/family | Status | Required HTML signal | Test coverage file | Notes |
|---|---|---|---|---|
| Additional embed providers | `future candidate` | Provider-specific URL pattern that can be normalized safely | None | Add only when provider detection is deterministic and fallback remains lossless for unknown providers. |
| Additional static layout patterns | `future candidate` | Explicit class, ARIA, or structural signal that is not shared by generic wrappers | None | Must not regress arbitrary wrapper fallback behavior. |
| Static social links (`core/social-links`, `core/social-link`) | `future candidate` | Explicit social-links wrapper plus provider-identifiable anchors | None | Source-signal note: needs a conservative wrapper and provider-identifiable anchors so ordinary navigation does not become social links. |
| Static accordion (`core/accordion*`) | `future candidate` | Native disclosure or accordion wrapper with stable heading/item/panel structure | None | Source-signal note: needs a parent accordion contract before child accordion blocks can be emitted safely. |
| Static icons (`core/icon`) | `future candidate` | Explicit icon provider/name metadata or stable inline SVG identity | None | Source-signal note: needs deterministic provider and icon identity rules before h2bc can emit an icon block. |
| Static math (`core/math`) | `future candidate` | Stable math markup with an explicit language/source contract | None | Source-signal note: needs a stable source-signal contract before h2bc can distinguish math from generic code or inline HTML. |
