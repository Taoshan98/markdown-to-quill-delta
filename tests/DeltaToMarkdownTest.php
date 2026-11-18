<?php

namespace Taoshan\QuillMarkdown\Tests;

use PHPUnit\Framework\TestCase;
use Taoshan\QuillMarkdown\Delta\Delta;
use Taoshan\QuillMarkdown\QuillMarkdown;

class DeltaToMarkdownTest extends TestCase
{
    private QuillMarkdown $qm;

    protected function setUp(): void
    {
        $this->qm = new QuillMarkdown();
    }

    public function testAcceptsAllInputShapesAndHandlesEmpty(): void
    {
        $this->assertSame('', $this->qm->toMarkdown(''));
        $this->assertSame('', $this->qm->toMarkdown([]));
        $this->assertSame('', $this->qm->toMarkdown(new Delta()));
        $this->assertSame('', $this->qm->toMarkdown('{"ops":[{"insert":"\n"}]}'));

        $delta = (new Delta())->insert('Hello', ['bold' => true])->insert("\n");
        $this->assertSame('**Hello**', $this->qm->toMarkdown($delta));
        $this->assertSame('**Hello**', $this->qm->toMarkdown($delta->toArray()));
        $this->assertSame('**Hello**', $this->qm->toMarkdown($delta->getOps()));
        $this->assertSame('**Hello**', $this->qm->toMarkdown($delta->toJson()));
        $this->assertSame('**Hello**', $this->qm->toMarkdown(json_encode($delta->getOps(), JSON_THROW_ON_ERROR)));
    }

    public function testThrowsOnInvalidJsonOrStructure(): void
    {
        $this->expectException(\JsonException::class);
        $this->qm->toMarkdown('{invalid-json');
    }

    public function testThrowsOnInvalidArrayShape(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->qm->toMarkdown(['foo' => 'bar']);
    }

    public function testDeltaUtilitiesCompactPlainTextAndSplitLines(): void
    {
        $delta = (new Delta())
            ->insert('Hello ')
            ->insert('World')
            ->insert('!', ['bold' => true])
            ->insert('?', ['bold' => true])
            ->insert("\n");

        $this->assertFalse($delta->isEmpty());
        $this->assertSame("Hello World!?\n", $delta->toPlainText());

        $compacted = $delta->compact();
        $this->assertSame([
            ['insert' => 'Hello World'],
            ['insert' => '!?', 'attributes' => ['bold' => true]],
            ['insert' => "\n"],
        ], $compacted->getOps());
    }

    public function testCompactedQuillGetContentsWithEmbeddedNewlines(): void
    {
        $json = json_encode([
            'ops' => [
                ['insert' => "First paragraph\n\nSection Title"],
                ['insert' => "\n", 'attributes' => ['header' => 2]],
                ['insert' => 'Normal text'],
                ['insert' => "\n\n", 'attributes' => ['blockquote' => true]],
            ],
        ], JSON_THROW_ON_ERROR);

        $expected = "First paragraph\n\n## Section Title\n> Normal text\n>";
        $this->assertSame($expected, $this->qm->toMarkdown($json));
    }

    public function testWhitespaceHoistingOnInlineStylesAndLinks(): void
    {
        $ops = [
            ['insert' => 'Hello'],
            ['insert' => ' bold ', 'attributes' => ['bold' => true]],
            ['insert' => 'and'],
            ['insert' => ' click ', 'attributes' => ['link' => 'https://example.com']],
            ['insert' => 'here'],
            ['insert' => "\n"],
        ];

        $this->assertSame(
            'Hello **bold** and [click](https://example.com) here',
            $this->qm->toMarkdown($ops)
        );
    }

    public function testWhitespaceOnlyBoldBetweenBoldSpansPreservedAndAccidentalSpaceStripped(): void
    {
        $ops = [
            ['insert' => 'Hello', 'attributes' => ['bold' => true]],
            ['insert' => ' ', 'attributes' => ['bold' => true]],
            ['insert' => 'World', 'attributes' => ['bold' => true]],
            ['insert' => ' ', 'attributes' => ['bold' => true]],
            ['insert' => 'plain'],
            ['insert' => "\n"],
        ];

        $this->assertSame('**Hello World** plain', $this->qm->toMarkdown($ops));
    }

    public function testOverlappingAndCombinedInlineFormats(): void
    {
        $ops = [
            ['insert' => 'Bold ', 'attributes' => ['bold' => true]],
            ['insert' => 'Italic', 'attributes' => ['bold' => true, 'italic' => true]],
            ['insert' => ' and '],
            ['insert' => 'Both', 'attributes' => ['bold' => true, 'italic' => true]],
            ['insert' => ' and '],
            ['insert' => 'code `tick`', 'attributes' => ['bold' => true, 'code' => true]],
            ['insert' => ' and '],
            ['insert' => 'a + b', 'attributes' => ['script' => 'super']],
            ['insert' => ' and '],
            ['insert' => '2', 'attributes' => ['script' => 'sub']],
            ['insert' => ' and '],
            ['insert' => 'mark', 'attributes' => ['background' => '#ffffb8']],
            ['insert' => ' and '],
            ['insert' => 'gone', 'attributes' => ['strike' => true]],
            ['insert' => "\n"],
        ];

        $this->assertSame(
            '**Bold *Italic*** and ***Both*** and **`` code `tick` ``** and ^a^ ^+^ ^b^ and ~2~ and ==mark== and ~~gone~~',
            $this->qm->toMarkdown($ops)
        );
    }

    public function testLinksAndImagesIncludingParensAndImageInLink(): void
    {
        $ops = [
            ['insert' => 'See '],
            ['insert' => 'Wiki', 'attributes' => ['link' => 'https://en.wikipedia.org/wiki/Foo_(bar)']],
            ['insert' => ' and '],
            ['insert' => ['image' => 'https://example.com/pic.png'], 'attributes' => ['alt' => 'My [Pic]']],
            ['insert' => ' and '],
            ['insert' => ['image' => 'badge.svg'], 'attributes' => ['link' => 'https://example.com/a b']],
            ['insert' => "\n"],
        ];

        $this->assertSame(
            'See [Wiki](https://en.wikipedia.org/wiki/Foo_(bar)) and ![My \[Pic\]](https://example.com/pic.png) and [![](badge.svg)](https://example.com/a%20b)',
            $this->qm->toMarkdown($ops)
        );
    }

    public function testCodeBlockGroupingLanguageAndDynamicFenceLength(): void
    {
        $ops = [
            ['insert' => "```nested```\n\$a = 1;\n", 'attributes' => ['code-block' => 'php']],
            ['insert' => "plain line\n", 'attributes' => ['code-block' => 'plain']],
            ['insert' => "\n", 'attributes' => ['code-block' => true]],
        ];

        $expected = "````php\n```nested```\n\$a = 1;\n````\n```\nplain line\n```\n```\n```";
        $this->assertSame($expected, $this->qm->toMarkdown($ops));
    }

    public function testTableGroupingAlignmentAndEscapedPipes(): void
    {
        $ops = [
            ['insert' => 'Name'],
            ['insert' => "\n", 'attributes' => ['table' => 'row-a']],
            ['insert' => 'Score'],
            ['insert' => "\n", 'attributes' => ['table' => 'row-a', 'align' => 'center']],
            ['insert' => 'Total'],
            ['insert' => "\n", 'attributes' => ['table' => 'row-a', 'align' => 'right']],
            ['insert' => 'a | b'],
            ['insert' => "\n", 'attributes' => ['table' => 'row-b']],
            ['insert' => '100', 'attributes' => ['bold' => true]],
            ['insert' => "\n", 'attributes' => ['table' => 'row-b', 'align' => 'center']],
            ['insert' => ''],
            ['insert' => "\n", 'attributes' => ['table' => 'row-b', 'align' => 'right']],
            ['insert' => "After | pipe\n"],
        ];

        $expected = "| Name | Score | Total |\n| --- | :---: | ---: |\n| a \\| b | **100** |  |\n\nAfter | pipe";
        $this->assertSame($expected, $this->qm->toMarkdown($ops));
    }

    public function testListsNestedOrderedCountersAndCheckboxes(): void
    {
        $ops = [
            ['insert' => 'First'],
            ['insert' => "\n", 'attributes' => ['list' => 'ordered']],
            ['insert' => 'Sub 1'],
            ['insert' => "\n", 'attributes' => ['list' => 'ordered', 'indent' => 1]],
            ['insert' => 'Sub 2'],
            ['insert' => "\n", 'attributes' => ['list' => 'ordered', 'indent' => 1]],
            ['insert' => 'Bullet'],
            ['insert' => "\n", 'attributes' => ['list' => 'bullet', 'indent' => 2]],
            ['insert' => 'Second'],
            ['insert' => "\n", 'attributes' => ['list' => 'ordered']],
            ['insert' => 'Todo'],
            ['insert' => "\n", 'attributes' => ['list' => 'unchecked']],
            ['insert' => 'Done'],
            ['insert' => "\n", 'attributes' => ['list' => 'checked']],
        ];

        $expected = implode("\n", [
            '1. First',
            '  1. Sub 1',
            '  2. Sub 2',
            '    - Bullet',
            '2. Second',
            '- [ ] Todo',
            '- [x] Done',
        ]);

        $this->assertSame($expected, $this->qm->toMarkdown($ops));
    }

    public function testContextAwareEscapingAvoidsUnnecessaryBackslashes(): void
    {
        $ops = [
            ['insert' => "Variable foo_bar_baz and array[0] and 5 * 3 * 2 and Costs ~10 or ~20.\n"],
            ['insert' => "# Not a heading\n"],
            ['insert' => "> Not a quote\n"],
            ['insert' => "- Not a list\n"],
            ['insert' => "1. Not ordered\n"],
            ['insert' => ": Not a def\n"],
            ['insert' => "*not italic* and _not italic_ and H~2~O\n"],
        ];

        $expected = implode("\n", [
            'Variable foo_bar_baz and array[0] and 5 * 3 * 2 and Costs ~10 or ~20.',
            '\# Not a heading',
            '\> Not a quote',
            '\- Not a list',
            '1\. Not ordered',
            '\: Not a def',
            '\*not italic\* and \_not italic\_ and H\~2\~O',
        ]);

        $this->assertSame($expected, $this->qm->toMarkdown($ops));
    }

    public function testStaticHelperMethods(): void
    {
        $delta = QuillMarkdown::markdownToDelta('# Title');
        $this->assertSame([
            ['insert' => 'Title'],
            ['insert' => "\n", 'attributes' => ['header' => 1]],
        ], $delta->getOps());

        $this->assertSame('# Title', QuillMarkdown::deltaToMarkdown($delta));
    }

    public function testAdversarialEdgeCasesRoundTripCleanly(): void
    {
        // 1. Brackets inside link labels (both balanced and unbalanced)
        $linkOps = [
            ['insert' => 'Array a]b', 'attributes' => ['link' => 'https://example.com']],
            ['insert' => ' and '],
            ['insert' => 'Click [here]', 'attributes' => ['link' => 'https://example.com/2']],
            ['insert' => "\n"],
        ];
        $linkMd = $this->qm->toMarkdown($linkOps);
        $this->assertSame('[Array a\]b](https://example.com) and [Click \[here\]](https://example.com/2)', $linkMd);
        $this->assertSame($linkOps, $this->qm->toDeltaOps($linkMd));

        // 2. UTF-8 accented snake_case stays unescaped
        $utf8Ops = [['insert' => "variabile città_bella e più_grande\n"]];
        $utf8Md = $this->qm->toMarkdown($utf8Ops);
        $this->assertSame('variabile città_bella e più_grande', $utf8Md);
        $this->assertSame($utf8Ops, $this->qm->toDelta($utf8Md)->compact()->getOps());

        // 3. Core Quill video embed default fallback
        $videoOps = [['insert' => ['video' => 'https://www.youtube.com/embed/xyz']], ['insert' => "\n"]];
        $this->assertSame('[Video](https://www.youtube.com/embed/xyz)', $this->qm->toMarkdown($videoOps));

        // 4. Trailing superscript paragraph is NOT misclassified as a footnote definition
        $superOps = [
            ['insert' => 'Paragraph.'],
            ['insert' => "\n"],
            ['insert' => "\n"],
            ['insert' => '1', 'attributes' => ['script' => 'super']],
            ['insert' => ': First prize'],
            ['insert' => "\n"],
        ];
        $superMd = $this->qm->toMarkdown($superOps);
        $this->assertSame("Paragraph.\n\n^1^: First prize", $superMd);
        $this->assertSame($superOps, $this->qm->toDeltaOps($superMd));

        // 5. Headings with trailing hashes escaped vs C# untouched
        $headingOps = [
            ['insert' => 'Learn C#'],
            ['insert' => "\n", 'attributes' => ['header' => 1]],
            ['insert' => 'Section ##'],
            ['insert' => "\n", 'attributes' => ['header' => 2]],
        ];
        $headingMd = $this->qm->toMarkdown($headingOps);
        $this->assertSame("# Learn C#\n## Section \#\#", $headingMd);
        $this->assertSame($headingOps, $this->qm->toDeltaOps($headingMd));

        // 6. Plain bullet/ordered items starting with literal "[ ] " or "[x] "
        $listOps = [
            ['insert' => '[ ] literal task'],
            ['insert' => "\n", 'attributes' => ['list' => 'bullet']],
            ['insert' => '[x] literal ordered'],
            ['insert' => "\n", 'attributes' => ['list' => 'ordered']],
        ];
        $listMd = $this->qm->toMarkdown($listOps);
        $this->assertSame("- \[ ] literal task\n1. \[x] literal ordered", $listMd);
        $this->assertSame($listOps, $this->qm->toDeltaOps($listMd));
    }
}
