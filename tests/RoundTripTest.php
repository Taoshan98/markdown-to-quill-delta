<?php

namespace Taoshan\QuillMarkdown\Tests;

use PHPUnit\Framework\TestCase;
use Taoshan\QuillMarkdown\Config\Options;
use Taoshan\QuillMarkdown\Delta\Delta;
use Taoshan\QuillMarkdown\QuillMarkdown;

class RoundTripTest extends TestCase
{
    private QuillMarkdown $qm;

    protected function setUp(): void
    {
        $this->qm = new QuillMarkdown(new Options(
            enableDividerBlot: true,
            preserveUnderlineHtml: true,
        ));
    }

    public function testMarkdownToDeltaToMarkdownStability(): void
    {
        $samples = [
            "# Heading 1\n\n## Heading 2\n\n###### Heading 6",
            'Paragraph with **bold**, *italic*, ***both***, ~~strike~~, ==highlight==, <u>underline</u>, `code`, H~2~O and x^2^.',
            "[Wiki](https://en.wikipedia.org/wiki/Foo_(bar)) and [![Logo](https://example.com/logo.png)](https://example.com)",
            "> Line one quote\n> Line two **bold** quote",
            "---",
            "```php\n<?php\necho 'Hello';\n```",
            "````markdown\n```\nfenced\n```\n````",
            "- Item 1\n  - Sub 1\n    1. Ordered nested\n- [ ] Unchecked\n- [x] Checked",
            "1. First\n2. Second\n3. Third",
            "| Col A | Center | Right |\n| --- | :---: | ---: |\n| a \\| b | **bold** | `x | y` |\n| 0 |  | end |",
            "Term\n: Definition with *emphasis*",
            "See note[^1] and TCP/IP.\n\n*[TCP/IP]: Transmission Control Protocol\n[^1]: Note with **bold**.",
            'Escaped \*stars\* and \_under\_ and \# hash and foo_bar_baz.',
        ];

        foreach ($samples as $markdown) {
            $delta1 = $this->qm->toDelta($markdown);
            $rendered = $this->qm->toMarkdown($delta1);
            $delta2 = $this->qm->toDelta($rendered);

            $this->assertSame(
                $delta1->compact()->toArray(),
                $delta2->compact()->toArray(),
                "Round-trip failed for:\n$markdown\nRendered:\n$rendered"
            );
        }
    }

    public function testCompactedFrontendDeltaRoundTrip(): void
    {
        $frontendPayload = [
            'ops' => [
                ['insert' => "Overview"],
                ['insert' => "\n", 'attributes' => ['header' => 1]],
                ['insert' => "Welcome to "],
                ['insert' => 'Quill', 'attributes' => ['bold' => true, 'link' => 'https://quilljs.com']],
                ['insert' => " editor.\n\n"],
                ['insert' => "Step one"],
                ['insert' => "\n", 'attributes' => ['list' => 'ordered']],
                ['insert' => "Sub step"],
                ['insert' => "\n", 'attributes' => ['list' => 'ordered', 'indent' => 1]],
                ['insert' => "Step two"],
                ['insert' => "\n", 'attributes' => ['list' => 'ordered']],
                ['insert' => "const x = 1;"],
                ['insert' => "\n", 'attributes' => ['code-block' => 'javascript']],
            ],
        ];

        $originalDelta = Delta::fromArray($frontendPayload)->compact();
        $markdown = $this->qm->toMarkdown($originalDelta);
        $reconvertedDelta = $this->qm->toDelta($markdown)->compact();

        $this->assertSame($originalDelta->toArray(), $reconvertedDelta->toArray());
    }
}
