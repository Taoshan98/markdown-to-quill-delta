# Quill Markdown

A zero-dependency, strictly typed PHP 8.1+ library for bidirectional conversion between Markdown (CommonMark/GFM subset and extensions) and Quill Delta documents (compatible with Quill 1.x and Quill 2.0).

Version 2.0.0 rebrands and replaces `taoshan98/markdown-to-quill-delta` under the package name `taoshan98/quill-markdown` and namespace `Taoshan\QuillMarkdown\`.

## Requirements

- PHP `>= 8.1`
- `ext-json`

## Installation

Install via Composer:

```bash
composer require taoshan98/quill-markdown
```

This package declares `"replace": { "taoshan98/markdown-to-quill-delta": "self.version" }` in `composer.json`.

## Architecture

The library separates the Quill Delta representation from both translation pipelines:

- **`Taoshan\QuillMarkdown\QuillMarkdown`**: Main facade exposing `toDelta()`, `toDeltaOps()`, `toDeltaJson()`, `toMarkdown()`, static helpers, and extension registration.
- **`Taoshan\QuillMarkdown\Delta\Delta` and `Line`**: Immutable-friendly value objects for building, validating, compacting, extracting plain text, and splitting compacted Quill operations into logical lines with block attributes.
- **`Taoshan\QuillMarkdown\MarkdownToDelta\MarkdownParser` and `InlineParser`**: Two-pass (fence-aware pre-scan + line-by-line block and flanking-aware inline) parser converting Markdown into canonical Quill Delta operations.
- **`Taoshan\QuillMarkdown\DeltaToMarkdown\DeltaRenderer` and `InlineSerializer`**: Line-based renderer and hierarchical inline serializer supporting compacted `quill.getContents()` payloads, whitespace hoisting, overlapping styles, dynamic code fences, aligned GFM tables, and context-aware escaping.
- **`Taoshan\QuillMarkdown\Config\Options`**: Immutable configuration object controlling formatting style and optional blots.
- **`Taoshan\QuillMarkdown\Extension\ExtensionRegistry`**: Registry for custom Quill embeds, custom inline attributes, and custom inline Markdown regex patterns.

## Quickstart & Core API

### Markdown to Quill Delta

```php
<?php

declare(strict_types=1);

require_once 'vendor/autoload.php';

use Taoshan\QuillMarkdown\QuillMarkdown;

$converter = new QuillMarkdown();

$markdown = <<<'MD'
# Release Summary

This release introduces **bold**, *italic*, and `inline code`.
MD;

// Returns a Delta value object
$delta = $converter->toDelta($markdown);

// Returns canonical Quill Delta JSON: {"ops":[...]}
$json = $converter->toDeltaJson($markdown, JSON_PRETTY_PRINT);

// Returns the raw list of Delta operations: [ ['insert' => ...], ... ]
$ops = $converter->toDeltaOps($markdown);

// Static shortcut
$delta = QuillMarkdown::markdownToDelta($markdown);
```

### Quill Delta to Markdown

`toMarkdown()` accepts a `Delta` instance, a JSON string (`{"ops":[...]}` or `[...]`), an associative `['ops' => [...]]` array, or a raw list of operations:

```php
<?php

declare(strict_types=1);

require_once 'vendor/autoload.php';

use Taoshan\QuillMarkdown\QuillMarkdown;

$converter = new QuillMarkdown();

// Native compacted payload from quill.getContents()
$quillJson = json_encode([
    'ops' => [
        ['insert' => "Overview\n\nSection Heading"],
        ['insert' => "\n", 'attributes' => ['header' => 2]],
        ['insert' => 'Check the'],
        ['insert' => ' documentation ', 'attributes' => ['link' => 'https://example.com']],
        ['insert' => 'for '],
        ['insert' => 'API ', 'attributes' => ['bold' => true]],
        ['insert' => 'details', 'attributes' => ['bold' => true, 'italic' => true]],
        ['insert' => ".\n"],
    ],
], JSON_THROW_ON_ERROR);

$markdown = $converter->toMarkdown($quillJson);
/*
Overview

## Section Heading
Check the [documentation](https://example.com) for **API *details***.
*/

// Static shortcut
$markdown = QuillMarkdown::deltaToMarkdown($quillJson);
```

The `DeltaToMarkdown` pipeline handles several Quill-specific behaviors automatically:

- **Canonical Line Splitting**: Multi-line string inserts such as `"Paragraph\n\nHeading"` followed by `{"insert": "\n", "attributes": {"header": 2}}` isolate the block attribute strictly to the terminating line (`Heading`).
- **Whitespace Hoisting**: Leading or trailing spaces captured inside inline styles or links (e.g., `" documentation "`) are moved outside Markdown delimiters (` [documentation](...) `) rather than producing broken syntax like `** bold **`.
- **Overlapping Style Merging**: Adjacent spans with shared attributes (such as `bold` followed by `bold + italic`) are nested cleanly (`**API *details***`).
- **Context-Aware Escaping**: Plain text characters such as `#`, `>`, `*`, `_`, `~`, `^`, `[`, `<`, and `|` are escaped only when their surrounding context would otherwise trigger Markdown parsing, leaving identifiers like `foo_bar_baz`, `array[0]`, and `5 * 3 * 2` clean.

### `Delta` Value Object Utilities

```php
use Taoshan\QuillMarkdown\Delta\Delta;

// Factory normalization (accepts Delta | array | JSON string)
$delta = Delta::from($payload);
$delta = Delta::fromJson($jsonString);
$delta = Delta::fromArray($arrayPayload);

// Builder API (empty string inserts are ignored per Quill Delta specification)
$built = (new Delta())
    ->insert('Hello ')
    ->insert('World', ['bold' => true])
    ->insert("\n");

// Utilities
$compacted = $built->compact();      // Merges adjacent text ops with identical attributes
$plainText = $built->toPlainText();  // Concatenates all string inserts
$lines     = $built->splitLines();   // Returns list<Line> with inlineOps and block attributes
$isEmpty   = $built->isEmpty();      // True when ops array is empty
$array     = $built->toArray();      // ['ops' => [...]]
$ops       = $built->getOps();       // [['insert' => ...], ...]
$json      = $built->toJson();       // '{"ops":[...]}' (throws \JsonException on error)
```

`Delta` implements `\JsonSerializable` and serializes directly via `json_encode($delta)` as `{"ops":[...]}`.

## Configuration (`Options`)

Pass a `Taoshan\QuillMarkdown\Config\Options` instance to `new QuillMarkdown($options)` or the static helpers. All properties on `Options` are `readonly`.

| Parameter | Type | Default | Description |
| :--- | :--- | :--- | :--- |
| `bulletMarker` | `BulletMarker` | `BulletMarker::Dash` | Marker emitted for bullet lists and task lists (`Dash` `-`, `Asterisk` `*`, `Plus` `+`). |
| `codeFenceStyle` | `CodeFenceStyle` | `CodeFenceStyle::Backtick` | Fence character emitted for code blocks (`Backtick` `` ` `` or `Tilde` `~`). |
| `listIndentSpaces` | `int` | `2` | Number of spaces per nesting level when rendering indented lists (must be `>= 2`). |
| `enableDividerBlot` | `bool` | `false` | When `true`, thematic breaks (`---`, `***`, `___`) convert to/from `{"insert": {"divider": true}}`. When `false`, thematic breaks remain literal text lines. |
| `preserveUnderlineHtml` | `bool` | `false` | When `true`, enables bidirectional conversion between `<u>...</u>` and Quill's `underline: true` attribute. |
| `highlightColor` | `string` | `'#ffffb8'` | Background hex/CSS color emitted when parsing `==highlight==`. |
| `compactDelta` | `bool` | `false` | When `true`, `toDelta()`, `toDeltaOps()`, and `toDeltaJson()` compact adjacent text operations with identical attributes. |

```php
use Taoshan\QuillMarkdown\Config\BulletMarker;
use Taoshan\QuillMarkdown\Config\CodeFenceStyle;
use Taoshan\QuillMarkdown\Config\Options;
use Taoshan\QuillMarkdown\QuillMarkdown;

$options = new Options(
    bulletMarker: BulletMarker::Asterisk,
    codeFenceStyle: CodeFenceStyle::Tilde,
    listIndentSpaces: 4,
    enableDividerBlot: true,
    preserveUnderlineHtml: true,
    highlightColor: '#ffe066',
    compactDelta: true,
);

$converter = new QuillMarkdown($options);
```

## Extensibility (`ExtensionRegistry`)

Register custom Quill embed blots, custom inline attributes, or custom inline Markdown patterns directly on `QuillMarkdown` (or via a standalone `ExtensionRegistry` passed to the constructor):

```php
use Taoshan\QuillMarkdown\Extension\ExtensionRegistry;
use Taoshan\QuillMarkdown\QuillMarkdown;

$converter = (new QuillMarkdown())
    ->registerEmbed(
        blotName: 'mention',
        toMarkdown: static function (mixed $value, array $attributes): string {
            if (is_array($value) && isset($value['value'], $value['id'])) {
                return '@[' . $value['value'] . '](' . $value['id'] . ')';
            }
            return '';
        },
        fromMarkdownPattern: '/@\[([^\]]+)\]\((\d+)\)/',
        fromMarkdown: static fn(array $matches): array => [
            'insert' => [
                'mention' => ['id' => (int) $matches[2], 'value' => $matches[1]],
            ],
        ],
    )
    ->registerEmbed(
        blotName: 'formula',
        toMarkdown: static fn(mixed $value): string => is_string($value) ? '$' . $value . '$' : '',
        fromMarkdownPattern: '/\$([^$\n]+)\$/',
        fromMarkdown: static fn(array $matches): array => [
            'insert' => ['formula' => $matches[1]],
        ],
    )
    ->registerInlineAttribute(
        attributeName: 'color',
        toMarkdown: static fn(string $content, mixed $value): string => "{color:$value}$content{/color}",
    );
```

Custom inline Markdown patterns are evaluated outside inline code spans and backslash escapes. Returning `null` from a `fromMarkdown` callback declines the match and falls back to standard parsing.

## Supported Syntax & Delta Mapping

| Construct | Markdown Syntax | Quill Delta Representation |
| :--- | :--- | :--- |
| Paragraph | `Plain text` | `{"insert": "Plain text"}`, `{"insert": "\n"}` |
| Headings (1–6) | `# H1` .. `###### H6` (closing `#` stripped) | `{"insert": "H1"}`, `{"insert": "\n", "attributes": {"header": 1}}` |
| Bold | `**bold**`, `__bold__` | `{"insert": "bold", "attributes": {"bold": true}}` |
| Italic | `*italic*`, `_italic_` (CommonMark flanking rules) | `{"insert": "italic", "attributes": {"italic": true}}` |
| Bold + Italic | `***both***`, `**a *b***`, `*a **b***` | `{"bold": true, "italic": true}` |
| Strikethrough | `~~strike~~` | `{"strike": true}` |
| Inline Code | `` `code` ``, ```` `` `nested` `` ```` | `{"code": true}` |
| Highlight | `==highlight==` | `{"background": "#ffffb8"}` (configurable) |
| Subscript / Superscript | `H~2~O`, `x^2^` | `{"script": "sub"}`, `{"script": "super"}` |
| Underline (opt-in) | `<u>text</u>` (`preserveUnderlineHtml: true`) | `{"underline": true}` |
| Fenced Code Block | ```` ```php ... ``` ```` or `~~~ ... ~~~` | Line inserts + `{"insert": "\n", "attributes": {"code-block": "php"}}` (or `true`) |
| Blockquote | `> quote` | `{"insert": "quote"}`, `{"insert": "\n", "attributes": {"blockquote": true}}` |
| Bullet / Ordered List | `- item`, `* item`, `+ item`, `1. item`, `1) item` | `{"list": "bullet"}` or `{"list": "ordered"}` (+ `"indent": N` for nested levels) |
| Task List | `- [ ] todo`, `- [x] done` | `{"list": "unchecked"}` or `{"list": "checked"}` (+ `"indent": N`) |
| GFM Table | `\| A \| B \|` + `\| :--- \| :---: \| ---: \|` | Cell ops + `{"insert": "\n", "attributes": {"table": "row-N", "align": ...}}` |
| Links | `[text](url "title")`, `[text][id]`, `[id]`, `<https://...>` | `{"insert": "text", "attributes": {"link": "url"}}` |
| Images | `![alt](url)`, `![alt][id]`, `[![alt](img)](url)` | `{"insert": {"image": "url"}}` (optional `link` attribute) |
| Video Embed | Default fallback on `Delta -> Markdown` | `{"insert": {"video": "url"}}` renders as `[Video](url)` |
| Definition List | `Term` followed by `: Definition` | Term line + indented line (`{"indent": 1}` without `list`) |
| Footnotes | `Text[^1]` and `[^1]: Body` | `{"insert": "1", "attributes": {"link": "#fn-1", "script": "super"}}` + bottom note |
| Abbreviations | `*[HTML]: HyperText` | `{"insert": "HTML", "attributes": {"title": "HyperText"}}` |
| Shortcodes | `:smile:`, `:rocket:`, `:heart:`, etc. | Built-in shortcode map to Unicode characters |
| Divider (opt-in) | `---`, `***`, `___` (`enableDividerBlot: true`) | `{"insert": {"divider": true}}` |
| Backslash Escapes | `\*`, `\_`, `\[`, `` \` ``, `\|`, `\\`, `\#`, `\>` | Unescaped literal punctuation in Delta |

## Security & URL Sanitization

During Markdown-to-Delta parsing, link, autolink, reference, and image destinations pass through URL scheme validation:

- **Allowed link schemes**: `http`, `https`, `mailto`, `tel`, `ftp`, and scheme-less relative URLs/fragments (`/path`, `image.png`, `#fn-1`, `//example.com`).
- **Allowed image `data:` URIs**: Restricted strictly to base64-encoded raster images (`data:image/png`, `data:image/jpeg`, `data:image/gif`, `data:image/webp`).
- **Rejected payloads**: `javascript:`, `vbscript:`, `data:text/html`, `data:image/svg+xml`, and control characters (`0x00`–`0x1F`, `0x7F`) are rejected and kept as plain text instead of active links or embeds.

## Design Boundaries & Round-Trip Semantics

- **Presentation-only Quill attributes**: Attributes without a standard Markdown counterpart (`color`, `font`, `size`, paragraph `align`, `direction`) are ignored by default unless explicitly mapped via `registerInlineAttribute()`.
- **Canonical normalization**: Alternative Markdown input variants (for example `1)` ordered markers, `+` bullets, 4-space list indentation, or `[text][ref]` reference links) serialize into the canonical style configured in `Options`, preserving exact semantic equivalence at the Delta level (`$delta1->compact()->toArray() === $delta2->compact()->toArray()`).

## Migrating from v1.x (`taoshan98/markdown-to-quill-delta`)

| v1.x (`MarkdownToQuill\`) | v2.0.0 (`Taoshan\QuillMarkdown\`) |
| :--- | :--- |
| `new MarkdownToQuill\Converter()` | `new Taoshan\QuillMarkdown\QuillMarkdown()` |
| `$converter->convertToDelta($md)` | `$converter->toDelta($md)` |
| `$converter->convertToArray($md)` | `$converter->toDeltaOps($md)` |
| `$converter->convertToJson($md)` | `$converter->toDeltaJson($md)` |
| *(not supported)* | `$converter->toMarkdown($deltaOrJson)` |

## Quality Assurance

Run the PHPUnit test suite and PHPStan Level 8 static analysis:

```bash
composer install
composer test
composer analyse
```

Or invoke the binaries directly:

```bash
./vendor/bin/phpunit
./vendor/bin/phpstan analyse
```

## License

This project is licensed under the BSD-3-Clause License.

## Credits

Originally inspired by the JavaScript library [md-to-quill-delta](https://github.com/volser/md-to-quill-delta), redesigned and expanded in PHP as a full bidirectional converter.
