<?php

namespace Taoshan\QuillMarkdown\DeltaToMarkdown;

use Taoshan\QuillMarkdown\Config\Options;
use Taoshan\QuillMarkdown\Delta\Delta;
use Taoshan\QuillMarkdown\Delta\Line;
use Taoshan\QuillMarkdown\Extension\ExtensionRegistry;

/**
 * Renders a Quill Delta document into clean Markdown (GFM + Extensions).
 */
final class DeltaRenderer
{
    private readonly InlineSerializer $inlineSerializer;

    public function __construct(
        private readonly Options $options = new Options(),
        ExtensionRegistry $extensions = new ExtensionRegistry(),
    ) {
        $this->inlineSerializer = new InlineSerializer($this->options, $extensions);
    }

    /**
     * Converts a Delta object, decoded array, or JSON string to Markdown.
     *
     * @param Delta|array<mixed>|string $input
     * @throws \JsonException
     */
    public function render(Delta|array|string $input): string
    {
        $delta = Delta::from($input);
        if ($delta->isEmpty()) {
            return '';
        }

        $lines = $delta->splitLines();
        if (count($lines) === 1 && $lines[0]->isBlank()) {
            return '';
        }

        $abbreviations = [];
        $footnoteDefs = $this->extractTrailingFootnotes($lines, $abbreviations);

        $outputLines = [];
        $lineCount = count($lines);

        for ($i = 0; $i < $lineCount; $i++) {
            $line = $lines[$i];
            $attrs = $line->attributes;

            // Horizontal Rule / Divider Embed
            if (!empty($attrs['divider'])) {
                $outputLines[] = '---';
                continue;
            }

            // Fenced Code Block Group
            if (isset($attrs['code-block'])) {
                $codeAttr = $attrs['code-block'];
                $codeLines = [];

                while ($i < $lineCount && ($lines[$i]->attributes['code-block'] ?? null) === $codeAttr) {
                    $codeLines[] = $lines[$i]->toPlainText();
                    $i++;
                }
                $i--;

                $fenceChar = $this->options->codeFenceStyle->value;
                $fenceLen = 3;
                $quotedChar = preg_quote($fenceChar, '/');

                foreach ($codeLines as $codeLine) {
                    if (preg_match_all('/' . $quotedChar . '+/', $codeLine, $matches) > 0) {
                        foreach ($matches[0] as $run) {
                            $fenceLen = max($fenceLen, strlen($run) + 1);
                        }
                    }
                }

                $fence = str_repeat($fenceChar, $fenceLen);
                $language = is_string($codeAttr) && $codeAttr !== '' && $codeAttr !== 'plain' ? $codeAttr : '';

                $outputLines[] = $fence . $language;
                if (!(count($codeLines) === 1 && $codeLines[0] === '')) {
                    foreach ($codeLines as $codeLine) {
                        $outputLines[] = $codeLine;
                    }
                }
                $outputLines[] = $fence;
                continue;
            }

            // GFM Table Group
            if (isset($attrs['table'])) {
                /** @var array<string, list<array{text: string, align: ?string}>> $rows */
                $rows = [];
                while ($i < $lineCount && isset($lines[$i]->attributes['table'])) {
                    $cellLine = $lines[$i];
                    $rowId = (string) $cellLine->attributes['table'];
                    $align = isset($cellLine->attributes['align']) && is_string($cellLine->attributes['align'])
                        ? $cellLine->attributes['align']
                        : null;
                    $cellText = $this->inlineSerializer->serialize($cellLine->inlineOps, $abbreviations, true);
                    $rows[$rowId][] = ['text' => $cellText, 'align' => $align];
                    $i++;
                }
                $i--;

                $rowList = array_values($rows);
                $colCount = 1;
                foreach ($rowList as $row) {
                    $colCount = max($colCount, count($row));
                }

                $alignments = array_fill(0, $colCount, null);
                foreach ($rowList as $row) {
                    for ($c = 0; $c < $colCount; $c++) {
                        if ($alignments[$c] === null && isset($row[$c]['align'])) {
                            $alignments[$c] = $row[$c]['align'];
                        }
                    }
                }

                $headerCells = [];
                $sepCells = [];
                for ($c = 0; $c < $colCount; $c++) {
                    $headerCells[] = $rowList[0][$c]['text'] ?? '';
                    $sepCells[] = match ($alignments[$c]) {
                        'center' => ':---:',
                        'right' => '---:',
                        'left' => ':---',
                        default => '---',
                    };
                }

                $outputLines[] = '| ' . implode(' | ', $headerCells) . ' |';
                $outputLines[] = '| ' . implode(' | ', $sepCells) . ' |';

                $totalRows = count($rowList);
                for ($r = 1; $r < $totalRows; $r++) {
                    $bodyCells = [];
                    for ($c = 0; $c < $colCount; $c++) {
                        $bodyCells[] = $rowList[$r][$c]['text'] ?? '';
                    }
                    $outputLines[] = '| ' . implode(' | ', $bodyCells) . ' |';
                }

                if (
                    $i + 1 < $lineCount
                    && !$lines[$i + 1]->isBlank()
                    && str_contains($lines[$i + 1]->toPlainText(), '|')
                ) {
                    $outputLines[] = '';
                }
                continue;
            }

            // Lists & Task Checkboxes Group
            if (isset($attrs['list'])) {
                /** @var array<int, int> $orderedCounters */
                $orderedCounters = [];
                $bullet = $this->options->bulletMarker->value;

                while ($i < $lineCount && isset($lines[$i]->attributes['list'])) {
                    $listLine = $lines[$i];
                    $listType = (string) $listLine->attributes['list'];
                    $indent = max(0, (int) ($listLine->attributes['indent'] ?? 0));
                    $itemMd = $this->inlineSerializer->serialize($listLine->inlineOps, $abbreviations);

                    foreach (array_keys($orderedCounters) as $level) {
                        if ($level > $indent) {
                            unset($orderedCounters[$level]);
                        }
                    }

                    $prefixIndent = str_repeat(' ', $indent * $this->options->listIndentSpaces);

                    if (
                        ($listType === 'bullet' || $listType === 'ordered')
                        && preg_match('/^\[[ xX]\](?:[ \t]|$)/', $itemMd) === 1
                    ) {
                        $itemMd = '\\' . $itemMd;
                    }

                    if ($listType === 'ordered') {
                        $orderedCounters[$indent] = ($orderedCounters[$indent] ?? 0) + 1;
                        $marker = $orderedCounters[$indent] . '.';
                        $outputLines[] = $itemMd !== ''
                            ? $prefixIndent . $marker . ' ' . $itemMd
                            : $prefixIndent . $marker;
                    } elseif ($listType === 'checked') {
                        unset($orderedCounters[$indent]);
                        $outputLines[] = $itemMd !== ''
                            ? $prefixIndent . $bullet . ' [x] ' . $itemMd
                            : $prefixIndent . $bullet . ' [x]';
                    } elseif ($listType === 'unchecked') {
                        unset($orderedCounters[$indent]);
                        $outputLines[] = $itemMd !== ''
                            ? $prefixIndent . $bullet . ' [ ] ' . $itemMd
                            : $prefixIndent . $bullet . ' [ ]';
                    } else {
                        unset($orderedCounters[$indent]);
                        $outputLines[] = $itemMd !== ''
                            ? $prefixIndent . $bullet . ' ' . $itemMd
                            : $prefixIndent . $bullet;
                    }

                    $i++;
                }
                $i--;
                continue;
            }

            // Blank line
            if ($line->isBlank()) {
                $outputLines[] = '';
                continue;
            }

            $inlineMd = $this->inlineSerializer->serialize($line->inlineOps, $abbreviations);

            // ATX Header
            if (isset($attrs['header'])) {
                $level = max(1, min(6, (int) $attrs['header']));
                $hashes = str_repeat('#', $level);
                if (preg_match('/^(.*?)(^|[ \t]+)(#+)[ \t]*$/', $inlineMd, $hm) === 1) {
                    $inlineMd = $hm[1] . $hm[2] . str_repeat('\\#', strlen($hm[3]));
                }
                $outputLines[] = $inlineMd !== '' ? $hashes . ' ' . $inlineMd : $hashes;
                continue;
            }

            // Blockquote
            if (!empty($attrs['blockquote'])) {
                $outputLines[] = $inlineMd !== '' ? '> ' . $inlineMd : '>';
                continue;
            }

            // Definition List Item (non-list indent)
            if (isset($attrs['indent'])) {
                $outputLines[] = ': ' . $inlineMd;
                continue;
            }

            // Plain Paragraph
            $outputLines[] = $this->inlineSerializer->escapeParagraphStart($inlineMd);
        }

        if ($abbreviations !== [] || $footnoteDefs !== []) {
            if ($outputLines !== [] && end($outputLines) !== '') {
                $outputLines[] = '';
            }
            foreach ($abbreviations as $abbr => $definition) {
                $outputLines[] = '*[' . $abbr . ']: ' . $definition;
            }
            foreach ($footnoteDefs as $fnId => $fnBody) {
                $outputLines[] = '[^' . $fnId . ']: ' . $fnBody;
            }
        }

        return implode("\n", $outputLines);
    }

    /**
     * Extracts trailing rendered footnote definition lines from the end of $lines.
     *
     * @param list<Line> $lines
     * @param array<string, string> $abbreviations
     * @return array<string, string>
     */
    private function extractTrailingFootnotes(array &$lines, array &$abbreviations): array
    {
        $referencedIds = [];
        foreach ($lines as $line) {
            foreach ($line->inlineOps as $op) {
                $opInsert = $op['insert'];
                $opAttrs = $op['attributes'] ?? [];
                if (
                    is_string($opInsert)
                    && ($opAttrs['script'] ?? null) === 'super'
                    && isset($opAttrs['link'])
                    && is_string($opAttrs['link'])
                    && str_starts_with($opAttrs['link'], '#fn-')
                ) {
                    $fnId = substr($opAttrs['link'], 4);
                    if ($fnId !== '' && $opInsert === $fnId) {
                        $referencedIds[$fnId] = true;
                    }
                }
            }
        }

        if ($referencedIds === []) {
            return [];
        }

        $extracted = [];

        while ($lines !== []) {
            $last = end($lines);
            if ($last->attributes !== [] || count($last->inlineOps) < 2) {
                break;
            }

            $op0 = $last->inlineOps[0];
            $op1 = $last->inlineOps[1];

            if (
                !is_string($op0['insert'])
                || $op0['insert'] === ''
                || !isset($referencedIds[$op0['insert']])
                || ($op0['attributes'] ?? []) !== ['script' => 'super']
                || !is_string($op1['insert'])
                || !str_starts_with($op1['insert'], ': ')
            ) {
                break;
            }

            array_pop($lines);

            $fnId = $op0['insert'];
            $bodyOps = array_slice($last->inlineOps, 1);
            $bodyOps[0]['insert'] = substr($op1['insert'], 2);
            if ($bodyOps[0]['insert'] === '') {
                array_shift($bodyOps);
            }

            $extracted[] = [
                'id' => $fnId,
                'body' => $this->inlineSerializer->serialize($bodyOps, $abbreviations),
            ];
        }

        if ($extracted === []) {
            return [];
        }

        if ($lines !== [] && end($lines)->isBlank()) {
            array_pop($lines);
        }

        $footnotes = [];
        foreach (array_reverse($extracted) as $entry) {
            $footnotes[$entry['id']] = $entry['body'];
        }

        return $footnotes;
    }
}
