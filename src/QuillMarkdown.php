<?php

namespace Taoshan\QuillMarkdown;

use Taoshan\QuillMarkdown\Config\Options;
use Taoshan\QuillMarkdown\Delta\Delta;
use Taoshan\QuillMarkdown\DeltaToMarkdown\DeltaRenderer;
use Taoshan\QuillMarkdown\Extension\ExtensionRegistry;
use Taoshan\QuillMarkdown\MarkdownToDelta\MarkdownParser;

/**
 * Main bidirectional facade converting between Markdown and Quill Delta.
 */
final class QuillMarkdown
{
    private readonly Options $options;
    private readonly ExtensionRegistry $extensions;
    private readonly MarkdownParser $markdownParser;
    private readonly DeltaRenderer $deltaRenderer;

    public function __construct(
        ?Options $options = null,
        ?ExtensionRegistry $extensions = null,
    ) {
        $this->options = $options ?? new Options();
        $this->extensions = $extensions ?? new ExtensionRegistry();
        $this->markdownParser = new MarkdownParser($this->options, $this->extensions);
        $this->deltaRenderer = new DeltaRenderer($this->options, $this->extensions);
    }

    /**
     * Converts Markdown text into a Delta value object.
     */
    public function toDelta(string $markdown): Delta
    {
        return $this->markdownParser->parse($markdown);
    }

    /**
     * Converts Markdown text into a raw list of Quill Delta operations.
     *
     * @return list<array{insert: string|array<string, mixed>, attributes?: array<string, mixed>}>
     */
    public function toDeltaOps(string $markdown): array
    {
        return $this->markdownParser->parse($markdown)->getOps();
    }

    /**
     * Converts Markdown text into canonical Quill Delta JSON (`{"ops":[...]}`).
     *
     * @throws \JsonException
     */
    public function toDeltaJson(string $markdown, int $jsonFlags = 0): string
    {
        return $this->markdownParser->parse($markdown)->toJson($jsonFlags);
    }

    /**
     * Converts a Quill Delta object, decoded array, or JSON string into Markdown.
     *
     * @param Delta|array<mixed>|string $delta
     * @throws \JsonException
     */
    public function toMarkdown(Delta|array|string $delta): string
    {
        return $this->deltaRenderer->render($delta);
    }

    /**
     * Quick static helper: Markdown -> Delta.
     */
    public static function markdownToDelta(string $markdown, ?Options $options = null): Delta
    {
        return (new self($options))->toDelta($markdown);
    }

    /**
     * Quick static helper: Delta -> Markdown.
     *
     * @param Delta|array<mixed>|string $delta
     * @throws \JsonException
     */
    public static function deltaToMarkdown(Delta|array|string $delta, ?Options $options = null): string
    {
        return (new self($options))->toMarkdown($delta);
    }

    public function getOptions(): Options
    {
        return $this->options;
    }

    public function getExtensions(): ExtensionRegistry
    {
        return $this->extensions;
    }

    /**
     * Registers a custom Quill embed blot handler (and optional Markdown regex parser).
     *
     * @param callable(mixed, array<string, mixed>): string $toMarkdown
     * @param (callable(array<int|string, string>): ?array{insert: string|array<string, mixed>, attributes?: array<string, mixed>})|null $fromMarkdown
     */
    public function registerEmbed(
        string $blotName,
        callable $toMarkdown,
        ?string $fromMarkdownPattern = null,
        ?callable $fromMarkdown = null,
    ): self {
        $this->extensions->registerEmbedToMarkdown($blotName, $toMarkdown);

        if ($fromMarkdownPattern !== null && $fromMarkdown !== null) {
            $this->extensions->registerInlineMarkdownPattern($fromMarkdownPattern, $fromMarkdown);
        }

        return $this;
    }

    /**
     * Registers a custom Quill inline attribute renderer for Delta-to-Markdown conversion.
     *
     * @param callable(string, mixed): string $toMarkdown
     */
    public function registerInlineAttribute(string $attributeName, callable $toMarkdown): self
    {
        $this->extensions->registerInlineAttributeToMarkdown($attributeName, $toMarkdown);
        return $this;
    }
}
