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
| `core/template-part` | Context-required | Requires header, footer, sidebar, or other template-part role. |
| `core/navigation*` | Context-required | Requires menu intent, link hierarchy, and site route knowledge. |
| `core/site-title`, `core/site-logo`, `core/site-tagline` | Context-required | Requires site identity metadata. |
| `core/post-*` | Context-required | Requires current template and post context. |
| `core/query*`, `core/post-template` | Context-required | Requires content model and loop intent. |
| `core/comments*`, `core/comment-*` | Context-required | Requires comment-template context. |
| Dynamic utility blocks | Context-required | Archives, categories, latest posts, RSS, tag cloud, loginout, and similar blocks require site data intent. |
| Interactive or stateful app blocks | Intentionally unsupported | Arbitrary HTML is not enough to infer application state, data sources, or editor controls. |

The important rule is that rendered HTML is not identity. The same `<h1>` could
be a static heading, site title, post title, or query title. This package should
choose `core/heading` because that is the only answer proven by the fragment.

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
