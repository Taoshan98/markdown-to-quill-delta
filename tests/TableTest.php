<?php

namespace MarkdownToQuill\Tests;

use MarkdownToQuill\Converter;
use PHPUnit\Framework\TestCase;

class TableTest extends TestCase
{
    private Converter $converter;

    protected function setUp(): void
    {
        $this->converter = new Converter();
    }

    public function testSimpleTable(): void
    {
        $markdown = "| Header 1 | Header 2 |\n|---|---|\n| Cell 1 | Cell 2 |";
        $ops = $this->converter->convertToArray($markdown);

        // Expected structure is a bit open to interpretation for Quill.
        // We will target a structure where each cell is a line with 'table' attribute set to row ID.
        // Row 1 (Header)
        $this->assertEquals('Header 1', $ops[0]['insert']);
        $this->assertArrayHasKey('table', $ops[1]['attributes']);
        $row1 = $ops[1]['attributes']['table'];
        
        $this->assertEquals('Header 2', $ops[2]['insert']);
        $this->assertEquals($row1, $ops[3]['attributes']['table']);

        // Row 2 (Separator is skipped visually, or processed?)
        // Standard markdown GFM parsing skips the separator line in output.
        // So next ops should be Cell 1.
        
        $this->assertEquals('Cell 1', $ops[4]['insert']);
        $this->assertArrayHasKey('table', $ops[5]['attributes']);
        $row2 = $ops[5]['attributes']['table'];
        $this->assertNotEquals($row1, $row2); // Different row ID
        
        $this->assertEquals('Cell 2', $ops[6]['insert']);
        $this->assertEquals($row2, $ops[7]['attributes']['table']);
    }

    public function testTableWithInlineFormat(): void
    {
        $markdown = "| **Bold** | *Italic* |\n|---|---|";
        $markdown = "| **Bold** | *Italic* |\n|---|---|";
        $ops = $this->converter->convertToArray($markdown);

        // Cell 1
        $this->assertEquals('Bold', $ops[0]['insert']);
        $this->assertEquals(true, $ops[0]['attributes']['bold']);
        // cell 1 op: insert "Bold", attr bold
        // cell 1 newline op: insert "\n", attr table=row1
        $this->assertEquals("\n", $ops[1]['insert']); 
        $this->assertArrayHasKey('table', $ops[1]['attributes']);
        
        // cell 2 op: insert "Italic", attr italic
        $this->assertEquals('Italic', $ops[2]['insert']);
        
        // cell 2 newline op: insert "\n", attr table=row1
        $this->assertEquals("\n", $ops[3]['insert']);
        $this->assertArrayHasKey('table', $ops[3]['attributes']);
    }
}
