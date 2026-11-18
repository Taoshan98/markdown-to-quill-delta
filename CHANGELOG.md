# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0] - 2026-11-18

### Changed
- **Breaking — Package Rebrand & Namespace**: Renamed package from `taoshan98/markdown-to-quill-delta` to `taoshan98/quill-markdown` (with `"replace"` mapping in `composer.json`) and migrated all classes from `MarkdownToQuill\` to `Taoshan\QuillMarkdown\`.
- **Breaking — Minimum PHP Version**: Raised minimum supported PHP version from `>=7.4` to `>=8.1`.
- **Breaking — Unified Facade**: Replaced `MarkdownToQuill\Converter` with `Taoshan\QuillMarkdown\QuillMarkdown` (`toDelta()`, `toDeltaOps()`, `toDeltaJson()`, `toMarkdown()`, static helpers `markdownToDelta()` and `deltaToMarkdown()`, `registerEmbed()`, `registerInlineAttribute()`).
- **Breaking — Canonical Delta Output**: Standardized `code-block` emission so attributes attach to terminating `"\n"` operations (preserving language identifiers when provided) and aligned `toDeltaJson()` / `Delta::toJson()` output to `{"ops":[...]}`.
- **Tooling**: Added `phpunit.xml.dist`, `phpstan.neon.dist` (level 8), `ext-json` requirement, PHPUnit `^10.5 || ^11.5` support, and removed `composer.lock` from repository tracking.

### Added
- **Bidirectional Delta-to-Markdown Engine (`DeltaToMarkdown`)**:
  - Added `DeltaRenderer` and `InlineSerializer` supporting Quill 1.x and Quill 2.0 compacted `getContents()` payloads.
  - Added canonical line splitting (`Delta::splitLines()` and `Line`) for multi-newline string operations and mixed block/inline attributes.
  - Added automatic whitespace hoisting on formatted inline spans and links, hierarchical nesting of overlapping inline styles (`bold`, `italic`, `strike`, `background`, `script`, `code`, optional `underline`), and default fallback rendering for core `video` embeds.
  - Added dynamic fenced code block sizing (4+ backticks/tildes when inner content contains fence characters), aligned GFM table reconstruction (`:---`, `:---:`, `---:`, cell pipe escaping), per-indent ordered list counters, footnote extraction (guarded against unreferenced trailing superscripts), abbreviation collection, and context-aware escaping for inline triggers, block prefixes, trailing header hashes, and literal `[ ]` list items.
- **Configuration & Extensibility**:
  - Added immutable `Options`, `BulletMarker` (`-`, `*`, `+`), and `CodeFenceStyle` (`` ` ``, `~`) controlling list markers, fence style, indentation width, optional `divider` blot support, optional `<u>` underline HTML preservation, highlight color, and Delta compaction.
  - Added `ExtensionRegistry` for bidirectional custom embed blots (`mention`, `formula`, etc.) and custom inline attributes.
- **Enhanced `Delta` Model**: Added `Delta::from()`, `Delta::fromJson()`, `Delta::fromArray()`, `Delta::compact()`, `Delta::toPlainText()`, `Delta::isEmpty()`, `Delta::toJson()`, and `\JsonSerializable`.
- **Verification & Test Suites**: Added `BugFixRegressionTest`, `DeltaToMarkdownTest`, `ExtensionAndOptionsTest`, `RoundTripTest`, and `RealWorldUseCasesTest` (100 tests, 336 assertions) alongside PHPStan level 8 verification.

### Fixed
- **Abbreviations & State Isolation**: Escaped regex delimiter `/` in `preg_quote`, added Unicode word-boundary lookarounds (`Più`, `C++`, `.NET`), and reset abbreviation state on every parse to eliminate state leakage across reused converter instances.
- **Zero-String Preservation**: Replaced loose `empty()` checks with strict `=== ''` comparisons so `"0"` is preserved in documents, headings, lists, blockquotes, tables, definition lists, reference links, and footnotes.
- **Pre-scan Isolation**: Ignored reference link, footnote, and abbreviation definitions inside fenced code blocks and stripped leftover blank separator lines cleanly.
- **Inline Parsing**: Added CommonMark backslash escaping, left/right flanking rules preventing intraword underscores and math operators (`5 * 3 * 2`, `~10`, `x^2 + y^2`, `a == b == c`) from triggering formatting, multi-backtick code span protection, nested bold/italic resolution, balanced-parenthesis URLs, link/image titles, shortcut references, reference/bracketed-alt images, and `<autolink>` parsing.
- **Block Parsing**: Normalized isolated `\r` line endings, ATX closing hashes, single-line triple backticks, 4+ backtick and `~~~` code fences, global table `row-N` IDs, escaped/code-span table pipes, empty outer table cells, thematic break preservation, and stack-based multi-space/tab list indentation.
- **Footnotes**: Rendered footnote definitions at the bottom of the Delta document with inline formatting instead of discarding their text.

### Security
- Added URL scheme sanitization (`isSafeUrl()`) for inline links, reference links, autolinks, and images, blocking `javascript:`, `vbscript:`, control characters, and non-raster `data:` URIs (`data:text/html`, `data:image/svg+xml`).

## [1.1.0] - 2026-01-30

### Changed
- **Breaking**: Renamed `Converter::convert($markdown)` to `Converter::convertToArray($markdown)`.
- **Breaking**: Removed horizontal rule (`divider`) blot support from core parser.
- Decomposed monolithic `ConverterTest.php` into granular unit test classes.

### Added
- Added `Converter::convertToJson($markdown)` helper.
- Added support for GFM tables, definition lists, checkbox task lists, reference links, footnotes, abbreviations, highlight, subscript, superscript, and emoji shortcodes.

### Fixed
- Fixed parsing of nested links containing images, mixed bold/italic `***` sequences, and subscript/strikethrough precedence.

## [1.0.0] - 2026-01-29

### Added
- Initial release with Markdown to Quill Delta conversion for paragraphs, headings, inline formatting, code blocks, blockquotes, lists, links, and images.
