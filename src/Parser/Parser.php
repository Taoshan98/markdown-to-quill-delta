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
                $type = is_numeric(substr(trim($matches[2]), 0, 1)) ? 'ordered' : 'bullet';
                $content = $matches[3];
                $this->appendInline($delta, $content);
                $delta->insert("\n", ['list' => $type]);
                continue;
            }

            // Horizontal Rule
            if (preg_match('/^([-*_]){3,}$/', $line)) {
                $delta->insert(['divider' => true]);
                $delta->insert("\n");
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
