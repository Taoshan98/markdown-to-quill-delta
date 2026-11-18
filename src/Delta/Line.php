<?php

namespace Taoshan\QuillMarkdown\Delta;

/**
 * Represents a single logical line within a Quill Delta document.
 */
final class Line
{
    /** @var list<array{insert: string|array<string, mixed>, attributes?: array<string, mixed>}> */
    public readonly array $inlineOps;

    /** @var array<string, mixed> */
    public readonly array $attributes;

    /**
     * @param list<array{insert: string|array<string, mixed>, attributes?: array<string, mixed>}> $inlineOps
     * @param array<string, mixed> $attributes
     */
    public function __construct(array $inlineOps = [], array $attributes = [])
    {
        $this->inlineOps = $inlineOps;
        $this->attributes = $attributes;
    }

    public function isBlank(): bool
    {
        return $this->inlineOps === [] && $this->attributes === [];
    }

    public function toPlainText(): string
    {
        $text = '';
        foreach ($this->inlineOps as $op) {
            if (is_string($op['insert'])) {
                $text .= $op['insert'];
            }
        }
        return $text;
    }
}
