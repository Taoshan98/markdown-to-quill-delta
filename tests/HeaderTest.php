<?php

namespace MarkdownToQuill\Tests;

use MarkdownToQuill\Converter;
use PHPUnit\Framework\TestCase;

class HeaderTest extends TestCase
{
    private Converter $converter;

    protected function setUp(): void
    {
        $this->converter = new Converter();
    }

    /**
     * @dataProvider headerProvider
     */
    public function testHeaders(string $markdown, int $level): void
    {
        $ops = $this->converter->convertToArray($markdown);

        // Expected format: line 1 = Text, line 2 = \n with header attr
        $this->assertEquals("Header $level", $ops[0]['insert']);
        $this->assertEquals("\n", $ops[1]['insert']);
        $this->assertEquals($level, $ops[1]['attributes']['header']);
    }

    public function headerProvider(): array
    {
        return [
            ['# Header 1', 1],
            ['## Header 2', 2],
            ['### Header 3', 3],
            ['#### Header 4', 4],
            ['##### Header 5', 5],
            ['###### Header 6', 6],
        ];
    }

    public function testHeaderWithInlineFormatting(): void
    {
        $markdown = "# This is **Bold**";
        $ops = $this->converter->convertToArray($markdown);

        // Ops: "This is ", "Bold" (attr bold), "\n" (attr header 1)
        $this->assertEquals("This is ", $ops[0]['insert']);
        
        $this->assertEquals("Bold", $ops[1]['insert']);
        $this->assertEquals(true, $ops[1]['attributes']['bold']);
        
        $this->assertEquals("\n", $ops[2]['insert']);
        $this->assertEquals(1, $ops[2]['attributes']['header']);
    }
}
