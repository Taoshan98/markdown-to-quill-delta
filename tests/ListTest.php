<?php

namespace Taoshan\QuillMarkdown\Tests;

use Taoshan\QuillMarkdown\QuillMarkdown;
use PHPUnit\Framework\TestCase;

class ListTest extends TestCase
{
    private QuillMarkdown $converter;

    protected function setUp(): void
    {
        $this->converter = new QuillMarkdown();
    }

    public function testUnorderedList(): void
    {
        $markdown = "- Item 1\n- Item 2";
        $ops = $this->converter->toDeltaOps($markdown);
        
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
        $ops = $this->converter->toDeltaOps($markdown);
        
        $this->assertEquals('First', $ops[0]['insert']);
        $this->assertEquals("\n", $ops[1]['insert']);
        $this->assertEquals('ordered', $ops[1]['attributes']['list']);

        $this->assertEquals('Second', $ops[2]['insert']);
        $this->assertEquals("\n", $ops[3]['insert']);
        $this->assertEquals('ordered', $ops[3]['attributes']['list']);
    }
}
