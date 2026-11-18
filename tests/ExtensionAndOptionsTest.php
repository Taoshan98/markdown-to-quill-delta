<?php

namespace Taoshan\QuillMarkdown\Tests;

use PHPUnit\Framework\TestCase;
use Taoshan\QuillMarkdown\Config\BulletMarker;
use Taoshan\QuillMarkdown\Config\CodeFenceStyle;
use Taoshan\QuillMarkdown\Config\Options;
use Taoshan\QuillMarkdown\Extension\ExtensionRegistry;
use Taoshan\QuillMarkdown\QuillMarkdown;

class ExtensionAndOptionsTest extends TestCase
{
    public function testInvalidListIndentThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Options(listIndentSpaces: 1);
    }

    public function testCustomBulletMarkerIndentSpacesAndTildeFences(): void
    {
        $qm = new QuillMarkdown(new Options(
            bulletMarker: BulletMarker::Asterisk,
            codeFenceStyle: CodeFenceStyle::Tilde,
            listIndentSpaces: 4,
        ));

        $ops = [
            ['insert' => 'Root'],
            ['insert' => "\n", 'attributes' => ['list' => 'bullet']],
            ['insert' => 'Nested'],
            ['insert' => "\n", 'attributes' => ['list' => 'checked', 'indent' => 1]],
            ['insert' => "echo 1;\n", 'attributes' => ['code-block' => 'php']],
        ];

        $expected = "* Root\n    * [x] Nested\n~~~php\necho 1;\n~~~";
        $this->assertSame($expected, $qm->toMarkdown($ops));
    }

    public function testEnableDividerBlotRoundTripAndLiteralEscape(): void
    {
        $qm = new QuillMarkdown(new Options(enableDividerBlot: true));

        $delta = $qm->toDelta("Above\n\n---\n\nBelow");
        $this->assertSame([
            ['insert' => 'Above'],
            ['insert' => "\n"],
            ['insert' => "\n"],
            ['insert' => ['divider' => true]],
            ['insert' => "\n"],
            ['insert' => 'Below'],
            ['insert' => "\n"],
        ], $delta->getOps());

        $this->assertSame("Above\n\n---\n\nBelow", $qm->toMarkdown($delta));

        // Plain paragraph containing literal "---" must be escaped when enableDividerBlot is true
        $literalDelta = [['insert' => "---\n"]];
        $escapedMd = $qm->toMarkdown($literalDelta);
        $this->assertSame('\---', $escapedMd);
        $this->assertSame([
            ['insert' => '---'],
            ['insert' => "\n"],
        ], $qm->toDeltaOps($escapedMd));
    }

    public function testPreserveUnderlineHtmlAndCustomHighlightColor(): void
    {
        $qm = new QuillMarkdown(new Options(
            preserveUnderlineHtml: true,
            highlightColor: '#ffe066',
        ));

        $md = 'This is <u>underlined **bold**</u> and ==highlighted==.';
        $ops = $qm->toDeltaOps($md);

        $this->assertSame([
            ['insert' => 'This is '],
            ['insert' => 'underlined ', 'attributes' => ['underline' => true]],
            ['insert' => 'bold', 'attributes' => ['bold' => true, 'underline' => true]],
            ['insert' => ' and '],
            ['insert' => 'highlighted', 'attributes' => ['background' => '#ffe066']],
            ['insert' => '.'],
            ['insert' => "\n"],
        ], $ops);

        $this->assertSame(
            'This is <u>underlined **bold**</u> and ==highlighted==.',
            $qm->toMarkdown($ops)
        );
    }

    public function testCompactDeltaOption(): void
    {
        $qm = new QuillMarkdown(new Options(compactDelta: true));
        $ops = $qm->toDeltaOps("Hello World\n\nNext");

        $this->assertSame([
            ['insert' => "Hello World\n\nNext\n"],
        ], $ops);
    }

    public function testCustomEmbedsAndInlineAttributesBidirectional(): void
    {
        $qm = (new QuillMarkdown())
            ->registerEmbed(
                blotName: 'mention',
                toMarkdown: static function (mixed $value): string {
                    if (is_array($value) && isset($value['value'], $value['id'])) {
                        return '@[' . $value['value'] . '](' . $value['id'] . ')';
                    }
                    return '';
                },
                fromMarkdownPattern: '/@\[([^\]]+)\]\((\d+)\)/',
                fromMarkdown: static fn(array $m): array => [
                    'insert' => ['mention' => ['id' => (int) $m[2], 'value' => $m[1]]],
                ],
            )
            ->registerEmbed(
                blotName: 'formula',
                toMarkdown: static fn(mixed $value): string => is_string($value) ? '$' . $value . '$' : '',
                fromMarkdownPattern: '/\$([^$\n]+)\$/',
                fromMarkdown: static fn(array $m): array => [
                    'insert' => ['formula' => $m[1]],
                ],
            )
            ->registerInlineAttribute(
                attributeName: 'color',
                toMarkdown: static fn(string $content, mixed $value): string => '{color:' . $value . '}' . $content . '{/color}',
            );

        $md = 'Ping @[Alice](42) with formula $E = mc^2$ and `keep $raw$`!';
        $ops = $qm->toDeltaOps($md);

        $this->assertSame([
            ['insert' => 'Ping '],
            ['insert' => ['mention' => ['id' => 42, 'value' => 'Alice']]],
            ['insert' => ' with formula '],
            ['insert' => ['formula' => 'E = mc^2']],
            ['insert' => ' and '],
            ['insert' => 'keep $raw$', 'attributes' => ['code' => true]],
            ['insert' => '!'],
            ['insert' => "\n"],
        ], $ops);

        $this->assertSame($md, $qm->toMarkdown($ops));

        // Test custom inline attribute rendering
        $coloredOps = [
            ['insert' => 'Alert', 'attributes' => ['bold' => true, 'color' => '#dc2626']],
            ['insert' => "\n"],
        ];
        $this->assertSame('**{color:#dc2626}Alert{/color}**', $qm->toMarkdown($coloredOps));
    }

    public function testExtensionRegistryRejectsEmptyKeys(): void
    {
        $registry = new ExtensionRegistry();

        $this->expectException(\InvalidArgumentException::class);
        $registry->registerEmbedToMarkdown('', static fn(): string => '');
    }
}
