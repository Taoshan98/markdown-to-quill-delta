<?php

namespace MarkdownToQuill\Tests;

use MarkdownToQuill\Converter;
use PHPUnit\Framework\TestCase;

class NestedListTest extends TestCase
{
    private Converter $converter;

    protected function setUp(): void
    {
        $this->converter = new Converter();
    }

    public function testNestedUnorderedList(): void
    {
        $markdown = implode("\n", [
            "- Level 1",
            "  - Level 2",
            "    - Level 3"
        ]);

        $ops = $this->converter->convertToArray($markdown);

        // Level 1
        $this->assertEquals('Level 1', $ops[0]['insert']);
        $this->assertEquals('bullet', $ops[1]['attributes']['list']);
        $this->assertArrayNotHasKey('indent', $ops[1]['attributes']);

        // Level 2
        $this->assertEquals('Level 2', $ops[2]['insert']);
        $this->assertEquals('bullet', $ops[3]['attributes']['list']);
        $this->assertEquals(1, $ops[3]['attributes']['indent']);

        // Level 3
        $this->assertEquals('Level 3', $ops[4]['insert']);
        $this->assertEquals('bullet', $ops[5]['attributes']['list']);
        $this->assertEquals(2, $ops[5]['attributes']['indent']);
    }

    public function testNestedOrderedList(): void
    {
        $markdown = implode("\n", [
            "1. Level 1",
            "  1. Level 2"
        ]);

        $ops = $this->converter->convertToArray($markdown);

        // Level 1
        $this->assertEquals('Level 1', $ops[0]['insert']);
        $this->assertEquals('ordered', $ops[1]['attributes']['list']);

        // Level 2
        $this->assertEquals('Level 2', $ops[2]['insert']);
        $this->assertEquals('ordered', $ops[3]['attributes']['list']);
        $this->assertEquals(1, $ops[3]['attributes']['indent']);
    }

    public function testMixedNestedList(): void
    {
        $markdown = implode("\n", [
            "- Level 1 Bullet",
            "  1. Level 2 Ordered",
            "    - Level 3 Bullet"
        ]);

        $ops = $this->converter->convertToArray($markdown);

        // Level 1
        $this->assertEquals('bullet', $ops[1]['attributes']['list']);

        // Level 2
        $this->assertEquals('ordered', $ops[3]['attributes']['list']);
        $this->assertEquals(1, $ops[3]['attributes']['indent']);

        // Level 3
        $this->assertEquals('bullet', $ops[5]['attributes']['list']);
        $this->assertEquals(2, $ops[5]['attributes']['indent']);
    }

    public function testTabIndentation(): void
    {
        $markdown = "- Level 1\n\t- Level 2";

        $ops = $this->converter->convertToArray($markdown);

        $this->assertEquals('Level 2', $ops[2]['insert']);
        $this->assertEquals(1, $ops[3]['attributes']['indent']);
    }
}
