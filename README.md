# Markdown to Quill Delta Converter

A high-performance PHP library to convert Markdown text into Quill Delta format.

## Features
- ✅ Checkboxes (Task lists)
- ✅ Nested Lists (with indentation)
- ✅ Reference-style Links
- ✅ Tables (GFM)
- ✅ Definition Lists
- ✅ Footnotes
- ✅ Abbreviations
- ✅ Highlight (`==`), Subscript (`~`), Superscript (`^`)
- ✅ Emoji (`:smile:`)
- ✅ Paragraphs
- ✅ Headers (H1-H6)
- ✅ Text formatting (bold, italic, inline code, strikethrough)
- ✅ Code blocks
- ✅ Blockquotes
- ✅ Ordered and unordered lists
- ✅ Links
- ✅ Images
- ✅ Nested formatting


## Installation

Install the package via Composer:

```bash
composer require taoshan98/markdown-to-quill-delta
```

## Quickstart

### Basic Usage

```php
<?php
require_once 'vendor/autoload.php';

use MarkdownToQuill\Converter;

$markdown = "# Hello World\n\nThis is **bold** and this is *italic*.";

$converter = new Converter();
$ops = $converter->convert($markdown);

// Output as JSON
echo json_encode($ops, JSON_PRETTY_PRINT);
```

### Practical Example: API Endpoint

```php
// api/convert.php
require_once '../vendor/autoload.php';

use MarkdownToQuill\Converter;

header('Content-Type: application/json');

// Receive markdown from POST
$markdown = file_get_contents('php://input');

// Convert
$converter = new Converter();
$ops = $converter->convert($markdown);

// Respond with JSON
echo json_encode([
    'success' => true,
    'delta' => $ops
]);
```

## Supported Markdown Elements

### Quick Reference

| Markdown | Result |
|----------|-----------|
| `# Heading` | Header level 1 |
| `**bold**` | Bold |
| `*italic*` | Italic |
| `` `code` `` | Inline code |
| `~~strike~~` | Strikethrough |
| `- item` | Unordered list |
| `1. item` | Ordered list |
| `- [ ] item` | Checkbox (unchecked) |
| `- [x] item` | Checkbox (checked) |
| `> quote` | Blockquote |
| ` ```code``` ` | Code block |
| `[text](url)` | Link |
| `![alt](url)` | Image |

### Detailed Syntax

#### Headers
```markdown
# H1
## H2
### H3
#### H4
##### H5
###### H6
```

#### Text Formatting
```markdown
**bold** or __bold__
*italic* or _italic_
`inline code`
~~strikethrough~~
```

#### Lists
Unordered lists:
```markdown
- Item 1
- Item 2
* Item 3
+ Item 4
```

Ordered lists:
```markdown
1. First
2. Second
3. Third
```

#### Checkboxes
```markdown
- [ ] To do
- [x] Done
```

#### Blockquotes
```markdown
> This is a blockquote
> On multiple lines
```

#### Code Blocks
````markdown
```javascript
function hello() {
    console.log("Hello!");
}
```
````

#### Links and Images
```markdown
[Link text](https://example.com)
![Alt text](https://example.com/image.jpg)
```

## Output Examples

### Simple Text
**Markdown Input:**
```
Hello World
```
**Delta JSON Output:**
```json
[
    {
        "insert": "Hello World"
    },
    {
        "insert": "\n"
    }
]
```

### Mixed Formatting
**Markdown Input:**
```
# Welcome
This document has **bold text**, *italic text*, and `inline code`.
```
**Delta JSON Output:**
```json
[
    {
        "insert": "Welcome"
    },
    {
        "insert": "\n",
        "attributes": {
            "header": 1
        }
    },
    {
        "insert": "This document has "
    },
    {
        "insert": "bold text",
        "attributes": {
            "bold": true
        }
    },
    {
        "insert": ", "
    },
    {
        "insert": "italic text",
        "attributes": {
            "italic": true
        }
    },
    {
        "insert": ", and "
    },
    {
        "insert": "inline code",
        "attributes": {
            "code": true
        }
    },
    {
        "insert": ".\n"
    }
]
```

## Testing

Run tests with PHPUnit:

```bash
composer install
./vendor/bin/phpunit tests
```

## Quill Delta Format

The Delta format is a JSON format that describes the content and formatting of Quill documents. Each operation (op) in the delta can be:

- **insert**: The content to insert (text or embeds like images)
- **attributes**: Formatting attributes applied to the insert

Example:
```json
[
    {
        "insert": "Normal text"
    },
    {
        "insert": "Bold text",
        "attributes": {
            "bold": true
        }
    }
]
```



## License

This project is licensed under the BSD-3-Clause License.

## Credits

PHP port based on the original JavaScript library [md-to-quill-delta](https://github.com/volser/md-to-quill-delta).
