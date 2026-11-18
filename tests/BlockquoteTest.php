<?php

namespace Taoshan\QuillMarkdown\Tests;

use Taoshan\QuillMarkdown\QuillMarkdown;
use PHPUnit\Framework\TestCase;

class BlockquoteTest extends TestCase
{
    private QuillMarkdown $converter;

    protected function setUp(): void
    {
        $this->converter = new QuillMarkdown();
    }

    public function testSimpleBlockquote(): void
    {
        $ops = $this->converter->toDeltaOps("> Quote text");
        
        $this->assertEquals('Quote text', $ops[0]['insert']);
        $this->assertEquals("\n", $ops[1]['insert']);
        $this->assertEquals(true, $ops[1]['attributes']['blockquote']);
    }

    public function testMultilineBlockquote(): void
    {
        $markdown = "> Line 1\n> Line 2";
        $ops = $this->converter->toDeltaOps($markdown);

        // Line 1
        $this->assertEquals('Line 1', $ops[0]['insert']);
        $this->assertEquals("\n", $ops[1]['insert']);
        $this->assertEquals(true, $ops[1]['attributes']['blockquote']);

        // Line 2
        $this->assertEquals('Line 2', $ops[2]['insert']);
        $this->assertEquals("\n", $ops[3]['insert']);
        $this->assertEquals(true, $ops[3]['attributes']['blockquote']);
    }
    
    public function testBlockquoteWithInlineFormatting(): void
    {
        $ops = $this->converter->toDeltaOps("> Quote with **bold**");
        
        $this->assertEquals('Quote with ', $ops[0]['insert']);
        $this->assertEquals('bold', $ops[1]['insert']);
        $this->assertEquals(true, $ops[1]['attributes']['bold']);
        
        $this->assertEquals("\n", $ops[2]['insert']);
        $this->assertEquals(true, $ops[2]['attributes']['blockquote']);
    }
}
