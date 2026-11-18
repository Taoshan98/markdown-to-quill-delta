<?php

namespace Taoshan\QuillMarkdown\Extension;

/**
 * Registry for custom Quill embeds, inline attributes, and Markdown inline extensions.
 */
final class ExtensionRegistry
{
    /** @var array<string, callable(mixed, array<string, mixed>): string> */
    private array $embedToMarkdown = [];

    /** @var array<string, callable(string, mixed): string> */
    private array $inlineAttrToMarkdown = [];

    /** @var list<array{pattern: string, handler: callable(array<int|string, string>): ?array{insert: string|array<string, mixed>, attributes?: array<string, mixed>}}> */
    private array $inlineMarkdownPatterns = [];

    /**
     * Registers a renderer converting a custom Quill embed blot to Markdown.
     *
     * @param callable(mixed, array<string, mixed>): string $handler
     */
    public function registerEmbedToMarkdown(string $blotName, callable $handler): self
    {
        if ($blotName === '') {
            throw new \InvalidArgumentException('Blot name cannot be empty.');
        }

        $this->embedToMarkdown[$blotName] = $handler;
        return $this;
    }

    public function hasEmbedToMarkdown(string $blotName): bool
    {
        return isset($this->embedToMarkdown[$blotName]);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function renderEmbedToMarkdown(string $blotName, mixed $value, array $attributes = []): string
    {
        if (!isset($this->embedToMarkdown[$blotName])) {
            return '';
        }

        return ($this->embedToMarkdown[$blotName])($value, $attributes);
    }

    /**
     * Registers a formatter converting a custom Quill inline attribute to Markdown.
     *
     * @param callable(string, mixed): string $handler
     */
    public function registerInlineAttributeToMarkdown(string $attributeName, callable $handler): self
    {
        if ($attributeName === '') {
            throw new \InvalidArgumentException('Attribute name cannot be empty.');
        }

        $this->inlineAttrToMarkdown[$attributeName] = $handler;
        return $this;
    }

    /**
     * @return array<string, callable(string, mixed): string>
     */
    public function getInlineAttributeHandlers(): array
    {
        return $this->inlineAttrToMarkdown;
    }

    /**
     * Registers a custom PCRE regex pattern for inline Markdown-to-Delta parsing.
     *
     * @param callable(array<int|string, string>): ?array{insert: string|array<string, mixed>, attributes?: array<string, mixed>} $handler
     */
    public function registerInlineMarkdownPattern(string $pattern, callable $handler): self
    {
        if ($pattern === '') {
            throw new \InvalidArgumentException('Pattern cannot be empty.');
        }

        $this->inlineMarkdownPatterns[] = [
            'pattern' => $pattern,
            'handler' => $handler,
        ];
        return $this;
    }

    /**
     * @return list<array{pattern: string, handler: callable(array<int|string, string>): ?array{insert: string|array<string, mixed>, attributes?: array<string, mixed>}}>
     */
    public function getInlineMarkdownPatterns(): array
    {
        return $this->inlineMarkdownPatterns;
    }
}
