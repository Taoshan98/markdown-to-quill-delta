<?php

namespace MarkdownToQuill\Tests;

use MarkdownToQuill\Converter;
use PHPUnit\Framework\TestCase;

class DefinitionListTest extends TestCase
{
    private Converter $converter;

    protected function setUp(): void
    {
        $this->converter = new Converter();
    }

    public function testDefinitionList(): void
    {
        $markdown = "Term\n: Definition";
        $ops = $this->converter->convertToArray($markdown);

        // Term (Paragraph)
        $this->assertEquals('Term', $ops[0]['insert']);
        $this->assertEquals("\n", $ops[1]['insert']);

        // Definition (Indented)
        // Expected: "Definition", \n with indent 1
        $this->assertEquals('Definition', $ops[2]['insert']);
        
        // Ensure indentation
        $this->assertEquals("\n", $ops[3]['insert']);
        $this->assertEquals(1, $ops[3]['attributes']['indent']);
        
        // Ensure NO list bullet
        $this->assertArrayNotHasKey('list', $ops[3]['attributes']);
    }

    public function testDefinitionWithInlineFormat(): void
    {
        $markdown = "Term\n: **Bold** Definition";
        $ops = $this->converter->convertToArray($markdown);
        
        $this->assertEquals('Bold', $ops[2]['insert']);
        $this->assertEquals(true, $ops[2]['attributes']['bold']);
        
        // Assert newline index (depends on number of inline ops)
        // 0: Term, 1: \n
        // 2: Bold, 3: Definition
        // 4: \n with indent
        $this->assertEquals("\n", $ops[4]['insert']);
        $this->assertEquals(1, $ops[4]['attributes']['indent']);
    }
}
