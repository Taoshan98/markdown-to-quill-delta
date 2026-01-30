<?php

namespace MarkdownToQuill\Tests;

use MarkdownToQuill\Converter;
use PHPUnit\Framework\TestCase;

class CheckboxTest extends TestCase
{
    private Converter $converter;

    protected function setUp(): void
    {
        $this->converter = new Converter();
    }

    public function testUncheckedItem(): void
    {
        $ops = $this->converter->convertToArray("- [ ] Unchecked Task");

        $expected = [
            ['insert' => 'Unchecked Task'],
            ['insert' => "\n", 'attributes' => ['list' => 'unchecked']]
        ];

        $this->assertEquals($expected, $ops);
    }

    public function testCheckedItem(): void
    {
        $ops = $this->converter->convertToArray("- [x] Checked Task");

        $expected = [
            ['insert' => 'Checked Task'],
            ['insert' => "\n", 'attributes' => ['list' => 'checked']]
        ];

        $this->assertEquals($expected, $ops);
    }

    public function testCheckedItemUppercase(): void
    {
        $ops = $this->converter->convertToArray("- [X] Checked Task UPPER");

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

        $ops = $this->converter->convertToArray($markdown);

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
        $ops = $this->converter->convertToArray("- [ ] Task with **bold** and *italic*");

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
        $ops = $this->converter->convertToArray("* [ ] Asterisk Task");
        $lastOp = end($ops);
        $this->assertEquals('unchecked', $lastOp['attributes']['list']);

        // Test with plus
        $ops = $this->converter->convertToArray("+ [x] Plus Task");
        $lastOp = end($ops);
        $this->assertEquals('checked', $lastOp['attributes']['list']);
        
        // Test with number (technically supported by GitHub flavored markdown as a task list)
        $ops = $this->converter->convertToArray("1. [ ] Ordered Task");
        $lastOp = end($ops);
        $this->assertEquals('unchecked', $lastOp['attributes']['list']);
    }

    public function testMalformedCheckboxesAreNormalLists(): void
    {
        // Missing space inside brackets
        $ops = $this->converter->convertToArray("- [] Not a checkbox");
        // InlineParser splits '[' and '] ...' so we have multiple inserts.
        // The list attribute should be on the newline at the end.
        $lastOp = end($ops);
        $this->assertEquals('bullet', $lastOp['attributes']['list']);
        $this->assertEquals("\n", $lastOp['insert']);

        // Missing space after brackets
        $ops = $this->converter->convertToArray("- [ ]No space");
        // Depending on implementation, strict GFM requires a space. 
        // We will assume standard GFM behavior: if no space, it might just be text.
        // Let's assert based on intended strict behavior.
        $this->assertEquals('bullet', end($ops)['attributes']['list']);
        // InlineParser splits '[' and '] ...'
        $this->assertEquals('[', $ops[0]['insert']);
    }
}
