<?php

namespace MarkdownToQuill\Tests;

use MarkdownToQuill\Converter;
use PHPUnit\Framework\TestCase;

class TextFormattingTest extends TestCase
{
    private Converter $converter;

    protected function setUp(): void
    {
        $this->converter = new Converter();
    }

    public function testSimpleText(): void
    {
        $ops = $this->converter->convertToArray("Hello World");
        
        $this->assertEquals('Hello World', $ops[0]['insert']);
        $this->assertEquals("\n", $ops[1]['insert']);
    }

    public function testBold(): void
    {
        $ops = $this->converter->convertToArray("This is **bold**.");
        
        $this->assertEquals('This is ', $ops[0]['insert']);
        $this->assertEquals('bold', $ops[1]['insert']);
        $this->assertEquals(true, $ops[1]['attributes']['bold']);
        $this->assertEquals('.', $ops[2]['insert']);
    }

    public function testItalic(): void
    {
        $ops = $this->converter->convertToArray("This is *italic*.");
        
        $this->assertEquals('This is ', $ops[0]['insert']);
        $this->assertEquals('italic', $ops[1]['insert']);
        $this->assertEquals(true, $ops[1]['attributes']['italic']);
        $this->assertEquals('.', $ops[2]['insert']);
    }

    public function testStrikethrough(): void
    {
        $ops = $this->converter->convertToArray("This is ~~strike~~.");
        
        $this->assertEquals('This is ', $ops[0]['insert']);
        $this->assertEquals('strike', $ops[1]['insert']);
        $this->assertEquals(true, $ops[1]['attributes']['strike']);
        $this->assertEquals('.', $ops[2]['insert']);
    }

    public function testNestedFormatting(): void
    {
        // **Bold *Italic***
        $markdown = "**Bold *Italic***";
        $ops = $this->converter->convertToArray($markdown);

        // Expected: "Bold " (bold), "Italic" (bold + italic)
        // Check "Bold "
        $this->assertEquals('Bold ', $ops[0]['insert']);
        $this->assertEquals(true, $ops[0]['attributes']['bold']);
        $this->assertArrayNotHasKey('italic', $ops[0]['attributes']);

        // Check "Italic"
        $this->assertEquals('Italic', $ops[1]['insert']);
        $this->assertEquals(true, $ops[1]['attributes']['bold']);
        $this->assertEquals(true, $ops[1]['attributes']['italic']);
    }

    public function testMixedFormatting(): void
    {
        $markdown = "**Bold** and *Italic* and `Code`";
        $ops = $this->converter->convertToArray($markdown);

        $this->assertEquals('Bold', $ops[0]['insert']);
        $this->assertEquals(true, $ops[0]['attributes']['bold']);

        $this->assertEquals(' and ', $ops[1]['insert']);

        $this->assertEquals('Italic', $ops[2]['insert']);
        $this->assertEquals(true, $ops[2]['attributes']['italic']);
        
        $this->assertEquals(' and ', $ops[3]['insert']); // Ops might be fragmented but verifying key parts

        // Find Code
        $codeOp = null;
        foreach($ops as $op) {
            if (($op['insert'] ?? '') === 'Code') $codeOp = $op;
        }
        $this->assertNotNull($codeOp);
        $this->assertEquals(true, $codeOp['attributes']['code']);
    }
}
