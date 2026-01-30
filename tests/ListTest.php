<?php

namespace MarkdownToQuill\Tests;

use MarkdownToQuill\Converter;
use PHPUnit\Framework\TestCase;

class ListTest extends TestCase
{
    private Converter $converter;

    protected function setUp(): void
    {
        $this->converter = new Converter();
    }

    public function testUnorderedList(): void
    {
        $markdown = "- Item 1\n- Item 2";
        $ops = $this->converter->convertToArray($markdown);
        
        $this->assertEquals('Item 1', $ops[0]['insert']);
        $this->assertEquals("\n", $ops[1]['insert']);
        $this->assertEquals('bullet', $ops[1]['attributes']['list']);

        $this->assertEquals('Item 2', $ops[2]['insert']);
        $this->assertEquals("\n", $ops[3]['insert']);
        $this->assertEquals('bullet', $ops[3]['attributes']['list']);
    }

    public function testOrderedList(): void
    {
        $markdown = "1. First\n2. Second";
        $ops = $this->converter->convertToArray($markdown);
        
        $this->assertEquals('First', $ops[0]['insert']);
        $this->assertEquals("\n", $ops[1]['insert']);
        $this->assertEquals('ordered', $ops[1]['attributes']['list']);

        $this->assertEquals('Second', $ops[2]['insert']);
        $this->assertEquals("\n", $ops[3]['insert']);
        $this->assertEquals('ordered', $ops[3]['attributes']['list']);
    }
}
