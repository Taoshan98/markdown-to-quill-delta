<?php

namespace Taoshan\QuillMarkdown\MarkdownToDelta;

use Taoshan\QuillMarkdown\Config\Options;
use Taoshan\QuillMarkdown\Delta\Delta;
use Taoshan\QuillMarkdown\Extension\ExtensionRegistry;

/**
 * Parses block-level and inline Markdown into a Quill Delta document.
 */
final class MarkdownParser
{
    private readonly InlineParser $inlineParser;

    public function __construct(
        private readonly Options $options = new Options(),
        ExtensionRegistry $extensions = new ExtensionRegistry(),
    ) {
        $this->inlineParser = new InlineParser($this->options, $extensions);
    }

    /**
     * Converts a Markdown string to a Delta document.
     */
    public function parse(string $markdown): Delta
    {
        $this->inlineParser->setReferences([]);
        $this->inlineParser->setFootnotes([]);
        $this->inlineParser->setAbbreviations([]);

        $delta = new Delta();
        if ($markdown === '') {
            return $delta;
        }

        $normalized = str_replace(["\r\n", "\r"], "\n", $markdown);
        $hadTrailingNewline = substr($normalized, -1) === "\n";
        if ($hadTrailingNewline) {
            $normalized = substr($normalized, 0, -1);
        }

        if ($normalized === '') {
            $delta->insert("\n");
            return $this->options->compactDelta ? $delta->compact() : $delta;
        }

        $rawLines = explode("\n", $normalized);
        if (count($rawLines) === 1 && !$hadTrailingNewline && trim($rawLines[0]) === '') {
            return $delta;
        }

        // Fence-aware pre-scan for reference links, footnotes, and abbreviations.
        $references = [];
        $footnotes = [];
        $abbreviations = [];
        $cleanedLines = [];

        $inFence = false;
        $fenceChar = '';
        $fenceLen = 0;

        $seenContent = false;
        $strippedBeforeContent = false;
        $strippedSinceLastContent = false;

        foreach ($rawLines as $line) {
            if ($inFence) {
                if ($this->isClosingFence($line, $fenceChar, $fenceLen)) {
                    $inFence = false;
                }
                $seenContent = true;
                $strippedSinceLastContent = false;
                $cleanedLines[] = $line;
                continue;
            }

            $openingFence = $this->matchOpeningFence($line);
            if ($openingFence !== null) {
                if (!$seenContent && $strippedBeforeContent) {
                    $cleanedLines = [];
                }
                $inFence = true;
                $fenceChar = $openingFence['char'];
                $fenceLen = $openingFence['len'];
                $seenContent = true;
                $strippedSinceLastContent = false;
                $cleanedLines[] = $line;
                continue;
            }

            if (preg_match('/^ {0,3}\[([^\]\^]+)\]:[ \t]*(?:<([^>\n]+)>|(\S+))(?:[ \t]+(?:"(?:\\\\.|[^"])*"|\'(?:\\\\.|[^\'])*\'|\((?:\\\\.|[^)])*\)))?[ \t]*$/', $line, $matches) === 1) {
                $id = strtolower(trim($matches[1]));
                $url = ($matches[2] ?? '') !== '' ? $matches[2] : ($matches[3] ?? '');
                if ($id !== '' && !isset($references[$id])) {
                    $references[$id] = $url;
                }
                if (!$seenContent) {
                    $strippedBeforeContent = true;
                } else {
                    $strippedSinceLastContent = true;
                }
                continue;
            }

            if (preg_match('/^ {0,3}\[\^([^\s\]]+)\]:[ \t]*(.*)$/', $line, $matches) === 1) {
                $footnotes[$matches[1]] = $matches[2];
                if (!$seenContent) {
                    $strippedBeforeContent = true;
                } else {
                    $strippedSinceLastContent = true;
                }
                continue;
            }

            if (preg_match('/^ {0,3}\*\[([^\]]+)\]:[ \t]*(.*)$/', $line, $matches) === 1) {
                $abbreviations[$matches[1]] = $matches[2];
                if (!$seenContent) {
                    $strippedBeforeContent = true;
                } else {
                    $strippedSinceLastContent = true;
                }
                continue;
            }

            if (trim($line) === '') {
                if ($strippedSinceLastContent && $cleanedLines !== [] && trim((string) end($cleanedLines)) === '') {
                    continue;
                }
                $cleanedLines[] = $line;
                continue;
            }

            if (!$seenContent && $strippedBeforeContent) {
                $cleanedLines = [];
            }

            $seenContent = true;
            $strippedSinceLastContent = false;
            $cleanedLines[] = $line;
        }

        if (!$seenContent && $strippedBeforeContent) {
            $cleanedLines = [];
        } elseif ($strippedSinceLastContent) {
            while ($cleanedLines !== [] && trim((string) end($cleanedLines)) === '') {
                array_pop($cleanedLines);
            }
        }

        $this->inlineParser->setReferences($references);
        $this->inlineParser->setFootnotes($footnotes);
        $this->inlineParser->setAbbreviations($abbreviations);

        $lines = $cleanedLines;
        $count = count($lines);
        $listIndentStack = [];
        $tableRowCounter = 0;

        for ($i = 0; $i < $count; $i++) {
            $line = $lines[$i];

            // Fenced Code Blocks (``` or ~~~)
            $openingFence = $this->matchOpeningFence($line);
            if ($openingFence !== null) {
                $listIndentStack = [];
                $language = '';
                if ($openingFence['info'] !== '') {
                    $infoParts = preg_split('/\s+/', $openingFence['info']);
                    $language = is_array($infoParts) ? $infoParts[0] : '';
                }
                $codeAttr = $language !== '' ? $language : true;

                $codeLines = [];
                $i++;
                while ($i < $count && !$this->isClosingFence($lines[$i], $openingFence['char'], $openingFence['len'])) {
                    $codeLines[] = $lines[$i];
                    $i++;
                }

                if ($codeLines === []) {
                    $delta->insert("\n", ['code-block' => $codeAttr]);
                } else {
                    foreach ($codeLines as $codeLine) {
                        if ($codeLine !== '') {
                            $delta->insert($codeLine);
                        }
                        $delta->insert("\n", ['code-block' => $codeAttr]);
                    }
                }
                continue;
            }

            // ATX Headers
            if (preg_match('/^ {0,3}(#{1,6})(?:[ \t]+|$)(.*)$/', $line, $matches) === 1) {
                $listIndentStack = [];
                $level = strlen($matches[1]);
                $content = preg_replace('/(?:^|[ \t]+)#+[ \t]*$/', '', $matches[2]) ?? $matches[2];
                $this->appendInline($delta, $content);
                $delta->insert("\n", ['header' => $level]);
                continue;
            }

            // Blockquotes
            if (preg_match('/^ {0,3}>[ \t]?(.*)$/', $line, $matches) === 1) {
                $listIndentStack = [];
                $this->appendInline($delta, $matches[1]);
                $delta->insert("\n", ['blockquote' => true]);
                continue;
            }

            // GFM Tables
            if (str_contains($line, '|') && isset($lines[$i + 1])) {
                $headerCells = $this->splitTableRow($line);
                $alignments = $this->parseTableSeparator($lines[$i + 1], count($headerCells));

                if ($alignments !== null) {
                    $listIndentStack = [];
                    $tableLines = [$line, $lines[$i + 1]];
                    $i += 2;

                    while (
                        $i < $count
                        && trim($lines[$i]) !== ''
                        && str_contains($lines[$i], '|')
                        && $this->matchOpeningFence($lines[$i]) === null
                    ) {
                        $tableLines[] = $lines[$i];
                        $i++;
                    }
                    $i--;

                    $colCount = count($headerCells);
                    foreach ($tableLines as $index => $tableLine) {
                        if ($index === 1) {
                            continue;
                        }

                        $tableRowCounter++;
                        $cells = $index === 0 ? $headerCells : $this->splitTableRow($tableLine);

                        for ($colIdx = 0; $colIdx < $colCount; $colIdx++) {
                            $cellContent = $cells[$colIdx] ?? '';
                            $this->appendInline($delta, $cellContent);

                            $attrs = ['table' => 'row-' . $tableRowCounter];
                            if ($alignments[$colIdx] !== null) {
                                $attrs['align'] = $alignments[$colIdx];
                            }
                            $delta->insert("\n", $attrs);
                        }
                    }
                    continue;
                }
            }

            // Thematic Breaks (Horizontal Rules)
            if (preg_match('/^ {0,3}(?:(?:\*[ \t]*){3,}|(?:-[ \t]*){3,}|(?:_[ \t]*){3,})$/', $line) === 1) {
                $listIndentStack = [];
                if ($this->options->enableDividerBlot) {
                    $delta->insert(['divider' => true]);
                } else {
                    $delta->insert(trim($line));
                    $delta->insert("\n");
                }
                continue;
            }

            // Lists & Task Checkboxes
            if (preg_match('/^([ \t]*)([*+-]|\d+[.)])[ \t]+(.*)$/', $line, $matches) === 1) {
                $whitespace = $matches[1];
                $marker = $matches[2];
                $content = $matches[3];

                $colWidth = 0;
                $wsLen = strlen($whitespace);
                for ($w = 0; $w < $wsLen; $w++) {
                    if ($whitespace[$w] === "\t") {
                        $colWidth = ((int) floor($colWidth / 4) + 1) * 4;
                    } else {
                        $colWidth++;
                    }
                }

                if ($listIndentStack === []) {
                    $listIndentStack = [0];
                    if ($colWidth > 0) {
                        $listIndentStack[] = $colWidth;
                    }
                } else {
                    while (count($listIndentStack) > 1 && $colWidth < end($listIndentStack)) {
                        array_pop($listIndentStack);
                    }
                    if ($colWidth > end($listIndentStack)) {
                        $listIndentStack[] = $colWidth;
                    }
                }
                $indent = count($listIndentStack) - 1;

                $type = is_numeric($marker[0]) ? 'ordered' : 'bullet';

                if (preg_match('/^\[([ xX])\](?:[ \t]+(.*)|$)/', $content, $checkboxMatches) === 1) {
                    $type = strtolower($checkboxMatches[1]) === 'x' ? 'checked' : 'unchecked';
                    $content = $checkboxMatches[2] ?? '';
                }

                $this->appendInline($delta, $content);

                $attributes = ['list' => $type];
                if ($indent > 0) {
                    $attributes['indent'] = $indent;
                }

                $delta->insert("\n", $attributes);
                continue;
            }

            $listIndentStack = [];

            // Definition Lists
            if ($i > 0 && trim($lines[$i - 1]) !== '' && preg_match('/^ {0,3}:[ \t]+(.*)$/', $line, $matches) === 1) {
                $this->appendInline($delta, $matches[1]);
                $delta->insert("\n", ['indent' => 1]);
                continue;
            }

            // Regular Paragraph or Blank Line
            if (trim($line) !== '') {
                $this->appendInline($delta, $line);
                $delta->insert("\n");
            } else {
                $delta->insert("\n");
            }
        }

        // Render footnote definitions at the bottom of the document.
        if ($footnotes !== []) {
            foreach ($footnotes as $fnId => $fnContent) {
                $delta->insert((string) $fnId, ['script' => 'super']);
                $this->appendInline($delta, ': ' . $fnContent);
                $delta->insert("\n");
            }
        }

        return $this->options->compactDelta ? $delta->compact() : $delta;
    }

    private function appendInline(Delta $delta, string $text): void
    {
        $ops = $this->inlineParser->parse($text);
        foreach ($ops as $op) {
            $delta->insert($op['insert'], $op['attributes'] ?? []);
        }
    }

    /**
     * @return array{char: string, len: int, info: string}|null
     */
    private function matchOpeningFence(string $line): ?array
    {
        if (preg_match('/^ {0,3}(`{3,}|~{3,})(.*)$/', $line, $matches) !== 1) {
            return null;
        }

        $fenceStr = $matches[1];
        $info = $matches[2];
        $char = $fenceStr[0];

        if ($char === '`' && str_contains($info, '`')) {
            return null;
        }

        return [
            'char' => $char,
            'len' => strlen($fenceStr),
            'info' => trim($info),
        ];
    }

    private function isClosingFence(string $line, string $char, int $len): bool
    {
        return preg_match('/^ {0,3}' . preg_quote($char, '/') . '{' . $len . ',}[ \t]*$/', $line) === 1;
    }

    /**
     * @return list<string>
     */
    private function splitTableRow(string $rowLine): array
    {
        $trimmed = trim($rowLine);
        if ($trimmed !== '' && $trimmed[0] === '|') {
            $trimmed = substr($trimmed, 1);
        }

        $len = strlen($trimmed);
        if ($len > 0 && $trimmed[$len - 1] === '|') {
            $backslashes = 0;
            for ($idx = $len - 2; $idx >= 0 && $trimmed[$idx] === '\\'; $idx--) {
                $backslashes++;
            }
            if ($backslashes % 2 === 0) {
                $trimmed = substr($trimmed, 0, -1);
                $len = strlen($trimmed);
            }
        }

        $cells = [];
        $current = '';
        $i = 0;

        while ($i < $len) {
            $ch = $trimmed[$i];

            if ($ch === '\\' && $i + 1 < $len) {
                $current .= $ch . $trimmed[$i + 1];
                $i += 2;
                continue;
            }

            if ($ch === '`') {
                $tickCount = strspn($trimmed, '`', $i);
                $pos = $i + $tickCount;
                $closePos = null;

                while ($pos < $len) {
                    $nextTick = strpos($trimmed, '`', $pos);
                    if ($nextTick === false) {
                        break;
                    }
                    $closeCount = strspn($trimmed, '`', $nextTick);
                    if ($closeCount === $tickCount) {
                        $closePos = $nextTick + $closeCount;
                        break;
                    }
                    $pos = $nextTick + $closeCount;
                }

                if ($closePos !== null) {
                    $current .= substr($trimmed, $i, $closePos - $i);
                    $i = $closePos;
                } else {
                    $current .= substr($trimmed, $i, $tickCount);
                    $i += $tickCount;
                }
                continue;
            }

            if ($ch === '|') {
                $cells[] = trim($current);
                $current = '';
                $i++;
                continue;
            }

            $current .= $ch;
            $i++;
        }

        $cells[] = trim($current);
        return $cells;
    }

    /**
     * @return list<string|null>|null
     */
    private function parseTableSeparator(string $sepLine, int $expectedCols): ?array
    {
        if ($expectedCols < 1 || !str_contains($sepLine, '|')) {
            return null;
        }

        $sepCells = $this->splitTableRow($sepLine);
        if (count($sepCells) !== $expectedCols) {
            return null;
        }

        $alignments = [];
        foreach ($sepCells as $cell) {
            if (preg_match('/^(:?)-+(:?)$/', $cell, $matches) !== 1) {
                return null;
            }

            $left = $matches[1] === ':';
            $right = $matches[2] === ':';

            if ($left && $right) {
                $alignments[] = 'center';
            } elseif ($right) {
                $alignments[] = 'right';
            } else {
                $alignments[] = null;
            }
        }

        return $alignments;
    }
}
