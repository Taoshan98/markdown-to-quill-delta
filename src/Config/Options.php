<?php

namespace Taoshan\QuillMarkdown\Config;

/**
 * Immutable configuration options for bidirectional Markdown <-> Quill Delta conversion.
 */
final class Options
{
    public readonly BulletMarker $bulletMarker;
    public readonly CodeFenceStyle $codeFenceStyle;
    public readonly int $listIndentSpaces;
    public readonly bool $enableDividerBlot;
    public readonly bool $preserveUnderlineHtml;
    public readonly string $highlightColor;
    public readonly bool $compactDelta;

    public function __construct(
        BulletMarker $bulletMarker = BulletMarker::Dash,
        CodeFenceStyle $codeFenceStyle = CodeFenceStyle::Backtick,
        int $listIndentSpaces = 2,
        bool $enableDividerBlot = false,
        bool $preserveUnderlineHtml = false,
        string $highlightColor = '#ffffb8',
        bool $compactDelta = false,
    ) {
        if ($listIndentSpaces < 2) {
            throw new \InvalidArgumentException('listIndentSpaces must be at least 2.');
        }

        $this->bulletMarker = $bulletMarker;
        $this->codeFenceStyle = $codeFenceStyle;
        $this->listIndentSpaces = $listIndentSpaces;
        $this->enableDividerBlot = $enableDividerBlot;
        $this->preserveUnderlineHtml = $preserveUnderlineHtml;
        $this->highlightColor = $highlightColor;
        $this->compactDelta = $compactDelta;
    }
}
