<?php

namespace MarkdownToQuill\Tests;

use MarkdownToQuill\Converter;
use PHPUnit\Framework\TestCase;

class BlockquoteTest extends TestCase
{
    private Converter $converter;

    protected function setUp(): void
    {
        $this->converter = new Converter();
    }

    public function testSimpleBlockquote(): void
    {
        $ops = $this->converter->convertToArray("> Quote text");
        
        $this->assertEquals('Quote text', $ops[0]['insert']);
        $this->assertEquals("\n", $ops[1]['insert']);
        $this->assertEquals(true, $ops[1]['attributes']['blockquote']);
    }

    public function testMultilineBlockquote(): void
    {
        $markdown = "> Line 1\n> Line 2";
        $ops = $this->converter->convertToArray($markdown);

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
        $ops = $this->converter->convertToArray("> Quote with **bold**");
        
        $this->assertEquals('Quote with ', $ops[0]['insert']);
        $this->assertEquals('bold', $ops[1]['insert']);
        $this->assertEquals(true, $ops[1]['attributes']['bold']);
        
        $this->assertEquals("\n", $ops[2]['insert']);
        $this->assertEquals(true, $ops[2]['attributes']['blockquote']);
    }
}
