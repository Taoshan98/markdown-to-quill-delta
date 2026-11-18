<?php

namespace Taoshan\QuillMarkdown\Config;

/**
 * Bullet list marker style used when rendering Markdown.
 */
enum BulletMarker: string
{
    case Dash = '-';
    case Asterisk = '*';
    case Plus = '+';
}
