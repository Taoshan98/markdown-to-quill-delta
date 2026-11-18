<?php

namespace Taoshan\QuillMarkdown\DeltaToMarkdown;

use Taoshan\QuillMarkdown\Config\Options;
use Taoshan\QuillMarkdown\Extension\ExtensionRegistry;

/**
 * Serializes inline Quill Delta operations for a single line into Markdown.
 */
final class InlineSerializer
{
    public function __construct(
        private readonly Options $options = new Options(),
        private readonly ExtensionRegistry $extensions = new ExtensionRegistry(),
    ) {
    }

    /**
     * Serializes a list of inline Delta operations to Markdown.
     *
     * @param list<array{insert: string|array<string, mixed>, attributes?: array<string, mixed>}> $inlineOps
     * @param array<string, string> $abbreviations Collector map of abbreviations encountered (`abbr => definition`).
     */
    public function serialize(array $inlineOps, array &$abbreviations = [], bool $inTableCell = false): string
    {
        if ($inlineOps === []) {
            return '';
        }

        $chunks = $this->splitIntoChunks($inlineOps);
        $output = '';

        foreach ($chunks as $chunk) {
            if ($chunk['type'] === 'footnote') {
                $output .= '[^' . $chunk['id'] . ']';
                continue;
            }

            if ($chunk['type'] === 'link') {
                $innerMd = $this->serializePlainGroup($chunk['ops'], $abbreviations, $inTableCell, true);
                if ($innerMd === '') {
                    continue;
                }

                $url = $this->formatDestinationUrl($chunk['url']);
                if (preg_match('/^([ \t]*)(.*?)([ \t]*)$/s', $innerMd, $m) === 1 && $m[2] !== '') {
                    $output .= $m[1] . '[' . $m[2] . '](' . $url . ')' . $m[3];
                } else {
                    $output .= '[' . $innerMd . '](' . $url . ')';
                }
                continue;
            }

            $output .= $this->serializePlainGroup($chunk['ops'], $abbreviations, $inTableCell, false);
        }

        return $output;
    }

    /**
     * Escapes block-level Markdown triggers at the start of an unformatted plain paragraph.
     */
    public function escapeParagraphStart(string $line): string
    {
        if ($line === '') {
            return '';
        }

        // Heading: # through ###### followed by space/tab or EOL
        if (preg_match('/^( {0,3})(#{1,6}(?:[ \t]|$).*)$/', $line, $m) === 1) {
            return $m[1] . '\\' . $m[2];
        }

        // Blockquote: >
        if (preg_match('/^( {0,3})(>.*)$/', $line, $m) === 1) {
            return $m[1] . '\\' . $m[2];
        }

        // Thematic break (only needs escaping when divider blot mode is active)
        if (
            $this->options->enableDividerBlot
            && preg_match('/^( {0,3})((?:\*[ \t]*){3,}|(?:-[ \t]*){3,}|(?:_[ \t]*){3,})$/', $line, $m) === 1
        ) {
            return $m[1] . '\\' . $m[2];
        }

        // Bullet list marker: -, *, + followed by space/tab
        if (preg_match('/^( {0,3})([*+-])([ \t].*)$/', $line, $m) === 1) {
            return $m[1] . '\\' . $m[2] . $m[3];
        }

        // Ordered list marker: 1. or 1) followed by space/tab
        if (preg_match('/^( {0,3}\d+)([.)])([ \t].*)$/', $line, $m) === 1) {
            return $m[1] . '\\' . $m[2] . $m[3];
        }

        // Definition list marker: : followed by space/tab
        if (preg_match('/^( {0,3})(:[ \t].*)$/', $line, $m) === 1) {
            return $m[1] . '\\' . $m[2];
        }

        // Tilde fence prefix: ~~~
        if (preg_match('/^( {0,3})(~~~.*)$/', $line, $m) === 1) {
            return $m[1] . '\~\~\~' . substr($m[2], 3);
        }

        return $line;
    }

    /**
     * @param list<array{insert: string|array<string, mixed>, attributes?: array<string, mixed>}> $inlineOps
     * @return list<array{type: 'footnote', id: string}|array{type: 'link', url: string, ops: list<array{insert: string|array<string, mixed>, attributes?: array<string, mixed>}>}|array{type: 'plain', ops: list<array{insert: string|array<string, mixed>, attributes?: array<string, mixed>}>}>
     */
    private function splitIntoChunks(array $inlineOps): array
    {
        $chunks = [];
        $count = count($inlineOps);
        $i = 0;

        while ($i < $count) {
            $op = $inlineOps[$i];
            $insert = $op['insert'];
            $attrs = $op['attributes'] ?? [];

            if (
                is_string($insert)
                && ($attrs['script'] ?? null) === 'super'
                && isset($attrs['link'])
                && is_string($attrs['link'])
                && str_starts_with($attrs['link'], '#fn-')
            ) {
                $fnId = substr($attrs['link'], 4);
                if ($fnId !== '' && $insert === $fnId) {
                    $chunks[] = ['type' => 'footnote', 'id' => $fnId];
                    $i++;
                    continue;
                }
            }

            if (isset($attrs['link']) && is_string($attrs['link']) && $attrs['link'] !== '') {
                $linkUrl = $attrs['link'];
                $groupOps = [];

                while ($i < $count) {
                    $curr = $inlineOps[$i];
                    $currInsert = $curr['insert'];
                    $currAttrs = $curr['attributes'] ?? [];

                    if (
                        is_string($currInsert)
                        && ($currAttrs['script'] ?? null) === 'super'
                        && ($currAttrs['link'] ?? null) === '#fn-' . $currInsert
                    ) {
                        break;
                    }

                    if (($currAttrs['link'] ?? null) !== $linkUrl) {
                        break;
                    }

                    unset($currAttrs['link']);
                    $strippedOp = ['insert' => $currInsert];
                    if ($currAttrs !== []) {
                        $strippedOp['attributes'] = $currAttrs;
                    }
                    $groupOps[] = $strippedOp;
                    $i++;
                }

                $chunks[] = ['type' => 'link', 'url' => $linkUrl, 'ops' => $groupOps];
                continue;
            }

            $plainOps = [];
            while ($i < $count) {
                $curr = $inlineOps[$i];
                $currAttrs = $curr['attributes'] ?? [];
                if (isset($currAttrs['link']) && is_string($currAttrs['link']) && $currAttrs['link'] !== '') {
                    break;
                }
                $plainOps[] = $curr;
                $i++;
            }

            if ($plainOps !== []) {
                $chunks[] = ['type' => 'plain', 'ops' => $plainOps];
            }
        }

        return $chunks;
    }

    /**
     * @param list<array{insert: string|array<string, mixed>, attributes?: array<string, mixed>}> $ops
     * @param array<string, string> $abbreviations
     */
    private function serializePlainGroup(array $ops, array &$abbreviations, bool $inTableCell, bool $inLinkLabel): string
    {
        /** @var list<array{text: string, styles: list<array{type: string, value: mixed}>, raw: bool}> $segments */
        $segments = [];

        foreach ($ops as $op) {
            $insert = $op['insert'];
            $attrs = $op['attributes'] ?? [];

            if (is_array($insert)) {
                $blotName = array_key_first($insert);
                if (!is_string($blotName) || $blotName === '') {
                    continue;
                }
                $value = $insert[$blotName];

                if ($this->extensions->hasEmbedToMarkdown($blotName)) {
                    $rendered = $this->extensions->renderEmbedToMarkdown($blotName, $value, $attrs);
                    if ($rendered !== '') {
                        $segments[] = [
                            'text' => $rendered,
                            'styles' => $this->extractStyles($attrs),
                            'raw' => true,
                        ];
                    }
                } elseif ($blotName === 'image' && is_string($value)) {
                    $alt = isset($attrs['alt']) && is_string($attrs['alt'])
                        ? $this->escapeBracketLabel($attrs['alt'])
                        : '';
                    $segments[] = [
                        'text' => '![' . $alt . '](' . $this->formatDestinationUrl($value) . ')',
                        'styles' => [],
                        'raw' => true,
                    ];
                } elseif ($blotName === 'video' && is_string($value) && $value !== '') {
                    $segments[] = [
                        'text' => '[Video](' . $this->formatDestinationUrl($value) . ')',
                        'styles' => [],
                        'raw' => true,
                    ];
                }
                continue;
            }

            if (isset($attrs['title']) && is_string($attrs['title']) && $attrs['title'] !== '') {
                $abbreviations[$insert] = $attrs['title'];
            }

            $styles = $this->extractStyles($attrs);

            if (!empty($attrs['code'])) {
                $segments[] = [
                    'text' => $this->formatCodeSpan($insert),
                    'styles' => $styles,
                    'raw' => true,
                ];
                continue;
            }

            $segments[] = [
                'text' => $insert,
                'styles' => $styles,
                'raw' => false,
            ];
        }

        $segCount = count($segments);
        for ($idx = 0; $idx < $segCount; $idx++) {
            if (!$segments[$idx]['raw'] && trim($segments[$idx]['text'], " \t") === '') {
                $prevStyles = $idx > 0 ? $segments[$idx - 1]['styles'] : [];
                $nextStyles = $idx + 1 < $segCount ? $segments[$idx + 1]['styles'] : [];
                $segments[$idx]['styles'] = array_values(array_filter(
                    $segments[$idx]['styles'],
                    static fn(array $style): bool => self::hasStyle($prevStyles, $style) && self::hasStyle($nextStyles, $style)
                ));
            }
        }

        return $this->renderSegments($segments, $inTableCell, $inLinkLabel);
    }

    /**
     * @param array<string, mixed> $attrs
     * @return list<array{type: string, value: mixed}>
     */
    private function extractStyles(array $attrs): array
    {
        $styles = [];

        if (!empty($attrs['bold'])) {
            $styles[] = ['type' => 'bold', 'value' => true];
        }
        if (!empty($attrs['italic'])) {
            $styles[] = ['type' => 'italic', 'value' => true];
        }
        if (!empty($attrs['strike'])) {
            $styles[] = ['type' => 'strike', 'value' => true];
        }
        if (!empty($attrs['background']) && is_string($attrs['background'])) {
            $styles[] = ['type' => 'background', 'value' => $attrs['background']];
        }
        if (isset($attrs['script']) && ($attrs['script'] === 'super' || $attrs['script'] === 'sub')) {
            $styles[] = ['type' => 'script', 'value' => $attrs['script']];
        }
        if ($this->options->preserveUnderlineHtml && !empty($attrs['underline'])) {
            $styles[] = ['type' => 'underline', 'value' => true];
        }

        foreach ($this->extensions->getInlineAttributeHandlers() as $attrName => $handler) {
            if (array_key_exists($attrName, $attrs) && $attrs[$attrName] !== null && $attrs[$attrName] !== false) {
                $styles[] = ['type' => 'custom:' . $attrName, 'value' => $attrs[$attrName]];
            }
        }

        return $styles;
    }

    /**
     * @param list<array{text: string, styles: list<array{type: string, value: mixed}>, raw: bool}> $segments
     */
    private function renderSegments(array $segments, bool $inTableCell, bool $inLinkLabel): string
    {
        $segments = $this->mergeAdjacentSegments($segments);
        if ($segments === []) {
            return '';
        }

        $first = $segments[0];
        if ($first['styles'] === []) {
            $head = $first['raw']
                ? $first['text']
                : $this->escapeInlineText($first['text'], $inTableCell, $inLinkLabel);
            return $head . $this->renderSegments(array_slice($segments, 1), $inTableCell, $inLinkLabel);
        }

        $chosenStyle = $first['styles'][0];
        $bestSpan = 0;

        foreach ($first['styles'] as $candidateStyle) {
            $span = 0;
            $segCount = count($segments);
            while ($span < $segCount && self::hasStyle($segments[$span]['styles'], $candidateStyle)) {
                $span++;
            }
            if ($span > $bestSpan) {
                $bestSpan = $span;
                $chosenStyle = $candidateStyle;
            }
        }

        $subSegments = [];
        for ($idx = 0; $idx < $bestSpan; $idx++) {
            $seg = $segments[$idx];
            $seg['styles'] = self::removeStyle($seg['styles'], $chosenStyle);
            $subSegments[] = $seg;
        }

        $inner = $this->renderSegments($subSegments, $inTableCell, $inLinkLabel);
        if ($inner !== '') {
            if (preg_match('/^([ \t]*)(.*?)([ \t]*)$/s', $inner, $m) === 1 && $m[2] !== '') {
                $renderedPrefix = $m[1] . $this->applyStyleWrapper($chosenStyle, $m[2]) . $m[3];
            } else {
                $renderedPrefix = $inner;
            }
        } else {
            $renderedPrefix = '';
        }

        return $renderedPrefix . $this->renderSegments(array_slice($segments, $bestSpan), $inTableCell, $inLinkLabel);
    }

    /**
     * @param list<array{text: string, styles: list<array{type: string, value: mixed}>, raw: bool}> $segments
     * @return list<array{text: string, styles: list<array{type: string, value: mixed}>, raw: bool}>
     */
    private function mergeAdjacentSegments(array $segments): array
    {
        $merged = [];
        foreach ($segments as $seg) {
            if ($seg['text'] === '') {
                continue;
            }
            if ($merged !== [] && !$seg['raw']) {
                $lastIdx = count($merged) - 1;
                if (!$merged[$lastIdx]['raw'] && $merged[$lastIdx]['styles'] === $seg['styles']) {
                    $merged[$lastIdx]['text'] .= $seg['text'];
                    continue;
                }
            }
            $merged[] = $seg;
        }
        return $merged;
    }

    /**
     * @param array{type: string, value: mixed} $style
     */
    private function applyStyleWrapper(array $style, string $content): string
    {
        return match ($style['type']) {
            'bold' => '**' . $content . '**',
            'italic' => '*' . $content . '*',
            'strike' => '~~' . $content . '~~',
            'background' => '==' . $content . '==',
            'underline' => '<u>' . $content . '</u>',
            'script' => $this->wrapScriptTokens(
                $content,
                $style['value'] === 'super' ? '^' : '~'
            ),
            default => $this->applyCustomAttributeWrapper($style['type'], $style['value'], $content),
        };
    }

    private function wrapScriptTokens(string $content, string $delimiter): string
    {
        if (preg_match('/\s/u', $content) !== 1) {
            return $delimiter . $content . $delimiter;
        }

        $tokens = preg_split('/(\s+)/u', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($tokens)) {
            return $delimiter . $content . $delimiter;
        }

        $result = '';
        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }
            if (preg_match('/^\s+$/u', $token) === 1) {
                $result .= $token;
            } else {
                $result .= $delimiter . $token . $delimiter;
            }
        }
        return $result;
    }

    private function applyCustomAttributeWrapper(string $styleType, mixed $value, string $content): string
    {
        $attrName = substr($styleType, 7);
        $handlers = $this->extensions->getInlineAttributeHandlers();
        if (isset($handlers[$attrName])) {
            return ($handlers[$attrName])($content, $value);
        }
        return $content;
    }

    /**
     * @param list<array{type: string, value: mixed}> $styles
     * @param array{type: string, value: mixed} $target
     */
    private static function hasStyle(array $styles, array $target): bool
    {
        foreach ($styles as $style) {
            if ($style === $target) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param list<array{type: string, value: mixed}> $styles
     * @param array{type: string, value: mixed} $target
     * @return list<array{type: string, value: mixed}>
     */
    private static function removeStyle(array $styles, array $target): array
    {
        return array_values(array_filter(
            $styles,
            static fn(array $style): bool => $style !== $target
        ));
    }

    private function formatCodeSpan(string $code): string
    {
        $maxRun = 0;
        if (preg_match_all('/`+/', $code, $matches) > 0) {
            foreach ($matches[0] as $run) {
                $maxRun = max($maxRun, strlen($run));
            }
        }

        $fence = str_repeat('`', $maxRun + 1);
        $needsPadding = $code !== '' && (
            $code[0] === '`'
            || str_ends_with($code, '`')
            || ($code[0] === ' ' && str_ends_with($code, ' ') && trim($code, ' ') !== '')
        );

        return $needsPadding
            ? $fence . ' ' . $code . ' ' . $fence
            : $fence . $code . $fence;
    }

    private function escapeInlineText(string $text, bool $inTableCell, bool $inLinkLabel): string
    {
        if ($text === '') {
            return '';
        }

        $escaped = str_replace(['\\', '`'], ['\\\\', '\\`'], $text);

        if ($inTableCell) {
            $escaped = str_replace('|', '\\|', $escaped);
        }

        // Escape * when adjacent to non-whitespace
        $escaped = $this->escapeCharWhenFlanking($escaped, '*', false);

        // Escape _ when non-intraword and adjacent to non-whitespace
        $escaped = $this->escapeCharWhenFlanking($escaped, '_', true);

        // Escape ~~ and == pairs
        $escaped = str_replace(['~~', '=='], ['\~\~', '\=\='], $escaped);

        // Escape ~ or ^ only inside non-whitespace tokens that contain 2+ occurrences
        $escaped = $this->escapePairedTokenDelimiters($escaped, '~');
        $escaped = $this->escapePairedTokenDelimiters($escaped, '^');

        if ($inLinkLabel) {
            $escaped = str_replace(['[', ']'], ['\\[', '\\]'], $escaped);
        } else {
            // Escape [ only when forming link/reference/footnote patterns
            $escaped = (string) preg_replace('/\[(?=[^\]]*\](?:\([^)]*\)|\[[^\]]*\]))/', '\[', $escaped);
            $escaped = (string) preg_replace('/\[(?=\^[^\s\]]+\])/', '\[', $escaped);
        }

        // Escape < only when forming autolinks or optional <u> tags
        $escaped = (string) preg_replace('/<(?=(?:https?:\/\/[^\s<>]+|[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,})>)/i', '\<', $escaped);
        if ($this->options->preserveUnderlineHtml) {
            $escaped = (string) preg_replace('/<(?=\/?u>)/i', '\<', $escaped);
        }

        return $escaped;
    }

    private function escapeCharWhenFlanking(string $text, string $target, bool $skipIntraword): string
    {
        $len = strlen($text);
        if (!str_contains($text, $target)) {
            return $text;
        }

        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $ch = $text[$i];
            if ($ch === '\\' && $i + 1 < $len) {
                $out .= $ch . $text[$i + 1];
                $i++;
                continue;
            }
            if ($ch !== $target) {
                $out .= $ch;
                continue;
            }

            $prev = $this->getPrevUtf8Char($text, $i);
            $next = $this->getNextUtf8Char($text, $i + 1);
            $prevSpace = preg_match('/^\s\z/u', $prev) === 1;
            $nextSpace = preg_match('/^\s\z/u', $next) === 1;

            if ($prevSpace && $nextSpace) {
                $out .= $ch;
                continue;
            }

            if (
                $skipIntraword
                && preg_match('/^[\p{L}\p{N}]\z/u', $prev) === 1
                && preg_match('/^[\p{L}\p{N}]\z/u', $next) === 1
            ) {
                $out .= $ch;
                continue;
            }

            $out .= '\\' . $ch;
        }

        return $out;
    }

    private function getPrevUtf8Char(string $text, int $bytePos): string
    {
        if ($bytePos <= 0) {
            return ' ';
        }
        $start = max(0, $bytePos - 4);
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

    private function escapePairedTokenDelimiters(string $text, string $delimiter): string
    {
        if (substr_count($text, $delimiter) < 2) {
            return $text;
        }

        $parts = preg_split('/(\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($parts)) {
            return $text;
        }

        $result = '';
        foreach ($parts as $part) {
            if ($part === '' || preg_match('/^\s+$/u', $part) === 1) {
                $result .= $part;
                continue;
            }

            // Count unescaped occurrences inside this non-whitespace token
            $unescapedCount = 0;
            $partLen = strlen($part);
            for ($i = 0; $i < $partLen; $i++) {
                if ($part[$i] === '\\' && $i + 1 < $partLen) {
                    $i++;
                    continue;
                }
                if ($part[$i] === $delimiter) {
                    $unescapedCount++;
                }
            }

            if ($unescapedCount >= 2) {
                $escapedPart = '';
                for ($i = 0; $i < $partLen; $i++) {
                    if ($part[$i] === '\\' && $i + 1 < $partLen) {
                        $escapedPart .= $part[$i] . $part[$i + 1];
                        $i++;
                        continue;
                    }
                    if ($part[$i] === $delimiter) {
                        $escapedPart .= '\\' . $delimiter;
                    } else {
                        $escapedPart .= $part[$i];
                    }
                }
                $result .= $escapedPart;
            } else {
                $result .= $part;
            }
        }

        return $result;
    }

    private function escapeBracketLabel(string $label): string
    {
        return str_replace(['\\', '[', ']'], ['\\\\', '\\[', '\\]'], $label);
    }

    private function formatDestinationUrl(string $url): string
    {
        if ($url === '') {
            return '<>';
        }

        if (preg_match('/[\s<>]/', $url) === 1 || !$this->hasBalancedParens($url)) {
            $safe = str_replace([' ', '<', '>'], ['%20', '%3C', '%3E'], $url);
            return $this->hasBalancedParens($safe) ? $safe : '<' . $safe . '>';
        }

        return $url;
    }

    private function hasBalancedParens(string $url): bool
    {
        $depth = 0;
        $len = strlen($url);
        for ($i = 0; $i < $len; $i++) {
            if ($url[$i] === '\\' && $i + 1 < $len) {
                $i++;
                continue;
            }
            if ($url[$i] === '(') {
                $depth++;
            } elseif ($url[$i] === ')') {
                $depth--;
                if ($depth < 0) {
                    return false;
                }
            }
        }
        return $depth === 0;
    }
}
