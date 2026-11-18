<?php

namespace Taoshan\QuillMarkdown\MarkdownToDelta;

use Taoshan\QuillMarkdown\Config\Options;
use Taoshan\QuillMarkdown\Extension\ExtensionRegistry;

/**
 * Parses inline Markdown formatting into Quill Delta operations.
 */
final class InlineParser
{
    /** @var array<string, string> */
    private array $references = [];

    /** @var array<string, string> */
    private array $footnotes = [];

    /** @var array<string, string> */
    private array $abbreviations = [];

    private ?string $abbrRegex = null;

    public function __construct(
        private readonly Options $options = new Options(),
        private readonly ExtensionRegistry $extensions = new ExtensionRegistry(),
    ) {
    }

    /**
     * @param array<string, string> $references
     */
    public function setReferences(array $references): void
    {
        $this->references = $references;
    }

    /**
     * @param array<string, string> $footnotes
     */
    public function setFootnotes(array $footnotes): void
    {
        $this->footnotes = $footnotes;
    }

    /**
     * @param array<string, string> $abbreviations
     */
    public function setAbbreviations(array $abbreviations): void
    {
        $this->abbreviations = $abbreviations;
        $this->abbrRegex = null;

        $keys = array_filter(
            array_map('strval', array_keys($abbreviations)),
            static fn(string $key): bool => $key !== ''
        );

        if ($keys !== []) {
            // Sort by length descending so longer abbreviations match before shorter prefixes.
            usort($keys, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
            $quotedKeys = array_map(
                static fn(string $key): string => preg_quote($key, '/'),
                $keys
            );
            $this->abbrRegex = '/(?<![\p{L}\p{N}_])(' . implode('|', $quotedKeys) . ')(?![\p{L}\p{N}_])/u';
        }
    }

    /**
     * Parses a text string and returns a compacted list of inline Delta operations.
     *
     * @return list<array{insert: string|array<string, mixed>, attributes?: array<string, mixed>}>
     */
    public function parse(string $text): array
    {
        if ($text === '') {
            return [];
        }

        return $this->processInlineFormatting($text);
    }

    /**
     * Internal recursive processor for inline formatting.
     *
     * @return list<array{insert: string|array<string, mixed>, attributes?: array<string, mixed>}>
     */
    private function processInlineFormatting(string $text): array
    {
        $ops = [];
        $i = 0;
        $len = strlen($text);

        while ($i < $len) {
            $nextSpecial = $this->findNextSpecialChar($text, $i);

            $abbrMatch = null;
            $abbrPos = $len + 1;

            if ($this->abbrRegex !== null) {
                if (preg_match($this->abbrRegex, $text, $matches, PREG_OFFSET_CAPTURE, $i) === 1) {
                    $abbrPos = $matches[0][1];
                    $abbrMatch = $matches[0][0];
                }
            }

            // Check custom inline Markdown extensions.
            $customMatch = $this->findEarliestCustomPatternMatch($text, $i);
            if (
                $customMatch !== null
                && $customMatch['pos'] <= $nextSpecial
                && $customMatch['pos'] <= $abbrPos
            ) {
                $flatMatches = array_map(
                    static fn(array $capture): string => (string) $capture[0],
                    $customMatch['matches']
                );
                $result = ($customMatch['handler'])($flatMatches);
                if ($result !== null) {
                    if ($customMatch['pos'] > $i) {
                        $this->pushOp($ops, substr($text, $i, $customMatch['pos'] - $i));
                    }
                    $this->pushOp($ops, $result['insert'], $result['attributes'] ?? []);
                    $i = $customMatch['pos'] + strlen($flatMatches[0]);
                    continue;
                }
            }

            if ($abbrPos < $nextSpecial && $abbrMatch !== null) {
                if ($abbrPos > $i) {
                    $this->pushOp($ops, substr($text, $i, $abbrPos - $i));
                }

                $definition = $this->abbreviations[$abbrMatch] ?? '';
                $this->pushOp($ops, $abbrMatch, ['title' => $definition]);

                $i = $abbrPos + strlen($abbrMatch);
                continue;
            }

            if ($nextSpecial > $i) {
                $this->pushOp($ops, substr($text, $i, $nextSpecial - $i));
                $i = $nextSpecial;
            }

            if ($i >= $len) {
                break;
            }

            $matched = false;
            $char = $text[$i];

            // Backslash escape for ASCII punctuation.
            if ($char === '\\') {
                if ($i + 1 < $len && ctype_punct($text[$i + 1])) {
                    $this->pushOp($ops, $text[$i + 1]);
                    $i += 2;
                } else {
                    $this->pushOp($ops, '\\');
                    $i++;
                }
                continue;
            }

            // Inline Code Spans (supports multi-backtick fences per CommonMark §6.1).
            if ($char === '`') {
                $tickCount = strspn($text, '`', $i);
                $span = $this->findCodeSpanEnd($text, $i);
                if ($span !== null) {
                    $code = substr($text, $span['contentStart'], $span['contentEnd'] - $span['contentStart']);
                    if (strlen($code) >= 2 && $code[0] === ' ' && substr($code, -1) === ' ' && trim($code, ' ') !== '') {
                        $code = substr($code, 1, -1);
                    }
                    $this->pushOp($ops, $code, ['code' => true]);
                    $i = $span['afterPos'];
                } else {
                    $this->pushOp($ops, substr($text, $i, $tickCount));
                    $i += $tickCount;
                }
                continue;
            }

            // Autolinks (<https://...>, <user@example.com>) and optional <u>...</u> underline HTML
            if ($char === '<') {
                $slice = substr($text, $i);
                if ($this->options->preserveUnderlineHtml && strncasecmp($slice, '<u>', 3) === 0) {
                    $closeTagPos = $this->findClosingHtmlTag($text, $i + 3, 'u');
                    if ($closeTagPos !== null && $closeTagPos > $i + 3) {
                        $innerContent = substr($text, $i + 3, $closeTagPos - ($i + 3));
                        $this->appendFormatted($ops, $innerContent, ['underline' => true]);
                        $i = $closeTagPos + 4;
                        $matched = true;
                    }
                } elseif (preg_match('/^<(https?:\/\/[^\s<>]+)>/i', $slice, $matches) === 1) {
                    if ($this->isSafeUrl($matches[1])) {
                        $this->pushOp($ops, $matches[1], ['link' => $matches[1]]);
                        $i += strlen($matches[0]);
                        $matched = true;
                    }
                } elseif (preg_match('/^<([a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,})>/', $slice, $matches) === 1) {
                    $this->pushOp($ops, $matches[1], ['link' => 'mailto:' . $matches[1]]);
                    $i += strlen($matches[0]);
                    $matched = true;
                }
            }

            // Footnotes: [^id]
            if (!$matched && substr($text, $i, 2) === '[^') {
                $endBracket = strpos($text, ']', $i + 2);
                if ($endBracket !== false) {
                    $fnId = substr($text, $i + 2, $endBracket - ($i + 2));
                    if ($fnId !== '' && preg_match('/[\s\[\]]/', $fnId) !== 1 && array_key_exists($fnId, $this->footnotes)) {
                        $this->pushOp($ops, $fnId, [
                            'link' => '#fn-' . $fnId,
                            'script' => 'super',
                        ]);
                        $i = $endBracket + 1;
                        $matched = true;
                    }
                }
            }

            // Images: ![alt](url "title"), ![alt][id], ![alt][], ![id]
            if (!$matched && substr($text, $i, 2) === '![') {
                $endBracket = $this->findMatchingBracket($text, $i + 1);
                if ($endBracket !== null) {
                    $altText = substr($text, $i + 2, $endBracket - ($i + 2));
                    $afterBracket = $endBracket + 1;
                    $nextChar = $afterBracket < $len ? $text[$afterBracket] : '';

                    if ($nextChar === '(') {
                        $dest = $this->parseInlineDestination($text, $afterBracket);
                        if ($dest !== null && $this->isSafeUrl($dest['url'], true)) {
                            $this->pushOp($ops, ['image' => $dest['url']]);
                            $i = $dest['endPos'] + 1;
                            $matched = true;
                        }
                    } elseif ($nextChar === '[') {
                        $endRefBracket = $this->findSimpleClosingBracket($text, $afterBracket + 1);
                        if ($endRefBracket !== null) {
                            $refId = substr($text, $afterBracket + 1, $endRefBracket - ($afterBracket + 1));
                            $lookupKey = strtolower($refId === '' ? $altText : $refId);
                            if ($lookupKey !== '' && isset($this->references[$lookupKey])) {
                                $url = $this->references[$lookupKey];
                                if ($this->isSafeUrl($url, true)) {
                                    $this->pushOp($ops, ['image' => $url]);
                                    $i = $endRefBracket + 1;
                                    $matched = true;
                                }
                            }
                        }
                    } elseif ($altText !== '' && !str_contains($altText, '[')) {
                        $lookupKey = strtolower($altText);
                        if (isset($this->references[$lookupKey])) {
                            $url = $this->references[$lookupKey];
                            if ($this->isSafeUrl($url, true)) {
                                $this->pushOp($ops, ['image' => $url]);
                                $i = $endBracket + 1;
                                $matched = true;
                            }
                        }
                    }
                }
            }

            // Links: [text](url "title"), [text][id], [text][], [id]
            if (!$matched && $char === '[' && substr($text, $i, 2) !== '[^') {
                $endBracket = $this->findMatchingBracket($text, $i);
                if ($endBracket !== null) {
                    $linkText = substr($text, $i + 1, $endBracket - ($i + 1));
                    $afterBracket = $endBracket + 1;
                    $nextChar = $afterBracket < $len ? $text[$afterBracket] : '';

                    if ($nextChar === '(') {
                        $dest = $this->parseInlineDestination($text, $afterBracket);
                        if ($dest !== null && $linkText !== '' && $this->isSafeUrl($dest['url'])) {
                            $this->appendFormatted($ops, $linkText, ['link' => $dest['url']]);
                            $i = $dest['endPos'] + 1;
                            $matched = true;
                        }
                    } elseif ($nextChar === '[') {
                        $endRefBracket = $this->findSimpleClosingBracket($text, $afterBracket + 1);
                        if ($endRefBracket !== null) {
                            $refId = substr($text, $afterBracket + 1, $endRefBracket - ($afterBracket + 1));
                            $lookupKey = strtolower($refId === '' ? $linkText : $refId);
                            if ($lookupKey !== '' && $linkText !== '' && isset($this->references[$lookupKey])) {
                                $url = $this->references[$lookupKey];
                                if ($this->isSafeUrl($url)) {
                                    $this->appendFormatted($ops, $linkText, ['link' => $url]);
                                    $i = $endRefBracket + 1;
                                    $matched = true;
                                }
                            }
                        }
                    } elseif ($linkText !== '' && !str_contains($linkText, '[')) {
                        $lookupKey = strtolower($linkText);
                        if (isset($this->references[$lookupKey])) {
                            $url = $this->references[$lookupKey];
                            if ($this->isSafeUrl($url)) {
                                $this->appendFormatted($ops, $linkText, ['link' => $url]);
                                $i = $endBracket + 1;
                                $matched = true;
                            }
                        }
                    }
                }
            }

            // Bold / Italic: **text**, __text__, *text*, _text_
            if (!$matched && ($char === '*' || $char === '_')) {
                $runLen = strspn($text, $char, $i);
                if (!$this->canOpenEmphasis($text, $i, $runLen, $char)) {
                    $this->pushOp($ops, substr($text, $i, $runLen));
                    $i += $runLen;
                    continue;
                }

                $attempts = [];
                if ($runLen >= 2) {
                    $attempts[] = ['len' => 2, 'balanced' => true, 'attr' => 'bold'];
                }
                $attempts[] = ['len' => 1, 'balanced' => true, 'attr' => 'italic'];
                if ($runLen >= 2) {
                    $attempts[] = ['len' => 2, 'balanced' => false, 'attr' => 'bold'];
                }
                $attempts[] = ['len' => 1, 'balanced' => false, 'attr' => 'italic'];

                foreach ($attempts as $attempt) {
                    $innerStart = $i + $attempt['len'];
                    $closePos = $this->findEmphasisCloser($text, $innerStart, $char, $attempt['len'], $attempt['balanced']);
                    if ($closePos !== null && $closePos > $innerStart) {
                        $innerContent = substr($text, $innerStart, $closePos - $innerStart);
                        $this->appendFormatted($ops, $innerContent, [$attempt['attr'] => true]);
                        $i = $closePos + $attempt['len'];
                        $matched = true;
                        break;
                    }
                }

                if (!$matched) {
                    $this->pushOp($ops, substr($text, $i, $runLen));
                    $i += $runLen;
                    continue;
                }
            }

            // Strikethrough (~~text~~) and Subscript (~text~)
            if (!$matched && $char === '~') {
                $runLen = strspn($text, '~', $i);
                if ($runLen === 2) {
                    $closePos = $this->findDoubleDelimiterCloser($text, $i + 2, '~');
                    if ($closePos !== null && $closePos > $i + 2) {
                        $this->appendFormatted($ops, substr($text, $i + 2, $closePos - ($i + 2)), ['strike' => true]);
                        $i = $closePos + 2;
                        $matched = true;
                    }
                } elseif ($runLen === 1) {
                    $closePos = $this->findSingleDelimiterCloser($text, $i + 1, '~');
                    if ($closePos !== null && $closePos > $i + 1) {
                        $this->appendFormatted($ops, substr($text, $i + 1, $closePos - ($i + 1)), ['script' => 'sub']);
                        $i = $closePos + 1;
                        $matched = true;
                    }
                }

                if (!$matched) {
                    $this->pushOp($ops, substr($text, $i, $runLen));
                    $i += $runLen;
                    continue;
                }
            }

            // Highlight: ==text==
            if (!$matched && $char === '=') {
                $runLen = strspn($text, '=', $i);
                if ($runLen === 2) {
                    $closePos = $this->findDoubleDelimiterCloser($text, $i + 2, '=');
                    if ($closePos !== null && $closePos > $i + 2) {
                        $this->appendFormatted($ops, substr($text, $i + 2, $closePos - ($i + 2)), [
                            'background' => $this->options->highlightColor,
                        ]);
                        $i = $closePos + 2;
                        $matched = true;
                    }
                }

                if (!$matched) {
                    $this->pushOp($ops, substr($text, $i, $runLen));
                    $i += $runLen;
                    continue;
                }
            }

            // Superscript: ^text^
            if (!$matched && $char === '^') {
                $runLen = strspn($text, '^', $i);
                $afterBracketOpen = $i > 0 && $text[$i - 1] === '[';
                if ($runLen === 1 && !$afterBracketOpen) {
                    $closePos = $this->findSingleDelimiterCloser($text, $i + 1, '^');
                    if ($closePos !== null && $closePos > $i + 1) {
                        $this->appendFormatted($ops, substr($text, $i + 1, $closePos - ($i + 1)), ['script' => 'super']);
                        $i = $closePos + 1;
                        $matched = true;
                    }
                }

                if (!$matched) {
                    $this->pushOp($ops, substr($text, $i, $runLen));
                    $i += $runLen;
                    continue;
                }
            }

            // Emoji: :smile:
            if (!$matched && $char === ':') {
                if (preg_match('/^:([a-z0-9_+\-]+):/', substr($text, $i), $matches) === 1) {
                    $emoji = $this->getEmoji($matches[1]);
                    if ($emoji !== null) {
                        $this->pushOp($ops, $emoji);
                        $i += strlen($matches[0]);
                        $matched = true;
                    }
                }
            }

            if (!$matched) {
                $this->pushOp($ops, $char);
                $i++;
            }
        }

        return $ops;
    }

    /**
     * @return array{pos: int, matches: array<int|string, array{0: string, 1: int}>, handler: callable(array<int|string, string>): ?array{insert: string|array<string, mixed>, attributes?: array<string, mixed>}}|null
     */
    private function findEarliestCustomPatternMatch(string $text, int $offset): ?array
    {
        $patterns = $this->extensions->getInlineMarkdownPatterns();
        if ($patterns === []) {
            return null;
        }

        $best = null;
        foreach ($patterns as $entry) {
            if (preg_match($entry['pattern'], $text, $matches, PREG_OFFSET_CAPTURE, $offset) === 1) {
                if ($matches[0][0] === '') {
                    continue;
                }
                $matchPos = (int) $matches[0][1];
                if ($best === null || $matchPos < $best['pos']) {
                    $best = [
                        'pos' => $matchPos,
                        'matches' => $matches,
                        'handler' => $entry['handler'],
                    ];
                }
            }
        }

        return $best;
    }

    private function findClosingHtmlTag(string $text, int $start, string $tagName): ?int
    {
        $closeTag = '</' . $tagName . '>';
        $closeLen = strlen($closeTag);
        $len = strlen($text);
        $k = $start;

        while ($k + $closeLen <= $len) {
            $skipped = $this->skipProtectedInlineConstruct($text, $k);
            if ($skipped !== null) {
                $k = $skipped;
                continue;
            }

            if (strncasecmp(substr($text, $k, $closeLen), $closeTag, $closeLen) === 0) {
                return $k;
            }
            $k++;
        }

        return null;
    }

    /**
     * @param list<array{insert: string|array<string, mixed>, attributes?: array<string, mixed>}> $ops
     * @param array<string, mixed> $extraAttributes
     */
    private function appendFormatted(array &$ops, string $innerText, array $extraAttributes): void
    {
        $innerOps = $this->processInlineFormatting($innerText);
        foreach ($innerOps as $op) {
            $merged = array_merge($op['attributes'] ?? [], $extraAttributes);
            $this->pushOp($ops, $op['insert'], $merged);
        }
    }

    /**
     * @param list<array{insert: string|array<string, mixed>, attributes?: array<string, mixed>}> $ops
     * @param string|array<string, mixed> $insert
     * @param array<string, mixed> $attributes
     */
    private function pushOp(array &$ops, string|array $insert, array $attributes = []): void
    {
        if ($insert === '') {
            return;
        }

        if ($attributes !== []) {
            ksort($attributes);
        }

        if (is_string($insert) && $ops !== []) {
            $lastIndex = count($ops) - 1;
            $lastInsert = $ops[$lastIndex]['insert'];
            $lastAttributes = $ops[$lastIndex]['attributes'] ?? [];
            if (is_string($lastInsert) && $lastAttributes === $attributes) {
                $ops[$lastIndex]['insert'] = $lastInsert . $insert;
                return;
            }
        }

        $op = ['insert' => $insert];
        if ($attributes !== []) {
            $op['attributes'] = $attributes;
        }
        $ops[] = $op;
    }

    private function findNextSpecialChar(string $text, int $start): int
    {
        $specialChars = ['\\', '*', '_', '`', '~', '[', '!', '=', '^', ':', '<'];
        $minPos = strlen($text);

        foreach ($specialChars as $char) {
            $pos = strpos($text, $char, $start);
            if ($pos !== false && $pos < $minPos) {
                $minPos = $pos;
            }
        }

        return $minPos;
    }

    /**
     * @return array{tickCount: int, contentStart: int, contentEnd: int, afterPos: int}|null
     */
    private function findCodeSpanEnd(string $text, int $start): ?array
    {
        $tickCount = strspn($text, '`', $start);
        $pos = $start + $tickCount;
        $len = strlen($text);

        while ($pos < $len) {
            $nextTick = strpos($text, '`', $pos);
            if ($nextTick === false) {
                return null;
            }

            $closeCount = strspn($text, '`', $nextTick);
            if ($closeCount === $tickCount) {
                return [
                    'tickCount' => $tickCount,
                    'contentStart' => $start + $tickCount,
                    'contentEnd' => $nextTick,
                    'afterPos' => $nextTick + $closeCount,
                ];
            }

            $pos = $nextTick + $closeCount;
        }

        return null;
    }

    private function skipProtectedInlineConstruct(string $text, int $pos, bool $skipLinks = true): ?int
    {
        $len = strlen($text);
        if ($pos >= $len) {
            return null;
        }

        $char = $text[$pos];

        if ($char === '\\') {
            return ($pos + 1 < $len && ctype_punct($text[$pos + 1])) ? $pos + 2 : $pos + 1;
        }

        if ($char === '`') {
            $span = $this->findCodeSpanEnd($text, $pos);
            return $span !== null ? $span['afterPos'] : ($pos + strspn($text, '`', $pos));
        }

        if ($char === '<') {
            $slice = substr($text, $pos);
            if (preg_match('/^<(?:https?:\/\/[^\s<>]+|[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,})>/i', $slice, $m) === 1) {
                return $pos + strlen($m[0]);
            }
        }

        if ($skipLinks && ($char === '[' || ($char === '!' && $pos + 1 < $len && $text[$pos + 1] === '['))) {
            $bracketStart = $char === '!' ? $pos + 1 : $pos;
            $endBracket = $this->findMatchingBracket($text, $bracketStart);
            if ($endBracket !== null && $endBracket + 1 < $len && $text[$endBracket + 1] === '(') {
                $dest = $this->parseInlineDestination($text, $endBracket + 1);
                if ($dest !== null) {
                    return $dest['endPos'] + 1;
                }
            }
        }

        return null;
    }

    private function findMatchingBracket(string $text, int $openBracketPos): ?int
    {
        $len = strlen($text);
        if ($openBracketPos >= $len || $text[$openBracketPos] !== '[') {
            return null;
        }

        $depth = 0;
        $k = $openBracketPos;

        while ($k < $len) {
            $skipped = $this->skipProtectedInlineConstruct($text, $k, false);
            if ($skipped !== null) {
                $k = $skipped;
                continue;
            }

            if ($text[$k] === '[') {
                $depth++;
            } elseif ($text[$k] === ']') {
                $depth--;
                if ($depth === 0) {
                    return $k;
                }
            }
            $k++;
        }

        return null;
    }

    private function findSimpleClosingBracket(string $text, int $start): ?int
    {
        $len = strlen($text);
        $k = $start;

        while ($k < $len) {
            if ($text[$k] === '\\' && $k + 1 < $len && ctype_punct($text[$k + 1])) {
                $k += 2;
                continue;
            }
            if ($text[$k] === '[') {
                return null;
            }
            if ($text[$k] === ']') {
                return $k;
            }
            $k++;
        }

        return null;
    }

    /**
     * @return array{url: string, endPos: int}|null
     */
    private function parseInlineDestination(string $text, int $openParenPos): ?array
    {
        $len = strlen($text);
        if ($openParenPos >= $len || $text[$openParenPos] !== '(') {
            return null;
        }

        $k = $openParenPos + 1;
        while ($k < $len && ($text[$k] === ' ' || $text[$k] === "\t")) {
            $k++;
        }

        if ($k >= $len) {
            return null;
        }

        $url = '';
        if ($text[$k] === '<') {
            $k++;
            $foundGt = false;
            while ($k < $len) {
                if ($text[$k] === '\\' && $k + 1 < $len && ctype_punct($text[$k + 1])) {
                    $url .= $text[$k + 1];
                    $k += 2;
                    continue;
                }
                if ($text[$k] === '>') {
                    $foundGt = true;
                    $k++;
                    break;
                }
                if ($text[$k] === '<' || $text[$k] === "\n" || $text[$k] === "\r") {
                    return null;
                }
                $url .= $text[$k];
                $k++;
            }
            if (!$foundGt) {
                return null;
            }
        } else {
            $parenDepth = 0;
            while ($k < $len) {
                $ch = $text[$k];
                if ($ch === '\\' && $k + 1 < $len && ctype_punct($text[$k + 1])) {
                    $url .= $text[$k + 1];
                    $k += 2;
                    continue;
                }
                if ($ch === '(') {
                    $parenDepth++;
                    $url .= $ch;
                    $k++;
                    continue;
                }
                if ($ch === ')') {
                    if ($parenDepth === 0) {
                        break;
                    }
                    $parenDepth--;
                    $url .= $ch;
                    $k++;
                    continue;
                }
                if ($ch === ' ' || $ch === "\t" || $ch === "\n" || $ch === "\r") {
                    break;
                }
                if (ord($ch) < 32 || ord($ch) === 127) {
                    return null;
                }
                $url .= $ch;
                $k++;
            }
            if ($parenDepth !== 0) {
                return null;
            }
        }

        if ($url === '') {
            return null;
        }

        $hadSpaceBeforeTitle = false;
        while ($k < $len && ($text[$k] === ' ' || $text[$k] === "\t")) {
            $hadSpaceBeforeTitle = true;
            $k++;
        }

        if ($k < $len && $text[$k] === ')') {
            return ['url' => $url, 'endPos' => $k];
        }

        if (!$hadSpaceBeforeTitle || $k >= $len) {
            return null;
        }

        $quote = $text[$k];
        if ($quote !== '"' && $quote !== "'" && $quote !== '(') {
            return null;
        }

        $closeQuote = $quote === '(' ? ')' : $quote;
        $k++;
        $foundClose = false;

        while ($k < $len) {
            if ($text[$k] === '\\' && $k + 1 < $len && ctype_punct($text[$k + 1])) {
                $k += 2;
                continue;
            }
            if ($quote === '(' && $text[$k] === '(') {
                return null;
            }
            if ($text[$k] === $closeQuote) {
                $foundClose = true;
                $k++;
                break;
            }
            $k++;
        }

        if (!$foundClose) {
            return null;
        }

        while ($k < $len && ($text[$k] === ' ' || $text[$k] === "\t")) {
            $k++;
        }

        if ($k < $len && $text[$k] === ')') {
            return ['url' => $url, 'endPos' => $k];
        }

        return null;
    }

    private function isSafeUrl(string $url, bool $isImage = false): bool
    {
        $trimmed = trim($url);
        if ($trimmed === '' || preg_match('/[\x00-\x1F\x7F]/', $trimmed) === 1) {
            return false;
        }

        if (preg_match('/^([a-zA-Z][a-zA-Z0-9+.\-]*):/', $trimmed, $matches) === 1) {
            $scheme = strtolower($matches[1]);
            if (in_array($scheme, ['http', 'https', 'mailto', 'tel', 'ftp'], true)) {
                return true;
            }
            if ($isImage && $scheme === 'data') {
                return preg_match('/^data:image\/(?:png|jpe?g|gif|webp);base64,[a-zA-Z0-9+\/]+=*$/i', $trimmed) === 1;
            }
            return false;
        }

        return true;
    }

    private function findEmphasisCloser(string $text, int $innerStart, string $marker, int $openLen, bool $trackInnerBalance): ?int
    {
        $len = strlen($text);
        $k = $innerStart;
        $pendingInner = 0;

        while ($k < $len) {
            $skipped = $this->skipProtectedInlineConstruct($text, $k);
            if ($skipped !== null) {
                $k = $skipped;
                continue;
            }

            if ($text[$k] !== $marker) {
                $k++;
                continue;
            }

            $cLen = strspn($text, $marker, $k);
            $canClose = $this->canCloseEmphasis($text, $k, $cLen, $marker, $innerStart);
            $canOpen = $this->canOpenEmphasis($text, $k, $cLen, $marker, $innerStart);

            if ($canClose) {
                $available = $cLen;
                if ($trackInnerBalance && $pendingInner > 0) {
                    $consumed = min($pendingInner, $available);
                    $pendingInner -= $consumed;
                    $available -= $consumed;
                }

                if ($available >= $openLen) {
                    return $k + ($cLen - $available);
                }

                if ($trackInnerBalance && $canOpen && $available > 0) {
                    $pendingInner += $available;
                }
            } elseif ($trackInnerBalance && $canOpen) {
                $pendingInner += $cLen;
            }

            $k += $cLen;
        }

        return null;
    }

    private function canOpenEmphasis(string $text, int $pos, int $runLen, string $marker, int $sliceStart = 0): bool
    {
        $info = $this->classifyFlanking($text, $pos, $runLen, $sliceStart);
        if (!$info['left']) {
            return false;
        }

        if ($marker === '_') {
            return !$info['right'] || $info['prevPunct'];
        }

        return true;
    }

    private function canCloseEmphasis(string $text, int $pos, int $runLen, string $marker, int $sliceStart = 0): bool
    {
        $info = $this->classifyFlanking($text, $pos, $runLen, $sliceStart);
        if (!$info['right']) {
            return false;
        }

        if ($marker === '_') {
            return !$info['left'] || $info['nextPunct'];
        }

        return true;
    }

    /**
     * @return array{left: bool, right: bool, prevPunct: bool, nextPunct: bool}
     */
    private function classifyFlanking(string $text, int $pos, int $runLen, int $sliceStart): array
    {
        $prev = $this->getPrevUtf8Char($text, $pos, $sliceStart);
        $next = $this->getNextUtf8Char($text, $pos + $runLen);

        $prevSpace = $this->isWhitespaceChar($prev);
        $nextSpace = $this->isWhitespaceChar($next);
        $prevPunct = $this->isPunctuationChar($prev);
        $nextPunct = $this->isPunctuationChar($next);

        return [
            'left' => !$nextSpace && (!$nextPunct || $prevSpace || $prevPunct),
            'right' => !$prevSpace && (!$prevPunct || $nextSpace || $nextPunct),
            'prevPunct' => $prevPunct,
            'nextPunct' => $nextPunct,
        ];
    }

    private function findDoubleDelimiterCloser(string $text, int $innerStart, string $marker): ?int
    {
        $len = strlen($text);
        if ($innerStart >= $len || $this->isWhitespaceChar($this->getNextUtf8Char($text, $innerStart))) {
            return null;
        }

        $k = $innerStart;
        while ($k < $len) {
            $skipped = $this->skipProtectedInlineConstruct($text, $k);
            if ($skipped !== null) {
                $k = $skipped;
                continue;
            }

            if ($text[$k] === $marker) {
                $cLen = strspn($text, $marker, $k);
                if ($cLen === 2 && $k > $innerStart && !$this->isWhitespaceChar($this->getPrevUtf8Char($text, $k, $innerStart))) {
                    return $k;
                }
                $k += $cLen;
                continue;
            }

            $k++;
        }

        return null;
    }

    private function findSingleDelimiterCloser(string $text, int $innerStart, string $marker): ?int
    {
        $len = strlen($text);
        if ($innerStart >= $len || $this->isWhitespaceChar($this->getNextUtf8Char($text, $innerStart))) {
            return null;
        }

        $k = $innerStart;
        while ($k < $len) {
            $skipped = $this->skipProtectedInlineConstruct($text, $k);
            if ($skipped !== null) {
                $k = $skipped;
                continue;
            }

            $nextChar = $this->getNextUtf8Char($text, $k);
            if ($this->isWhitespaceChar($nextChar)) {
                return null;
            }

            if ($text[$k] === $marker) {
                $cLen = strspn($text, $marker, $k);
                if ($cLen === 1 && $k > $innerStart && !$this->isWhitespaceChar($this->getPrevUtf8Char($text, $k, $innerStart))) {
                    return $k;
                }
                $k += $cLen;
                continue;
            }

            $k++;
        }

        return null;
    }

    private function getPrevUtf8Char(string $text, int $bytePos, int $sliceStart = 0): string
    {
        if ($bytePos <= $sliceStart) {
            return ' ';
        }
        $start = max($sliceStart, $bytePos - 4);
        $chunk = substr($text, $start, $bytePos - $start);
        if (preg_match('/.\z/us', $chunk, $matches) === 1) {
            return $matches[0];
        }
        return $text[$bytePos - 1];
    }

    private function getNextUtf8Char(string $text, int $afterBytePos): string
    {
        if ($afterBytePos >= strlen($text)) {
            return ' ';
        }
        if (preg_match('/./us', $text, $matches, 0, $afterBytePos) === 1) {
            return $matches[0];
        }
        return $text[$afterBytePos];
    }

    private function isWhitespaceChar(string $char): bool
    {
        return preg_match('/^\s\z/u', $char) === 1;
    }

    private function isPunctuationChar(string $char): bool
    {
        return preg_match('/^[\p{P}\p{S}]\z/u', $char) === 1;
    }

    private function getEmoji(string $shortcode): ?string
    {
        $map = [
            'smile' => '😄',
            'thumbsup' => '👍',
            'tada' => '🎉',
            'heart' => '❤️',
            'rocket' => '🚀',
            'laughing' => '😆',
            'wink' => '😉',
            'cry' => '😢',
            'sunny' => '☀️',
            'cloud' => '☁️',
        ];
        return $map[$shortcode] ?? null;
    }
}
