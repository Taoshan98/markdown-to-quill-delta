<?php

namespace MarkdownToQuill\Parser;

/**
 * Parses inline Markdown formatting into Quill Delta operations.
 */
class InlineParser
{
    /**
     * Parses a text string and returns an array of operations.
     *
     * @param string $text The text to parse.
     * @return array<array<string, mixed>>
     */
    public function parse(string $text): array
    {
        if (empty($text)) {
            return [];
        }

        return $this->processInlineFormatting($text);
    }

    /**
     * Internal recursive processor for inline formatting.
     */
    private function processInlineFormatting(string $text): array
    {
        $ops = [];
        $i = 0;
        $len = strlen($text);

        while ($i < $len) {
            $nextSpecial = $this->findNextSpecialChar($text, $i);

            if ($nextSpecial > $i) {
                $ops[] = ['insert' => substr($text, $i, $nextSpecial - $i)];
                $i = $nextSpecial;
            }

            if ($i >= $len) {
                break;
            }

            $matched = false;

            // Image: ![alt](url)
            if (substr($text, $i, 2) === '![' && ($endBracket = strpos($text, ']', $i + 2)) !== false) {
                if (substr($text, $endBracket + 1, 1) === '(' && ($endParen = strpos($text, ')', $endBracket + 2)) !== false) {
                    $url = substr($text, $endBracket + 2, $endParen - ($endBracket + 2));
                    $ops[] = ['insert' => ['image' => $url]];
                    $i = $endParen + 1;
                    $matched = true;
                }
            }

            // Link: [text](url)
            if (!$matched && substr($text, $i, 1) === '[' && ($endBracket = strpos($text, ']', $i + 1)) !== false) {
                if (substr($text, $endBracket + 1, 1) === '(' && ($endParen = strpos($text, ')', $endBracket + 2)) !== false) {
                    $linkText = substr($text, $i + 1, $endBracket - ($i + 1));
                    $url = substr($text, $endBracket + 2, $endParen - ($endBracket + 2));
                    $innerOps = $this->processInlineFormatting($linkText);
                    foreach ($innerOps as $op) {
                        $op['attributes'] = array_merge($op['attributes'] ?? [], ['link' => $url]);
                        $ops[] = $op;
                    }
                    $i = $endParen + 1;
                    $matched = true;
                }
            }

            // Bold: **text** or __text__
            if (!$matched && (substr($text, $i, 2) === '**' || substr($text, $i, 2) === '__')) {
                $marker = substr($text, $i, 2);
                if (($endPos = strpos($text, $marker, $i + 2)) !== false) {
                    $innerContent = substr($text, $i + 2, $endPos - ($i + 2));
                    $innerOps = $this->processInlineFormatting($innerContent);
                    foreach ($innerOps as $op) {
                        $op['attributes'] = array_merge($op['attributes'] ?? [], ['bold' => true]);
                        $ops[] = $op;
                    }
                    $i = $endPos + 2;
                    $matched = true;
                }
            }

            // Italic: *text* or _text_
            if (!$matched && (substr($text, $i, 1) === '*' || substr($text, $i, 1) === '_')) {
                $marker = substr($text, $i, 1);
                if (($endPos = strpos($text, $marker, $i + 1)) !== false) {
                    $innerContent = substr($text, $i + 1, $endPos - ($i + 1));
                    $innerOps = $this->processInlineFormatting($innerContent);
                    foreach ($innerOps as $op) {
                        $op['attributes'] = array_merge($op['attributes'] ?? [], ['italic' => true]);
                        $ops[] = $op;
                    }
                    $i = $endPos + 1;
                    $matched = true;
                }
            }

            // Strikethrough: ~~text~~
            if (!$matched && substr($text, $i, 2) === '~~') {
                if (($endPos = strpos($text, '~~', $i + 2)) !== false) {
                    $innerContent = substr($text, $i + 2, $endPos - ($i + 2));
                    $innerOps = $this->processInlineFormatting($innerContent);
                    foreach ($innerOps as $op) {
                        $op['attributes'] = array_merge($op['attributes'] ?? [], ['strike' => true]);
                        $ops[] = $op;
                    }
                    $i = $endPos + 2;
                    $matched = true;
                }
            }

            // Inline Code: `text`
            if (!$matched && substr($text, $i, 1) === '`') {
                if (($endPos = strpos($text, '`', $i + 1)) !== false) {
                    $code = substr($text, $i + 1, $endPos - ($i + 1));
                    $ops[] = ['insert' => $code, 'attributes' => ['code' => true]];
                    $i = $endPos + 1;
                    $matched = true;
                }
            }

            if (!$matched) {
                $ops[] = ['insert' => substr($text, $i, 1)];
                $i++;
            }
        }

        return $ops;
    }

    /**
     * Finds the next special character position.
     */
    private function findNextSpecialChar(string $text, int $start): int
    {
        $specialChars = ['*', '_', '`', '~', '[', '!'];
        $minPos = strlen($text);

        foreach ($specialChars as $char) {
            $pos = strpos($text, $char, $start);
            if ($pos !== false && $pos < $minPos) {
                $minPos = $pos;
            }
        }

        return $minPos;
    }
}
