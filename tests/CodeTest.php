<?php

namespace MarkdownToQuill\Tests;

use MarkdownToQuill\Converter;
use PHPUnit\Framework\TestCase;

class CodeTest extends TestCase
{
    private Converter $converter;

    protected function setUp(): void
    {
        $this->converter = new Converter();
    }

    public function testInlineCode(): void
    {
        $ops = $this->converter->convertToArray("This is `inline code`.");
        
        $this->assertEquals('This is ', $ops[0]['insert']);
        
        $this->assertEquals('inline code', $ops[1]['insert']);
        $this->assertEquals(true, $ops[1]['attributes']['code']);
        
        $this->assertEquals('.', $ops[2]['insert']);
    }

    public function testGenericCodeBlock(): void
    {
        $markdown = "```\ncode line 1\ncode line 2\n```";
        $ops = $this->converter->convertToArray($markdown);
        
        // Expected: each line is an op with 'code-block': true
        // line 1 definition
        $this->assertEquals('code line 1' . "\n", $ops[0]['insert']);
        $this->assertEquals(true, $ops[0]['attributes']['code-block']);
        
        // line 2 definition
        $this->assertEquals('code line 2' . "\n", $ops[1]['insert']);
        $this->assertEquals(true, $ops[1]['attributes']['code-block']);
    }

    public function testLanguageSpecifiedCodeBlock(): void
    {
        // Quill often uses 'code-block': 'language' or just 'code-block': true depending on format.
        // Our Parser currently sets 'code-block' => true.
        // Ideally it should capture language if using a specific delta format extension, 
        // but core Delta 'code-block' is boolean or string.
        // Let's check current implementation in Parser.php:
        // $delta->insert($codeLine . "\n", ['code-block' => true]);
        // So language is ignored in Delta output currently.
        // We verify that it parses correctly even if language is present.
        
        $markdown = "```php\necho 'Hello';\n```";
        $ops = $this->converter->convertToArray($markdown);
        
        $this->assertEquals("echo 'Hello';\n", $ops[0]['insert']);
        $this->assertEquals(true, $ops[0]['attributes']['code-block']);
    }
}
