<?php

namespace Taoshan\QuillMarkdown\Tests;

use Taoshan\QuillMarkdown\QuillMarkdown;
use Taoshan\QuillMarkdown\Delta\Delta;
use PHPUnit\Framework\TestCase;

class BugFixRegressionTest extends TestCase
{
    private QuillMarkdown $converter;

    protected function setUp(): void
    {
        $this->converter = new QuillMarkdown();
    }

    public function testAbbreviationWithSlashUtf8AndSymbols(): void
    {
        $markdown = "Use TCP/IP, Più, C++ and .NET daily.\n\n"
            . "*[TCP/IP]: Transmission Control Protocol\n"
            . "*[Più]: Molto di più\n"
            . "*[C++]: C Plus Plus\n"
            . "*[.NET]: DotNet";

        $ops = $this->converter->toDeltaOps($markdown);

        $abbrTitles = [];
        foreach ($ops as $op) {
            if (isset($op['attributes']['title'])) {
                $abbrTitles[$op['insert']] = $op['attributes']['title'];
            }
        }

        $this->assertSame([
            'TCP/IP' => 'Transmission Control Protocol',
            'Più' => 'Molto di più',
            'C++' => 'C Plus Plus',
            '.NET' => 'DotNet',
        ], $abbrTitles);
    }

    public function testAbbreviationNotAppliedInsideCodeOrLinkUrl(): void
    {
        $markdown = "Check `HTML` and [click](https://example.com/HTML) and HTML.\n\n*[HTML]: HyperText";
        $ops = $this->converter->toDeltaOps($markdown);

        $this->assertSame([
            ['insert' => 'Check '],
            ['insert' => 'HTML', 'attributes' => ['code' => true]],
            ['insert' => ' and '],
            ['insert' => 'click', 'attributes' => ['link' => 'https://example.com/HTML']],
            ['insert' => ' and '],
            ['insert' => 'HTML', 'attributes' => ['title' => 'HyperText']],
            ['insert' => '.'],
            ['insert' => "\n"],
        ], $ops);
    }

    public function testConverterReuseDoesNotLeakState(): void
    {
        $this->converter->toDeltaOps("The HTML[^1] [ref][1].\n\n*[HTML]: HyperText\n[^1]: Note\n[1]: https://example.com");
        $second = $this->converter->toDeltaOps("Plain HTML[^1] [ref][1].");

        $this->assertSame([
            ['insert' => 'Plain HTML[^1] [ref][1].'],
            ['insert' => "\n"],
        ], $second);
    }

    public function testZeroStringIsNotLostAcrossConstructs(): void
    {
        $this->assertSame([['insert' => '0'], ['insert' => "\n"]], $this->converter->toDeltaOps('0'));
        $this->assertSame([['insert' => '0'], ['insert' => "\n", 'attributes' => ['header' => 1]]], $this->converter->toDeltaOps('# 0'));
        $this->assertSame([['insert' => '0'], ['insert' => "\n", 'attributes' => ['list' => 'bullet']]], $this->converter->toDeltaOps('- 0'));
        $this->assertSame([['insert' => '0'], ['insert' => "\n", 'attributes' => ['blockquote' => true]]], $this->converter->toDeltaOps('> 0'));
        $this->assertSame([
            ['insert' => 'Term'],
            ['insert' => "\n"],
            ['insert' => '0'],
            ['insert' => "\n", 'attributes' => ['indent' => 1]],
        ], $this->converter->toDeltaOps("Term\n: 0"));
        $this->assertSame([
            ['insert' => 'Col'],
            ['insert' => "\n", 'attributes' => ['table' => 'row-1']],
            ['insert' => '0'],
            ['insert' => "\n", 'attributes' => ['table' => 'row-2']],
        ], $this->converter->toDeltaOps("| Col |\n|---|\n| 0 |"));
        $this->assertSame([
            ['insert' => 'zero', 'attributes' => ['link' => 'https://zero.com']],
            ['insert' => "\n"],
        ], $this->converter->toDeltaOps("[zero][0]\n\n[0]: https://zero.com"));
        $this->assertSame([
            ['insert' => '0', 'attributes' => ['link' => 'https://zero.com']],
            ['insert' => "\n"],
        ], $this->converter->toDeltaOps("[0][]\n\n[0]: https://zero.com"));
        $this->assertSame([
            ['insert' => 'Note'],
            ['insert' => '0', 'attributes' => ['link' => '#fn-0', 'script' => 'super']],
            ['insert' => "\n"],
            ['insert' => '0', 'attributes' => ['script' => 'super']],
            ['insert' => ': Zero note'],
            ['insert' => "\n"],
        ], $this->converter->toDeltaOps("Note[^0]\n\n[^0]: Zero note"));
    }

    public function testPrescanIgnoresDefinitionsInsideCodeBlocks(): void
    {
        $markdown = "```markdown\n[1]: https://secret.com\n[^1]: note\n*[HTML]: Hyper\n```\n\nHTML [x][1] [^1]";
        $ops = $this->converter->toDeltaOps($markdown);

        $this->assertSame([
            ['insert' => 'HTML [x][1] [^1]'],
            ['insert' => "\n"],
        ], array_slice($ops, -2));
    }

    public function testFootnoteDefinitionRendersInlineFormattingAtBottom(): void
    {
        $markdown = "Text[^1].\n\n[^1]: Note with **bold** text.";
        $ops = $this->converter->toDeltaOps($markdown);

        $this->assertSame([
            ['insert' => 'Text'],
            ['insert' => '1', 'attributes' => ['link' => '#fn-1', 'script' => 'super']],
            ['insert' => '.'],
            ['insert' => "\n"],
            ['insert' => '1', 'attributes' => ['script' => 'super']],
            ['insert' => ': Note with '],
            ['insert' => 'bold', 'attributes' => ['bold' => true]],
            ['insert' => ' text.'],
            ['insert' => "\n"],
        ], $ops);
    }

    public function testDeltaAndJsonApiContract(): void
    {
        $delta = new Delta();
        $delta->insert('')->insert('Hi', ['bold' => true])->insert("\n");

        $this->assertSame([
            ['insert' => 'Hi', 'attributes' => ['bold' => true]],
            ['insert' => "\n"],
        ], $delta->getOps());

        $this->assertSame(['ops' => $delta->getOps()], $delta->toArray());
        $this->assertSame('{"ops":[{"insert":"Hi","attributes":{"bold":true}},{"insert":"\n"}]}', $delta->toJson());
        $this->assertSame($delta->toJson(), json_encode($delta));
        $this->assertSame('{"ops":[{"insert":"Perché €"},{"insert":"\n"}]}', $this->converter->toDeltaJson('Perché €'));
    }

    public function testDeltaThrowsOnInvalidInsertTypeAndInvalidUtf8(): void
    {
        $this->expectException(\TypeError::class);
        /** @phpstan-ignore-next-line */
        (new Delta())->insert(new \stdClass());
    }

    public function testDeltaFromArrayThrowsOnInvalidOperationShape(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Delta::fromArray([['insert' => 123]]);
    }

    public function testConvertToJsonThrowsOnMalformedUtf8(): void
    {
        $this->expectException(\JsonException::class);
        $this->converter->toDeltaJson("\xB1\x31");
    }

    public function testBackslashEscapes(): void
    {
        $markdown = '\*not italic\* and \_not italic\_ and \[not link\](url) and \`not code\` and \\\\slash\\\\';
        $ops = $this->converter->toDeltaOps($markdown);

        $this->assertSame([
            ['insert' => '*not italic* and _not italic_ and [not link](url) and `not code` and \\slash\\'],
            ['insert' => "\n"],
        ], $ops);
    }

    public function testIntrawordUnderscoresAndMathDelimitersRemainLiteral(): void
    {
        $samples = [
            'Variable foo_bar_baz in text',
            'https://example.com/a_b_c',
            'Unknown :emoji_one: and :emoji_two:',
            'Result: 5 * 3 * 2 = 30',
            'Costs ~10 or ~20 euros',
            'Formula: x^2 + y^2 = z^2',
            'Compare a == b == c',
            'Text ~~~strike?~~~',
            '[^missing1][^missing2]',
            '10:30:00!',
        ];

        foreach ($samples as $text) {
            $ops = $this->converter->toDeltaOps($text);
            $this->assertSame([
                ['insert' => $text],
                ['insert' => "\n"],
            ], $ops, "Unexpected formatting for: $text");
        }
    }

    public function testInlineCodeProtectsOuterDelimitersAndSupportsMultiBackticks(): void
    {
        $ops1 = $this->converter->toDeltaOps('**bold `**code**` bold**');
        $this->assertSame([
            ['insert' => 'bold ', 'attributes' => ['bold' => true]],
            ['insert' => '**code**', 'attributes' => ['bold' => true, 'code' => true]],
            ['insert' => ' bold', 'attributes' => ['bold' => true]],
            ['insert' => "\n"],
        ], $ops1);

        $ops2 = $this->converter->toDeltaOps('Use `` `backtick` `` inside code');
        $this->assertSame([
            ['insert' => 'Use '],
            ['insert' => '`backtick`', 'attributes' => ['code' => true]],
            ['insert' => ' inside code'],
            ['insert' => "\n"],
        ], $ops2);

        $ops3 = $this->converter->toDeltaOps('Unclosed `` and ` single');
        $this->assertSame([
            ['insert' => 'Unclosed `` and ` single'],
            ['insert' => "\n"],
        ], $ops3);
    }

    public function testNestedEmphasisCombinations(): void
    {
        $this->assertSame([
            ['insert' => 'italic ', 'attributes' => ['italic' => true]],
            ['insert' => 'bold', 'attributes' => ['bold' => true, 'italic' => true]],
            ['insert' => "\n"],
        ], $this->converter->toDeltaOps('*italic **bold***'));

        $this->assertSame([
            ['insert' => 'bold italic', 'attributes' => ['bold' => true, 'italic' => true]],
            ['insert' => "\n"],
        ], $this->converter->toDeltaOps('***bold italic***'));

        $this->assertSame([
            ['insert' => 'italic', 'attributes' => ['bold' => true, 'italic' => true]],
            ['insert' => ' bold', 'attributes' => ['bold' => true]],
            ['insert' => "\n"],
        ], $this->converter->toDeltaOps('***italic* bold**'));

        $this->assertSame([
            ['insert' => 'bold', 'attributes' => ['bold' => true, 'italic' => true]],
            ['insert' => ' italic', 'attributes' => ['italic' => true]],
            ['insert' => "\n"],
        ], $this->converter->toDeltaOps('***bold** italic*'));

        $this->assertSame([
            ['insert' => 'bold', 'attributes' => ['bold' => true]],
            ['insert' => "\n"],
        ], $this->converter->toDeltaOps('****bold****'));

        $this->assertSame([
            ['insert' => 'pointer *ptr', 'attributes' => ['bold' => true]],
            ['insert' => "\n"],
        ], $this->converter->toDeltaOps('**pointer *ptr**'));

        $this->assertSame([
            ['insert' => 'bold italic', 'attributes' => ['bold' => true, 'italic' => true]],
            ['insert' => "\n"],
        ], $this->converter->toDeltaOps('___bold italic___'));

        $this->assertSame([
            ['insert' => '('],
            ['insert' => 'italic', 'attributes' => ['italic' => true]],
            ['insert' => ')'],
            ['insert' => "\n"],
        ], $this->converter->toDeltaOps('(_italic_)'));
    }

    public function testLinksImagesAutolinksAndXssProtection(): void
    {
        // Balanced parens in URL
        $this->assertSame([
            ['insert' => 'Wiki', 'attributes' => ['link' => 'https://en.wikipedia.org/wiki/Foo_(bar)']],
            ['insert' => "\n"],
        ], $this->converter->toDeltaOps('[Wiki](https://en.wikipedia.org/wiki/Foo_(bar))'));

        $this->assertSame([
            ['insert' => ['image' => 'https://example.com/img_(1).png']],
            ['insert' => "\n"],
        ], $this->converter->toDeltaOps('![alt](https://example.com/img_(1).png)'));

        // Titles stripped from URL
        $this->assertSame([
            ['insert' => 'text', 'attributes' => ['link' => 'https://example.com']],
            ['insert' => "\n"],
        ], $this->converter->toDeltaOps('[text](https://example.com "Title")'));

        $this->assertSame([
            ['insert' => ['image' => 'https://example.com/img.png']],
            ['insert' => "\n"],
        ], $this->converter->toDeltaOps('![alt](https://example.com/img.png "Title")'));

        // Reference titles with single quotes and parens + shortcut link
        $refMd = "[a][1] [b][2] [Google]\n\n[1]: https://a.com 'Single'\n[2]: https://b.com (Paren)\n[Google]: https://google.com";
        $this->assertSame([
            ['insert' => 'a', 'attributes' => ['link' => 'https://a.com']],
            ['insert' => ' '],
            ['insert' => 'b', 'attributes' => ['link' => 'https://b.com']],
            ['insert' => ' '],
            ['insert' => 'Google', 'attributes' => ['link' => 'https://google.com']],
            ['insert' => "\n"],
        ], $this->converter->toDeltaOps($refMd));

        // Reference image & brackets in alt
        $this->assertSame([
            ['insert' => ['image' => 'https://example.com/logo.png']],
            ['insert' => "\n"],
        ], $this->converter->toDeltaOps("![alt][logo]\n\n[logo]: https://example.com/logo.png"));

        $this->assertSame([
            ['insert' => ['image' => 'img.png']],
            ['insert' => "\n"],
        ], $this->converter->toDeltaOps('![Alt [with] brackets](img.png)'));

        // Autolinks
        $this->assertSame([
            ['insert' => 'https://example.com', 'attributes' => ['link' => 'https://example.com']],
            ['insert' => ' and '],
            ['insert' => 'user@example.com', 'attributes' => ['link' => 'mailto:user@example.com']],
            ['insert' => "\n"],
        ], $this->converter->toDeltaOps('<https://example.com> and <user@example.com>'));

        // Dangerous schemes rejected; safe base64 image allowed
        $this->assertSame([
            ['insert' => '[Click](javascript:alert(1))'],
            ['insert' => "\n"],
        ], $this->converter->toDeltaOps('[Click](javascript:alert(1))'));

        $this->assertSame([
            ['insert' => '![x](vbscript:msgbox(1))'],
            ['insert' => "\n"],
        ], $this->converter->toDeltaOps('![x](vbscript:msgbox(1))'));

        $this->assertSame([
            ['insert' => ['image' => 'data:image/png;base64,iVBORw0KGgo=']],
            ['insert' => "\n"],
        ], $this->converter->toDeltaOps('![ok](data:image/png;base64,iVBORw0KGgo=)'));
    }

    public function testCodeBlockFencesAndSingleLineTripleBackticks(): void
    {
        $singleLine = "```echo 1;```\nNext line";
        $this->assertSame([
            ['insert' => 'echo 1;', 'attributes' => ['code' => true]],
            ['insert' => "\n"],
            ['insert' => 'Next line'],
            ['insert' => "\n"],
        ], $this->converter->toDeltaOps($singleLine));

        $fourTicks = "````\n```\ncode\n```\n````";
        $this->assertSame([
            ['insert' => '```'],
            ['insert' => "\n", 'attributes' => ['code-block' => true]],
            ['insert' => 'code'],
            ['insert' => "\n", 'attributes' => ['code-block' => true]],
            ['insert' => '```'],
            ['insert' => "\n", 'attributes' => ['code-block' => true]],
        ], $this->converter->toDeltaOps($fourTicks));

        $tildeFence = "~~~python\nprint('hi')\n~~~";
        $this->assertSame([
            ['insert' => "print('hi')"],
            ['insert' => "\n", 'attributes' => ['code-block' => 'python']],
        ], $this->converter->toDeltaOps($tildeFence));

        $closingWithText = "```\nline1\n```notaclose\nline2\n```";
        $this->assertSame([
            ['insert' => 'line1'],
            ['insert' => "\n", 'attributes' => ['code-block' => true]],
            ['insert' => '```notaclose'],
            ['insert' => "\n", 'attributes' => ['code-block' => true]],
            ['insert' => 'line2'],
            ['insert' => "\n", 'attributes' => ['code-block' => true]],
        ], $this->converter->toDeltaOps($closingWithText));

        $this->assertSame([
            ['insert' => "\n", 'attributes' => ['code-block' => true]],
        ], $this->converter->toDeltaOps("```\n```"));
    }

    public function testTableEdgeCasesAndUniqueRowIds(): void
    {
        // Two tables in the same document have distinct row IDs
        $twoTables = "| A |\n|---|\n| 1 |\n\n| B |\n|---|\n| 2 |";
        $ops = $this->converter->toDeltaOps($twoTables);
        $tableRows = [];
        foreach ($ops as $op) {
            if (isset($op['attributes']['table'])) {
                $tableRows[] = $op['attributes']['table'];
            }
        }
        $this->assertSame(['row-1', 'row-2', 'row-3', 'row-4'], $tableRows);

        // Escaped pipe, code-span pipe, empty cells, and column alignments
        $complexTable = "| Left | Center | Right |\n|:---|:---:|---:|\n| a \\| b | `x | y` |  |\n|  | mid | end |";
        $complexOps = $this->converter->toDeltaOps($complexTable);
        $this->assertSame([
            ['insert' => 'Left'],
            ['insert' => "\n", 'attributes' => ['table' => 'row-1']],
            ['insert' => 'Center'],
            ['insert' => "\n", 'attributes' => ['table' => 'row-1', 'align' => 'center']],
            ['insert' => 'Right'],
            ['insert' => "\n", 'attributes' => ['table' => 'row-1', 'align' => 'right']],
            ['insert' => 'a | b'],
            ['insert' => "\n", 'attributes' => ['table' => 'row-2']],
            ['insert' => 'x | y', 'attributes' => ['code' => true]],
            ['insert' => "\n", 'attributes' => ['table' => 'row-2', 'align' => 'center']],
            ['insert' => "\n", 'attributes' => ['table' => 'row-2', 'align' => 'right']],
            ['insert' => "\n", 'attributes' => ['table' => 'row-3']],
            ['insert' => 'mid'],
            ['insert' => "\n", 'attributes' => ['table' => 'row-3', 'align' => 'center']],
            ['insert' => 'end'],
            ['insert' => "\n", 'attributes' => ['table' => 'row-3', 'align' => 'right']],
        ], $complexOps);

        // False positive separator rejected
        $this->assertSame([
            ['insert' => 'a | b'],
            ['insert' => "\n"],
            ['insert' => '---| text'],
            ['insert' => "\n"],
        ], $this->converter->toDeltaOps("a | b\n---| text"));

        // 1-dash separator and no outer pipes
        $this->assertSame([
            ['insert' => 'H1'],
            ['insert' => "\n", 'attributes' => ['table' => 'row-1']],
            ['insert' => 'H2'],
            ['insert' => "\n", 'attributes' => ['table' => 'row-1']],
            ['insert' => 'C1'],
            ['insert' => "\n", 'attributes' => ['table' => 'row-2']],
            ['insert' => 'C2'],
            ['insert' => "\n", 'attributes' => ['table' => 'row-2']],
        ], $this->converter->toDeltaOps("H1 | H2\n- | --\nC1 | C2"));
    }

    public function testHorizontalRulesDoNotMangleIntoListsOrItalics(): void
    {
        foreach (['---', '***', '___', '- - -', '* * *'] as $hr) {
            $this->assertSame([
                ['insert' => $hr],
                ['insert' => "\n"],
            ], $this->converter->toDeltaOps($hr));
        }
    }

    public function testFourSpaceNestedListsDedentAndOrderedParen(): void
    {
        $markdown = "1) Root\n    1) Child\n        - Grandchild\n    2) Child 2\n2) Root 2";
        $this->assertSame([
            ['insert' => 'Root'],
            ['insert' => "\n", 'attributes' => ['list' => 'ordered']],
            ['insert' => 'Child'],
            ['insert' => "\n", 'attributes' => ['list' => 'ordered', 'indent' => 1]],
            ['insert' => 'Grandchild'],
            ['insert' => "\n", 'attributes' => ['list' => 'bullet', 'indent' => 2]],
            ['insert' => 'Child 2'],
            ['insert' => "\n", 'attributes' => ['list' => 'ordered', 'indent' => 1]],
            ['insert' => 'Root 2'],
            ['insert' => "\n", 'attributes' => ['list' => 'ordered']],
        ], $this->converter->toDeltaOps($markdown));
    }

    public function testLineEndingsClosingHeaderHashesAndTrailingBlankLines(): void
    {
        $this->assertSame([
            ['insert' => 'Title'],
            ['insert' => "\n", 'attributes' => ['header' => 1]],
            ['insert' => 'Paragraph'],
            ['insert' => "\n"],
        ], $this->converter->toDeltaOps("# Title\rParagraph"));

        $this->assertSame([
            ['insert' => 'Title'],
            ['insert' => "\n", 'attributes' => ['header' => 2]],
            ['insert' => 'C#'],
            ['insert' => "\n", 'attributes' => ['header' => 1]],
        ], $this->converter->toDeltaOps("## Title ##\n# C#"));

        $this->assertSame([
            ['insert' => 'Text'],
            ['insert' => "\n"],
        ], $this->converter->toDeltaOps("Text\n\n[1]: https://a.com\n[2]: https://b.com\n"));

        $this->assertSame([
            ['insert' => 'Text'],
            ['insert' => "\n"],
        ], $this->converter->toDeltaOps("[1]: https://a.com\n\nText"));

        $this->assertSame([], $this->converter->toDeltaOps('   '));
    }
}
