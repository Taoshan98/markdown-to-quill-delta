<?php

namespace MarkdownToQuill;

use MarkdownToQuill\Delta\Delta;
use MarkdownToQuill\Parser\Parser;

/**
 * Main converter class.
 */
class Converter
{
    private Parser $parser;

    public function __construct()
    {
        $this->parser = new Parser();
    }

    /**
     * Converts Markdown to Quill Delta operations.
     *
     * @param string $markdown
     * @return array<array<string, mixed>>
     */
    public function convertToArray(string $markdown): array
    {
        return $this->parser->parse($markdown)->getOps();
    }

    /**
     * Converts Markdown to a Delta object.
     *
     * @param string $markdown
     * @return Delta
     */
    public function convertToDelta(string $markdown): Delta
    {
        return $this->parser->parse($markdown);
    }

    /**
     * Converts Markdown to Delta JSON.
     *
     * @param string $markdown
     * @return string
     */
    public function convertToJson(string $markdown): string
    {
        return json_encode($this->parser->parse($markdown)->getOps());
    }
}
