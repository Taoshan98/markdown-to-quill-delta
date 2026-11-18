<?php

namespace Taoshan\QuillMarkdown\Tests;

use Taoshan\QuillMarkdown\QuillMarkdown;
use PHPUnit\Framework\TestCase;

class CodeTest extends TestCase
{
    private QuillMarkdown $converter;

    protected function setUp(): void
    {
        $this->converter = new QuillMarkdown();
    }

    public function testInlineCode(): void
    {
        $ops = $this->converter->toDeltaOps("This is `inline code`.");
        
        $this->assertEquals('This is ', $ops[0]['insert']);
        
        $this->assertEquals('inline code', $ops[1]['insert']);
        $this->assertEquals(true, $ops[1]['attributes']['code']);
        
        $this->assertEquals('.', $ops[2]['insert']);
    }

    public function testGenericCodeBlock(): void
    {
        $markdown = "```\ncode line 1\ncode line 2\n```";
        $ops = $this->converter->toDeltaOps($markdown);

        $expected = [
            ['insert' => 'code line 1'],
            ['insert' => "\n", 'attributes' => ['code-block' => true]],
            ['insert' => 'code line 2'],
            ['insert' => "\n", 'attributes' => ['code-block' => true]],
        ];

        $this->assertEquals($expected, $ops);
    }

    public function testLanguageSpecifiedCodeBlock(): void
    {
        $markdown = "```php\necho 'Hello';\n```";
        $ops = $this->converter->toDeltaOps($markdown);

        $expected = [
            ['insert' => "echo 'Hello';"],
            ['insert' => "\n", 'attributes' => ['code-block' => 'php']],
        ];

        $this->assertEquals($expected, $ops);
    }
}
