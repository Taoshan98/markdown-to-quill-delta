<?php

namespace MarkdownToQuill\Tests;

use MarkdownToQuill\Converter;
use PHPUnit\Framework\TestCase;

class InlineExtensionTest extends TestCase
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

    public function testHighlight(): void
    {
        $markdown = "This is ==highlighted== text.";
        $ops = $this->converter->convertToArray($markdown);

        $found = false;
        foreach ($ops as $op) {
            if (isset($op['attributes']['background']) && $op['insert'] === 'highlighted') {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, "Highlight syntax ==...== failed.");
    }

    public function testSubscript(): void
    {
        $markdown = "H~2~O";
        $ops = $this->converter->convertToArray($markdown);

        $found = false;
        foreach ($ops as $op) {
            // Check for script: sub
            if (isset($op['attributes']['script']) && $op['attributes']['script'] === 'sub' && $op['insert'] === '2') {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, "Subscript syntax ~...~ failed.");
    }

    public function testSuperscript(): void
    {
        $markdown = "x^2^";
        $ops = $this->converter->convertToArray($markdown);

        $found = false;
        foreach ($ops as $op) {
            // Check for script: super
            if (isset($op['attributes']['script']) && $op['attributes']['script'] === 'super' && $op['insert'] === '2') {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, "Superscript syntax ^...^ failed.");
    }
    
    public function testStrikethroughStillWorks(): void
    {
        // ~~ should be strike, not double sub
        $markdown = "~~strike~~";
        $ops = $this->converter->convertToArray($markdown);

        $found = false;
        foreach ($ops as $op) {
            if (isset($op['attributes']['strike']) && $op['insert'] === 'strike') {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, "Strikethrough ~~...~~ failed regression test.");
        
        // Ensure it is NOT sub
        foreach ($ops as $op) {
            $this->assertArrayNotHasKey('script', $op['attributes'] ?? []);
        }
    }

    public function testEmoji(): void
    {
        $markdown = "Hello :smile:!";
        $ops = $this->converter->convertToArray($markdown);
        
        // Expected: Hello 😄!
        $text = $this->getPlainText($ops);
        // We verify that :smile: is NOT present, and some unicode is present.
        // Or check specifically for the emoji if we control the map.
        $this->assertStringContainsString("😄", $text);
        $this->assertStringNotContainsString(":smile:", $text);
    }
}
