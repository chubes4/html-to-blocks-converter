# Gutenberg rawHandler Parity

This project tracks Gutenberg `rawHandler` parity for deterministic static HTML
patterns only. The goal is not to reimplement the full paste pipeline. Gutenberg
also normalizes source-specific clipboard markup from Google Docs, Microsoft
Word, Slack, Markdown paste, and browser image clipboard data. Those behaviors
depend on editor/browser context and are outside h2bc's current server-side
boundary.

The parity contract is:

- If Gutenberg can infer a static core block from ordinary raw HTML alone, h2bc
  should either match that block family or document why it intentionally does
  not.
- If a block requires site, template, query, post, comment, navigation, media
  library, editor, or browser clipboard context, h2bc must not guess.
- Ambiguous or unsupported top-level fragments should remain observable as
  `core/html` fallbacks.

The executable parity contract lives in
`tests/GutenbergRawHandlerParityUnitTest.php`. The broader support matrix lives
in `docs/core-block-coverage.md`.

## Blocks Engine Delegation Gates

`html-to-blocks-converter` now delegates ordinary conversion to Blocks Engine PHP
transformer when no local raw transforms were preloaded. The remaining
`html_to_blocks_needs_legacy_*` gates are compatibility gates, not canonical
runtime ownership. Keep a gate until Blocks Engine matches the legacy behavior
and a characterization test proves the wrapper can accept the delegated output.

Current gate debt:

| Gate | Compatibility behavior protected | Removal condition |
|---|---|---|
| `html_to_blocks_needs_legacy_visual_or_nested_list()` | Visual list-like wrappers stay editable groups while plain lists remain native lists. | Split the gate so plain nested lists delegate, and keep visual wrappers local until Blocks Engine distinguishes them. |
| `html_to_blocks_needs_legacy_script_fallback()` | Scoped script fallbacks preserve the original `<script>` body for observability. | Blocks Engine fallback payloads preserve the scoped script content or the public fallback contract changes. |
| `html_to_blocks_needs_legacy_code_wrapper()` and `html_to_blocks_needs_legacy_span_wrapper()` | Code-window markup becomes preformatted/code content without decorative chrome. | Blocks Engine emits the same editable code body and drops decorative wrappers. |
| `html_to_blocks_needs_legacy_checkbox_label()` | Checkbox inputs are dropped while label text remains editable content. | Blocks Engine matches the legacy checkbox-label transform. |
| `html_to_blocks_needs_legacy_definition_list()` | Visual definition-list wrappers remain groups while direct definition lists remain lists. | Blocks Engine distinguishes visual wrappers from semantic `<dl>` input. |
| `html_to_blocks_needs_legacy_blockquote_figure()` | Testimonial figure/blockquote classes and attribution stay compatible with older consumers. | Blocks Engine output matches the legacy class and attribution contract. |
| `html_to_blocks_needs_legacy_text_div()` and wrapper/media/SVG gates | Static-site chrome, decorative wrappers, resized SVGs, and visual media wrappers retain legacy serialization. | Blocks Engine output matches the wrapper fixture expectations and the smoke tests pass without local routing. |

Gate deletion should pair a narrowing/removal change with the focused smoke test
for the protected shape plus the parity/unit coverage named below.

## Covered Static Expectations

The parity fixture suite currently covers these Gutenberg-compatible static
expectations:

- headings
- paragraphs and inline formatting
- lists and nested list items
- quotes
- images with captions
- code and preformatted text
- separators
- tables
- WordPress shortcodes
- explicit layout wrappers: group, columns, cover, spacer
- explicit action/text blocks: buttons, details, pullquote, verse
- explicit media/embed blocks: video, audio, gallery, media-text, file, embed

## Intentionally Out Of Scope

These are Gutenberg paste/rawHandler-adjacent capabilities, but h2bc should not
claim support until a separate fixture and implementation lands:

- Google Docs, Microsoft Word, Apple Pages, LibreOffice, Slack, and Evernote
  cleanup passes.
- Browser clipboard image data.
- Markdown paste conversion as a source format. h2bc consumes HTML; format
  orchestration belongs to callers.
- Dynamic, contextual, or Site Editor block inference such as navigation, template
  parts, query loops, site identity blocks, post data blocks, comments, and
  dynamic utility blocks.
