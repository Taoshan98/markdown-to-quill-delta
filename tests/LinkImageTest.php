<?php

namespace MarkdownToQuill\Tests;

use MarkdownToQuill\Converter;
use PHPUnit\Framework\TestCase;

class LinkImageTest extends TestCase
{
    private Converter $converter;

    protected function setUp(): void
    {
        $this->converter = new Converter();
    }

    public function testLink(): void
    {
        $ops = $this->converter->convertToArray("This is a [link](https://example.com).");
        
        $this->assertEquals('This is a ', $ops[0]['insert']);
        
        $this->assertEquals('link', $ops[1]['insert']);
        $this->assertEquals('https://example.com', $ops[1]['attributes']['link']);
        
        $this->assertEquals('.', $ops[2]['insert']);
    }

    public function testLinkWithInnerFormatting(): void
    {
        $ops = $this->converter->convertToArray("Check [**bold** link](https://example.com)");
        
        // "Check "
        $this->assertEquals('Check ', $ops[0]['insert']);
        
        // "bold" (bold + link)
        $this->assertEquals('bold', $ops[1]['insert']);
        $this->assertEquals(true, $ops[1]['attributes']['bold']);
        $this->assertEquals('https://example.com', $ops[1]['attributes']['link']);
        
        // " link" (link only)
        $this->assertEquals(' link', $ops[2]['insert']);
        $this->assertEquals('https://example.com', $ops[2]['attributes']['link']);
    }

    public function testImage(): void
    {
        $ops = $this->converter->convertToArray("![Alt Text](image.jpg)");
        
        // Image op is an object insert
        $this->assertEquals(['image' => 'image.jpg'], $ops[0]['insert']);
        
        // Verify attributes? Usually image ops might have attributes like alt (if Quills supports custom attr)
        // Standard Delta image insert is just { image: url }
        // Our parser produces just the insert.
    }
    
    public function testImageInLink(): void
    {
        // Markdown: [![Alt](img.jpg)](link.com)
        // Quill support for image inside link is... tricky.
        // Usually renders as Image Op with Link Attribute.
        
        $markdown = "[![Alt](img.jpg)](link.com)";
        $ops = $this->converter->convertToArray($markdown);
        
        // Let's see if Parser supports this recursion.
        // InlineParser Logic:
        // [ ... ]( ... )  -> extracts inner text "![Alt](img.jpg)" -> recursively parses it.
        // Inner parse finds image. Returns Image Op.
        // Outer loop applies link attribute to all inner ops.
        
        $this->assertEquals(['image' => 'img.jpg'], $ops[0]['insert']);
        $this->assertEquals('link.com', $ops[0]['attributes']['link']);
    }
}
