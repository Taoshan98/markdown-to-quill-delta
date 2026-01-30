<?php

namespace MarkdownToQuill\Parser;

use MarkdownToQuill\Delta\Delta;

/**
 * Main parser that coordinates line-by-line parsing of Markdown.
 */
class Parser
{
    private InlineParser $inlineParser;

    public function __construct()
    {
        $this->inlineParser = new InlineParser();
    }

    /**
     * Converts Markdown string to a Delta object.
     *
     * @param string $markdown The Markdown content.
     * @return Delta
     */
    public function parse(string $markdown): Delta
    {
        $delta = new Delta();
        if (empty($markdown)) {
            return $delta;
        }

        $lines = explode("\n", str_replace("\r\n", "\n", $markdown));
        
        // Pre-scan for Reference Checks, Footnotes, and Abbreviations
        $references = [];
        $footnotes = [];
        $abbreviations = [];
        
        $cleanedLines = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            // Regex for [id]: url "title"
            if (preg_match('/^\[([^\]\^]+)\]:\s+(\S+)(?:\s+".*")?$/', $trimmed, $matches)) {
                $id = strtolower($matches[1]);
                $url = $matches[2];
                $references[$id] = $url;
            } 
            // Regex for Footnotes [^id]: content
            elseif (preg_match('/^\[\^([^\]]+)\]:\s+(.*)$/', $trimmed, $matches)) {
                $id = $matches[1];
                $content = $matches[2];
                $footnotes[$id] = $content;
            }
            // Regex for Abbreviations *[abbr]: definition
            elseif (preg_match('/^\*\[([^\]]+)\]:\s+(.*)$/', $trimmed, $matches)) {
                $abbr = $matches[1];
                $definition = $matches[2];
                $abbreviations[$abbr] = $definition;
            }
            else {
                $cleanedLines[] = $line;
            }
        }
        
        $lines = $cleanedLines;
        $this->inlineParser->setReferences($references);
        $this->inlineParser->setFootnotes($footnotes); // Need to add this method
        $this->inlineParser->setAbbreviations($abbreviations); // Need to add this method

        $count = count($lines);

        for ($i = 0; $i < $count; $i++) {
            $line = $lines[$i];

            // Code Blocks
            if (preg_match('/^```(.*)/', $line, $matches)) {
                $language = trim($matches[1]);
                $codeLines = [];
                $i++;
                while ($i < $count && !preg_match('/^```/', $lines[$i])) {
                    $codeLines[] = $lines[$i];
                    $i++;
                }
                $codeContent = implode("\n", $codeLines);
                foreach ($codeLines as $codeLine) {
                    $delta->insert($codeLine . "\n", ['code-block' => true]);
                }
                continue;
            }

            // Headers
            if (preg_match('/^(#{1,6})\s+(.*)/', $line, $matches)) {
                $level = strlen($matches[1]);
                $content = $matches[2];
                $this->appendInline($delta, $content);
                $delta->insert("\n", ['header' => $level]);
                continue;
            }

            // Blockquotes
            if (preg_match('/^>\s?(.*)/', $line, $matches)) {
                $content = $matches[1];
                $this->appendInline($delta, $content);
                $delta->insert("\n", ['blockquote' => true]);
                continue;
            }

            // Lists
            if (preg_match('/^(\s*)([*+-]|\d+\.)\s+(.*)/', $line, $matches)) {
                $whitespace = $matches[1];
                $marker = $matches[2];
                $content = $matches[3];

                // Determine indentation
                $indent = 0;
                $len = strlen($whitespace);
                
                // If tabs are used, count them. If spaces, divide by 2 or 4.
                // Common convention: 2 spaces = 1 level for bullets, 4 for ordered (but often 2 is enough).
                // Let's standardise on: 1 tab = 1 level, 2 spaces = 1 level.
                if (strpos($whitespace, "\t") !== false) {
                    $indent = substr_count($whitespace, "\t");
                } else {
                    $indent = (int) floor($len / 2);
                }

                $type = is_numeric(substr(trim($marker), 0, 1)) ? 'ordered' : 'bullet';

                // Checkbox task list support
                if (preg_match('/^\[([ xX])\]\s+(.*)/', $content, $checkboxMatches)) {
                    $isChecked = strtolower($checkboxMatches[1]) === 'x';
                    $type = $isChecked ? 'checked' : 'unchecked';
                    $content = $checkboxMatches[2];
                }

                $this->appendInline($delta, $content);
                
                $attributes = ['list' => $type];
                if ($indent > 0) {
                    $attributes['indent'] = $indent;
                }
                
                $delta->insert("\n", $attributes);
                continue;
            }



            // Tables
            // Check if current line looks like a table row and next line is a separator
            if (strpos($line, '|') !== false && isset($lines[$i + 1]) && preg_match('/^\|? *:?-{3,}:? *\|/', trim($lines[$i + 1]))) {
                // Determine columns from separator or first line?
                // Separator line: |---|---|
                $separatorLine = trim($lines[$i + 1]);
                // Consume table lines
                $tableLines = [$line, $separatorLine];
                $i += 2;
                while ($i < $count && strpos($lines[$i], '|') !== false) {
                    $tableLines[] = $lines[$i];
                    $i++;
                }
                $i--; // Backtrack one because loop increments

                // Process Table
                // Row 1 is header. Row 2 is separator (skip). Row 3+ body.
                // We use a simple auto-increment row ID.
                $rowId = 1;

                foreach ($tableLines as $index => $tableLine) {
                    if ($index === 1) {
                        continue; // Skip separator
                    }
                    
                    // Split cells
                    $cells = explode('|', trim($tableLine));
                    
                    // Remove first/last empty if pipe is at start/end
                    if (trim($cells[0]) === '') array_shift($cells);
                    if (empty($cells)) continue;
                    if (trim(end($cells)) === '') array_pop($cells);
                    
                    foreach ($cells as $cellContent) {
                        $this->appendInline($delta, trim($cellContent));
                        $delta->insert("\n", ['table' => 'row-' . $rowId]);
                    }
                    $rowId++;
                }
                continue;
            }

            // Definition Lists
            if (preg_match('/^:\s+(.*)/', $line, $matches)) {
                $content = $matches[1];
                $this->appendInline($delta, $content);
                $delta->insert("\n", ['indent' => 1]);
                continue;
            }

            // Regular Paragraph
            if (trim($line) !== '') {
                $this->appendInline($delta, $line);
                $delta->insert("\n");
            } elseif ($i < $count - 1) {
                // Keep empty lines between paragraphs if not the last line
                $delta->insert("\n");
            }
        }

        return $delta;
    }

    /**
     * Appends inline formatted text to the Delta.
     */
    private function appendInline(Delta $delta, string $text): void
    {
        $ops = $this->inlineParser->parse($text);
        foreach ($ops as $op) {
            $delta->insert($op['insert'], $op['attributes'] ?? []);
        }
    }
}
