<?php

namespace Taoshan\QuillMarkdown\Tests;

use Taoshan\QuillMarkdown\QuillMarkdown;
use PHPUnit\Framework\TestCase;

class CheckboxTest extends TestCase
{
    private QuillMarkdown $converter;

    protected function setUp(): void
    {
        $this->converter = new QuillMarkdown();
    }

    public function testUncheckedItem(): void
    {
        $ops = $this->converter->toDeltaOps("- [ ] Unchecked Task");

        $expected = [
            ['insert' => 'Unchecked Task'],
            ['insert' => "\n", 'attributes' => ['list' => 'unchecked']]
        ];

        $this->assertEquals($expected, $ops);
    }

    public function testCheckedItem(): void
    {
        $ops = $this->converter->toDeltaOps("- [x] Checked Task");

        $expected = [
            ['insert' => 'Checked Task'],
            ['insert' => "\n", 'attributes' => ['list' => 'checked']]
        ];

        $this->assertEquals($expected, $ops);
    }

    public function testCheckedItemUppercase(): void
    {
        $ops = $this->converter->toDeltaOps("- [X] Checked Task UPPER");

        $expected = [
            ['insert' => 'Checked Task UPPER'],
            ['insert' => "\n", 'attributes' => ['list' => 'checked']]
        ];

        $this->assertEquals($expected, $ops);
    }

    public function testMixedList(): void
    {
        $markdown = implode("\n", [
            "- [ ] Item 1",
            "- [x] Item 2",
            "- [ ] Item 3",
            "- [x] Item 4"
        ]);

        $ops = $this->converter->toDeltaOps($markdown);

        $expected = [
            ['insert' => 'Item 1'],
            ['insert' => "\n", 'attributes' => ['list' => 'unchecked']],
            ['insert' => 'Item 2'],
            ['insert' => "\n", 'attributes' => ['list' => 'checked']],
            ['insert' => 'Item 3'],
            ['insert' => "\n", 'attributes' => ['list' => 'unchecked']],
            ['insert' => 'Item 4'],
            ['insert' => "\n", 'attributes' => ['list' => 'checked']],
        ];

        $this->assertEquals($expected, $ops);
    }

    public function testCheckboxWithInlineFormatting(): void
    {
        $ops = $this->converter->toDeltaOps("- [ ] Task with **bold** and *italic*");

        $expected = [
            ['insert' => 'Task with '],
            ['insert' => 'bold', 'attributes' => ['bold' => true]],
            ['insert' => ' and '],
            ['insert' => 'italic', 'attributes' => ['italic' => true]],
            ['insert' => "\n", 'attributes' => ['list' => 'unchecked']]
        ];

        $this->assertEquals($expected, $ops);
    }

    public function testCheckboxWithDifferentMarkers(): void
    {
        // Test with asterisk
        $ops = $this->converter->toDeltaOps("* [ ] Asterisk Task");
        $lastOp = end($ops);
        $this->assertEquals('unchecked', $lastOp['attributes']['list']);

        // Test with plus
        $ops = $this->converter->toDeltaOps("+ [x] Plus Task");
        $lastOp = end($ops);
        $this->assertEquals('checked', $lastOp['attributes']['list']);
        
        // Test with number (technically supported by GitHub flavored markdown as a task list)
        $ops = $this->converter->toDeltaOps("1. [ ] Ordered Task");
        $lastOp = end($ops);
        $this->assertEquals('unchecked', $lastOp['attributes']['list']);
    }

    public function testMalformedCheckboxesAreNormalLists(): void
    {
        // Missing space inside brackets
        $ops = $this->converter->toDeltaOps("- [] Not a checkbox");
        $this->assertEquals('[] Not a checkbox', $ops[0]['insert']);
        $lastOp = end($ops);
        $this->assertEquals('bullet', $lastOp['attributes']['list']);
        $this->assertEquals("\n", $lastOp['insert']);

        // Missing space after brackets
        $ops = $this->converter->toDeltaOps("- [ ]No space");
        $this->assertEquals('bullet', end($ops)['attributes']['list']);
        $this->assertEquals('[ ]No space', $ops[0]['insert']);
    }
}
