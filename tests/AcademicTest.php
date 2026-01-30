<?php

namespace MarkdownToQuill\Tests;

use MarkdownToQuill\Converter;
use PHPUnit\Framework\TestCase;

class AcademicTest extends TestCase
{
    private Converter $converter;

    protected function setUp(): void
    {
        $this->converter = new Converter();
    }

    public function testFootnoteReference(): void
    {
        $markdown = "Text[^1].\n\n[^1]: Footnote content.";
        $ops = $this->converter->convertToArray($markdown);

        // Expected: Text
        $this->assertEquals('Text', $ops[0]['insert']);
        
        // Footnote mark: [^1] -> 1 (super, link #fn-1)
        // Or just 1 with super?
        // Let's implement: insert "1", script: super, link: #fn-1
        $this->assertEquals('1', $ops[1]['insert']);
        $this->assertEquals('super', $ops[1]['attributes']['script']);
        $this->assertEquals('#fn-1', $ops[1]['attributes']['link']);
        
        // Definition should be removed from main flow or rendered at bottom?
        // Standard behavior: The definition line is stripped from where it was.
        // Rendering key-value definitions at bottom is optional.
        // For now, we verify it is stripped.
        
        // After "1", maybe "."
        $this->assertEquals(".", $ops[2]['insert']);
        $this->assertEquals("\n", $ops[3]['insert']); // End of Para
        
        // Ensure definition line is not rendered as text
        $this->assertCount(4, $ops); 
    }

    public function testAbbreviation(): void
    {
        $markdown = "The HTML spec.\n\n*[HTML]: Hyper Text";
        $ops = $this->converter->convertToArray($markdown);

        // Expected: "The ", "HTML" (title=Hyper Text), " spec.\n"
        $this->assertEquals('The ', $ops[0]['insert']);
        
        $this->assertEquals('HTML', $ops[1]['insert']);
        $this->assertEquals('Hyper Text', $ops[1]['attributes']['title']);
        
        $this->assertEquals(' spec.', $ops[2]['insert']);
    }
}
