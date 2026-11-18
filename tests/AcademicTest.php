<?php

namespace Taoshan\QuillMarkdown\Tests;

use Taoshan\QuillMarkdown\QuillMarkdown;
use PHPUnit\Framework\TestCase;

class AcademicTest extends TestCase
{
    private QuillMarkdown $converter;

    protected function setUp(): void
    {
        $this->converter = new QuillMarkdown();
    }

    public function testFootnoteReference(): void
    {
        $markdown = "Text[^1].\n\n[^1]: Footnote content.";
        $ops = $this->converter->toDeltaOps($markdown);

        // Inline reference
        $this->assertEquals('Text', $ops[0]['insert']);
        $this->assertEquals('1', $ops[1]['insert']);
        $this->assertEquals('super', $ops[1]['attributes']['script']);
        $this->assertEquals('#fn-1', $ops[1]['attributes']['link']);
        $this->assertEquals('.', $ops[2]['insert']);
        $this->assertEquals("\n", $ops[3]['insert']);

        // Rendered footnote definition at the bottom
        $this->assertEquals('1', $ops[4]['insert']);
        $this->assertEquals('super', $ops[4]['attributes']['script']);
        $this->assertEquals(': Footnote content.', $ops[5]['insert']);
        $this->assertEquals("\n", $ops[6]['insert']);
        $this->assertCount(7, $ops);
    }

    public function testAbbreviation(): void
    {
        $markdown = "The HTML spec.\n\n*[HTML]: Hyper Text";
        $ops = $this->converter->toDeltaOps($markdown);

        // Expected: "The ", "HTML" (title=Hyper Text), " spec.\n"
        $this->assertEquals('The ', $ops[0]['insert']);
        
        $this->assertEquals('HTML', $ops[1]['insert']);
        $this->assertEquals('Hyper Text', $ops[1]['attributes']['title']);
        
        $this->assertEquals(' spec.', $ops[2]['insert']);
    }
}
