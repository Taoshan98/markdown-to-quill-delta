<?php

namespace Taoshan\QuillMarkdown\Config;

/**
 * Fenced code block delimiter character used when rendering Markdown.
 */
enum CodeFenceStyle: string
{
    case Backtick = '`';
    case Tilde = '~';
}
