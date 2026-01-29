<?php

namespace MarkdownToQuill\Tests;

use MarkdownToQuill\Converter;
use PHPUnit\Framework\TestCase;

class ConverterTest extends TestCase
{
    private Converter $converter;

    protected function setUp(): void
    {
        $this->converter = new Converter();
    }

    public function testSimpleText(): void
    {
        $ops = $this->converter->convert("Hello World");
        
        $expected = [
            ['insert' => 'Hello World'],
            ['insert' => "\n"]
        ];
        
        $this->assertEquals($expected, $ops);
    }
    
    public function testBold(): void
    {
        $ops = $this->converter->convert("This is **bold** text");
        
        $expected = [
            ['insert' => 'This is '],
            ['insert' => 'bold', 'attributes' => ['bold' => true]],
            ['insert' => ' text'],
            ['insert' => "\n"]
        ];
        
        $this->assertEquals($expected, $ops);
    }
    
    public function testItalic(): void
    {
        $ops = $this->converter->convert("This is *italic* text");
        
        $expected = [
            ['insert' => 'This is '],
            ['insert' => 'italic', 'attributes' => ['italic' => true]],
            ['insert' => ' text'],
            ['insert' => "\n"]
        ];
        
        $this->assertEquals($expected, $ops);
    }
    
    public function testHeader(): void
    {
        $ops = $this->converter->convert("# Header 1");
        
        $expected = [
            ['insert' => 'Header 1'],
            ['insert' => "\n", 'attributes' => ['header' => 1]]
        ];
        
        $this->assertEquals($expected, $ops);
    }
    
    public function testCodeBlock(): void
    {
        $markdown = "```\ncode line 1\ncode line 2\n```";
        $ops = $this->converter->convert($markdown);
        
        $this->assertCount(2, $ops);
        $this->assertEquals('code line 1' . "\n", $ops[0]['insert']);
        $this->assertTrue($ops[0]['attributes']['code-block']);
        $this->assertEquals('code line 2' . "\n", $ops[1]['insert']);
        $this->assertTrue($ops[1]['attributes']['code-block']);
    }
    
    public function testInlineCode(): void
    {
        $ops = $this->converter->convert("This is `code` inline");
        
        $expected = [
            ['insert' => 'This is '],
            ['insert' => 'code', 'attributes' => ['code' => true]],
            ['insert' => ' inline'],
            ['insert' => "\n"]
        ];
        
        $this->assertEquals($expected, $ops);
    }
    
    public function testBlockquote(): void
    {
        $ops = $this->converter->convert("> Quote text");
        
        $expected = [
            ['insert' => 'Quote text'],
            ['insert' => "\n", 'attributes' => ['blockquote' => true]]
        ];
        
        $this->assertEquals($expected, $ops);
    }
    
    public function testUnorderedList(): void
    {
        $ops = $this->converter->convert("- Item 1\n- Item 2");
        
        $this->assertEquals('Item 1', $ops[0]['insert']);
        $this->assertEquals('bullet', $ops[1]['attributes']['list']);
        $this->assertEquals('Item 2', $ops[2]['insert']);
        $this->assertEquals('bullet', $ops[3]['attributes']['list']);
    }
    
    public function testOrderedList(): void
    {
        $ops = $this->converter->convert("1. Item 1\n2. Item 2");
        
        $this->assertEquals('Item 1', $ops[0]['insert']);
        $this->assertEquals('ordered', $ops[1]['attributes']['list']);
        $this->assertEquals('Item 2', $ops[2]['insert']);
        $this->assertEquals('ordered', $ops[3]['attributes']['list']);
    }
    
    public function testLink(): void
    {
        $ops = $this->converter->convert("[Click here](https://example.com)");
        
        $expected = [
            ['insert' => 'Click here', 'attributes' => ['link' => 'https://example.com']],
            ['insert' => "\n"]
        ];
        
        $this->assertEquals($expected, $ops);
    }
    
    public function testImage(): void
    {
        $ops = $this->converter->convert("![Alt text](https://example.com/image.jpg)");
        
        $this->assertArrayHasKey('image', $ops[0]['insert']);
        $this->assertEquals('https://example.com/image.jpg', $ops[0]['insert']['image']);
    }
    
    public function testStrikethrough(): void
    {
        $ops = $this->converter->convert("This is ~~deleted~~ text");
        
        $expected = [
            ['insert' => 'This is '],
            ['insert' => 'deleted', 'attributes' => ['strike' => true]],
            ['insert' => ' text'],
            ['insert' => "\n"]
        ];
        
        $this->assertEquals($expected, $ops);
    }
}
