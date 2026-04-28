# FSE Boundary

`html-to-blocks-converter` is a raw-transform library. It converts deterministic
HTML fragments into Gutenberg block arrays and falls back safely when a fragment
does not match a known transform. It is not a full-site-editing compiler.

## Raw Handler Pattern

The `html_to_blocks_raw_handler()` flow mirrors Gutenberg's raw-handler shape:

```text
HTML fragment
  -> shortcode split
  -> HTML5 fragment parse through WP_HTML_Processor
  -> registered raw block transforms
  -> core/html fallback for unknown top-level elements
  -> block arrays for serialize_blocks()
```

This layer is intentionally deterministic. It should only produce a semantic
block when the HTML fragment itself contains enough information to choose that
block without knowing the surrounding template, site identity, query, or theme.
The source-of-truth list of supported transforms, observed fallbacks, future
candidates, and context-required block families lives in the
[Core Block Coverage Matrix](core-block-coverage.md).

## Block Family Boundary

| Block family | Status | Boundary |
|---|---|---|
| `core/paragraph` | Raw-transformable | Plain text and `<p>` map directly. |
| `core/heading` | Raw-transformable | `<h1>` through `<h6>` map to heading levels. A compiler may later choose a site-title, post-title, or query-title block when it has that intent. |
| `core/list`, `core/list-item` | Raw-transformable | `<ul>` and `<ol>` map directly, including nested lists. |
| `core/quote` | Raw-transformable | `<blockquote>` maps directly, with nested static content handled recursively. |
| `core/image` | Raw-transformable with conservative heuristics | `<img>` and `<figure><img>` map to static image blocks. Media-library attachment identity is not inferred. |
| `core/code`, `core/preformatted` | Raw-transformable | `<pre><code>` and `<pre>` map directly. |
| `core/separator` | Raw-transformable | `<hr>` maps directly. |
| `core/table` | Raw-transformable | `<table>` maps to a static table block. |
| `core/html` | Safe fallback | Unknown or intentionally unsupported fragments are preserved as custom HTML instead of guessed. |
| Layout-only static containers | Raw-transformable with conservative heuristics | Groups, columns, covers, buttons, and similar layout blocks may be added only when the HTML pattern is unambiguous and the fallback remains lossless. |
| `core/pattern` | Explicit-marker raw-transformable | Requires `data-bfb-pattern="namespace/slug"`. Similar-looking layout is not enough. |
| `core/template-part` | Explicit-marker raw-transformable | Requires `data-bfb-template-part="area-or-slug"`. Header/footer-looking layout is not enough. |
| Static `core/navigation` | Explicit-marker raw-transformable | `<nav>` may become inline `core/navigation` only when it contains exactly one direct static list of links. h2bc never attaches a persistent `ref`. |
| Persistent `core/navigation*` | Context-required | Requires menu intent, site route knowledge, menu-location policy, and `wp_navigation` post lifecycle ownership. |
| `core/site-title`, `core/site-logo`, `core/site-tagline` | Context-required | Requires site identity metadata. |
| `core/post-title`, `core/post-content`, `core/post-excerpt`, `core/post-featured-image`, and related post-data blocks | Context-required | Requires current template and post context. |
| `core/query*`, `core/post-template` | Context-required | Requires content model and loop intent. |
| `core/comments*`, `core/comment-*` | Context-required | Requires comment-template context. |
| Dynamic utility blocks | Context-required | Archives, categories, latest posts, RSS, tag cloud, loginout, and similar blocks require site data intent. |
| Interactive or stateful app blocks | Intentionally unsupported | Arbitrary HTML is not enough to infer application state, data sources, or editor controls. |

The important rule is that rendered HTML is not identity. The same `<h1>` could
be a static heading, site title, post title, or query title. This package should
choose `core/heading` because that is the only answer proven by the fragment.

## Static Navigation Boundary

Static navigation is the one navigation shape h2bc can convert safely because
the source fragment fully describes the output and requires no persistence:

```html
<nav aria-label="Primary">
  <ul>
    <li><a href="/about/">About</a></li>
    <li><a href="/products/">Products</a>
      <ul>
        <li><a href="/products/a/">Product A</a></li>
      </ul>
    </li>
  </ul>
</nav>
```

This converts to inline `core/navigation` block markup with
`core/navigation-link` and `core/navigation-submenu` children. The conversion is
mechanical and side-effect free:

- No `wp_navigation` posts are created, queried, or reused.
- No `ref` attribute is set on `core/navigation`.
- No menu location, current menu, site route, homepage, or global navigation
  intent is inferred.
- Mixed-content nav, branding-plus-menu nav, search/action nav, or list items
  without direct links fall back instead of being guessed.

Persistent navigation belongs to a higher-level WordPress integration layer that
owns the `wp_navigation` entity lifecycle and site policy decisions.

## Theme Block Classification

| Block family | Classification | Why |
|---|---|---|
| Static `core/navigation` list links | Explicit-marker supported | The fragment carries the exact link tree and requires no site state. |
| `core/pattern` | Explicit-marker supported | Requires `data-bfb-pattern="namespace/slug"`; h2bc does not choose patterns by visual similarity. |
| `core/template-part` | Explicit-marker supported | Requires `data-bfb-template-part="area-or-slug"`; h2bc does not split regions by visual similarity. |
| Persistent `core/navigation` refs | Compiler-only | Requires `wp_navigation` post lifecycle, route knowledge, and menu policy. |
| `core/site-title`, `core/site-logo`, `core/site-tagline` | Compiler-only | Requires site identity metadata; rendered HTML is only static output. |
| `core/post-title`, `core/post-content`, `core/post-excerpt`, `core/post-featured-image` | Compiler-only | Requires current post/template context. |
| `core/query`, `core/post-template`, query pagination/title blocks | Compiler-only | Requires query args, loop intent, and content-model context. |
| `core/comments` and `core/comment-*` blocks | Compiler-only | Requires comment-query context and per-comment state. |
| Dynamic utility blocks | Unsupported | Raw HTML does not carry site-data intent for archives, latest posts, RSS, search, calendars, login state, or tag clouds. |

## Future FSE Compiler Layer

Full-site-editing generation belongs above this package and above format bridges
that use it. A site compiler can carry the intent that raw HTML lacks:

```text
static HTML/CSS/site spec
  -> FSE compiler
      -> split regions: header, footer, main, templates, parts
      -> infer theme.json tokens: palette, typography, spacing
      -> call h2bc for static fragments
      -> insert explicit FSE blocks where intent is known
  -> block theme files
      -> theme.json
      -> templates/*.html
      -> parts/*.html
```

That compiler can decide that a region is a `core/template-part`, that a heading
is `core/site-title`, or that repeated cards are a `core/query` loop. Once it has
made those intent-aware decisions, it can still delegate static fragments to
`html_to_blocks_raw_handler()`.

## Recommendation

Keep h2bc focused on deterministic raw transforms. Template identity, query
semantics, navigation intent, and theme design-token extraction should live in a
separate FSE compiler package or plugin layered above h2bc and Block Format
Bridge. That keeps this package small, predictable, and safe as a reusable
conversion primitive.
