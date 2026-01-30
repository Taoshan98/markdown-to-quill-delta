<?php

namespace MarkdownToQuill\Tests;

use MarkdownToQuill\Converter;
use PHPUnit\Framework\TestCase;

class ReferenceLinkTest extends TestCase
{
    private Converter $converter;

    protected function setUp(): void
    {
        $this->converter = new Converter();
    }

    private function getPlainText(array $ops): string
    {
        $text = "";
        foreach ($ops as $op) {
            $text .= $op['insert'];
        }
        return $text;
    }

    public function testStandardReferenceLink(): void
    {
        $markdown = "This is a [link][1].\n\n[1]: https://example.com";
        $ops = $this->converter->convertToArray($markdown);

        // Verify structure
        // Op structure might be fragmented, so we look for the link attribute
        $foundLink = false;
        foreach ($ops as $op) {
            if (isset($op['attributes']['link']) && $op['attributes']['link'] === 'https://example.com' && $op['insert'] === 'link') {
                $foundLink = true;
                break;
            }
        }
        $this->assertTrue($foundLink, "Reference link was not parsed correctly.");
        
        // Verify text content ends with newline
        $this->assertStringEndsWith("\n", $this->getPlainText($ops));
    }

    public function testImplicitReferenceLink(): void
    {
        $markdown = "This is a [Google][].\n\n[Google]: https://google.com";
        $ops = $this->converter->convertToArray($markdown);

        $foundLink = false;
        foreach ($ops as $op) {
            if (isset($op['attributes']['link']) && $op['attributes']['link'] === 'https://google.com' && $op['insert'] === 'Google') {
                $foundLink = true;
                break;
            }
        }
        $this->assertTrue($foundLink, "Implicit reference link was not parsed correctly.");
    }
    
    public function testReferenceWithTitle(): void
    {
        $markdown = "Link [here][id].\n\n[id]: https://test.com \"Test Title\"";
        $ops = $this->converter->convertToArray($markdown);

        $foundLink = false;
        foreach ($ops as $op) {
            if (isset($op['attributes']['link']) && $op['attributes']['link'] === 'https://test.com' && $op['insert'] === 'here') {
                $foundLink = true;
                break;
            }
        }
        $this->assertTrue($foundLink, "Reference link with title was not parsed.");
    }

    public function testCaseInsensitivity(): void
    {
        $markdown = "Link [Here][ID].\n\n[id]: https://case.com";
        $ops = $this->converter->convertToArray($markdown);

        $foundLink = false;
        foreach ($ops as $op) {
            if (isset($op['attributes']['link']) && $op['attributes']['link'] === 'https://case.com' && $op['insert'] === 'Here') {
                $foundLink = true;
                break;
            }
        }
        $this->assertTrue($foundLink, "Case insensitive reference link failed.");
    }
    
    public function testMissingReference(): void
    {
        // Should remain as plain text if ref not found
        $markdown = "Link [here][missing].";
        $ops = $this->converter->convertToArray($markdown);
        
        $text = $this->getPlainText($ops);
        $this->assertEquals("Link [here][missing].\n", $text);
    }

    public function testRefDefinitionsAreRemoved(): void
    {
        // Note: The newline after "Text" remains because it's a paragraph.
        // The definitions are removed, so we shouldn't see the URL in text.
        $markdown = "Text\n\n[1]: http://url.com";
        $ops = $this->converter->convertToArray($markdown);
        
        $text = $this->getPlainText($ops);
        $this->assertEquals("Text\n", $text);
    }
}
