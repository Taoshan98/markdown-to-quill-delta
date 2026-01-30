<?php

namespace MarkdownToQuill\Parser;

/**
 * Parses inline Markdown formatting into Quill Delta operations.
 */
class InlineParser
{
    /**
     * Parses a text string and returns an array of operations.
     *
     * @param string $text The text to parse.
     * @return array<array<string, mixed>>
     */
    private array $references = [];
    private array $footnotes = [];
    private array $abbreviations = [];
    private ?string $abbrRegex = null;

    public function setReferences(array $references): void
    {
        $this->references = $references;
    }

    public function setFootnotes(array $footnotes): void
    {
        $this->footnotes = $footnotes;
    }

    public function setAbbreviations(array $abbreviations): void
    {
        $this->abbreviations = $abbreviations;
        if (!empty($abbreviations)) {
            // Sort by length desc to match longest first
            $keys = array_keys($abbreviations);
            usort($keys, fn($a, $b) => strlen($b) <=> strlen($a));
            $this->abbrRegex = '/\b(' . implode('|', array_map('preg_quote', $keys)) . ')\b/';
        }
    }

    /**
     * Parses a text string and returns an array of operations.
     */
    public function parse(string $text): array
    {
        if (empty($text)) {
            return [];
        }

        return $this->processInlineFormatting($text);
    }

    /**
     * Internal recursive processor for inline formatting.
     */
    private function processInlineFormatting(string $text): array
    {
        $ops = [];
        $i = 0;
        $len = strlen($text);

        while ($i < $len) {
            $nextSpecial = $this->findNextSpecialChar($text, $i);
            
            // Check for Abbreviation Match if regex exists
            $abbrMatch = null;
            $abbrPos = $len + 1;
            
            if ($this->abbrRegex) {
                if (preg_match($this->abbrRegex, $text, $matches, PREG_OFFSET_CAPTURE, $i)) {
                    $abbrPos = $matches[0][1];
                    $abbrMatch = $matches[0][0];
                }
            }

            // Decide which comes first: special char or abbreviation
            if ($abbrPos < $nextSpecial) {
                 // Insert text before abbreviation
                 if ($abbrPos > $i) {
                     $ops[] = ['insert' => substr($text, $i, $abbrPos - $i)];
                 }
                 
                 // Insert Abbreviation
                 $definition = $this->abbreviations[$abbrMatch] ?? '';
                 $ops[] = ['insert' => $abbrMatch, 'attributes' => ['title' => $definition]];
                 
                 $i = $abbrPos + strlen($abbrMatch);
                 continue;
            }

            if ($nextSpecial > $i) {
                $ops[] = ['insert' => substr($text, $i, $nextSpecial - $i)];
                $i = $nextSpecial;
            }

            if ($i >= $len) {
                break;
            }

            $matched = false;

            // Footnotes: [^id]
            if (substr($text, $i, 2) === '[^' && ($endBracket = strpos($text, ']', $i + 2)) !== false) {
                $fnId = substr($text, $i + 2, $endBracket - ($i + 2));
                if (isset($this->footnotes[$fnId])) {
                     // Insert footnote reference
                     $ops[] = [
                         'insert' => $fnId, 
                         'attributes' => [
                             'script' => 'super',
                             'link' => '#fn-' . $fnId
                         ]
                     ];
                     $i = $endBracket + 1;
                     $matched = true;
                }
            }

            // Image: ![alt](url)
            if (!$matched && substr($text, $i, 2) === '![' && ($endBracket = strpos($text, ']', $i + 2)) !== false) {
                if (substr($text, $endBracket + 1, 1) === '(' && ($endParen = strpos($text, ')', $endBracket + 2)) !== false) {
                    $url = substr($text, $endBracket + 2, $endParen - ($endBracket + 2));
                    $ops[] = ['insert' => ['image' => $url]];
                    $i = $endParen + 1;
                    $matched = true;
                }
            }

            // Link: [text](url) OR [text][id] OR [text][]
            if (!$matched && substr($text, $i, 1) === '[') {
                // Find matching closing bracket with nesting support
                $level = 0;
                $endBracket = false;
                for ($k = $i; $k < strlen($text); $k++) {
                     // Skip escaped chars? (Not implemented generally yet, but good practice if needed)
                     // Simple counting for now
                     if ($text[$k] === '[') $level++;
                     elseif ($text[$k] === ']') {
                         $level--;
                         if ($level === 0) {
                             $endBracket = $k;
                             break;
                         }
                     }
                }

                if ($endBracket !== false) {
                    // Check for standard link: [text](url)
                    if (substr($text, $endBracket + 1, 1) === '(' && ($endParen = strpos($text, ')', $endBracket + 2)) !== false) {
                        $linkText = substr($text, $i + 1, $endBracket - ($i + 1));
                        $url = substr($text, $endBracket + 2, $endParen - ($endBracket + 2));
                        $innerOps = $this->processInlineFormatting($linkText);
                        foreach ($innerOps as $op) {
                            $op['attributes'] = array_merge($op['attributes'] ?? [], ['link' => $url]);
                            $ops[] = $op;
                        }
                        $i = $endParen + 1;
                        $matched = true;
                    }
                    // Check for reference link: [text][id] or [text][]
                    elseif (substr($text, $endBracket + 1, 1) === '[' && ($endRefBracket = strpos($text, ']', $endBracket + 2)) !== false) {
                         $linkText = substr($text, $i + 1, $endBracket - ($i + 1));
                         $refId = substr($text, $endBracket + 2, $endRefBracket - ($endBracket + 2));
                         
                         if (empty($refId)) {
                             $refId = $linkText; // Implicit [text][] uses text as id
                         }
                         
                         $lowerRefId = strtolower($refId);
                         
                         if (isset($this->references[$lowerRefId])) {
                             $url = $this->references[$lowerRefId];
                             $innerOps = $this->processInlineFormatting($linkText);
                             foreach ($innerOps as $op) {
                                 $op['attributes'] = array_merge($op['attributes'] ?? [], ['link' => $url]);
                                 $ops[] = $op;
                             }
                             $i = $endRefBracket + 1;
                             $matched = true;
                         } 
                    }
                }
            }

            // Bold: **text** or __text__
            if (!$matched && (substr($text, $i, 2) === '**' || substr($text, $i, 2) === '__')) {
                $marker = substr($text, $i, 2);
                if (($endPos = strpos($text, $marker, $i + 2)) !== false) {
                    // Handle greedy closing (***) -> Bold should capture outer stars, leaving inner for Italic
                    // If text at endPos is '***', we want to consume the LAST 2 stars.
                    // Meaning we shift endPos by 1.
                    if (substr($text, $endPos, 3) === $marker . substr($marker, 0, 1)) {
                        $endPos++;
                    }
                    
                    $innerContent = substr($text, $i + 2, $endPos - ($i + 2));
                    $innerOps = $this->processInlineFormatting($innerContent);
                    foreach ($innerOps as $op) {
                        $op['attributes'] = array_merge($op['attributes'] ?? [], ['bold' => true]);
                        $ops[] = $op;
                    }
                    $i = $endPos + 2;
                    $matched = true;
                }
            }

            // Italic: *text* or _text_
            if (!$matched && (substr($text, $i, 1) === '*' || substr($text, $i, 1) === '_')) {
                $marker = substr($text, $i, 1);
                if (($endPos = strpos($text, $marker, $i + 1)) !== false) {
                    $innerContent = substr($text, $i + 1, $endPos - ($i + 1));
                    $innerOps = $this->processInlineFormatting($innerContent);
                    foreach ($innerOps as $op) {
                        $op['attributes'] = array_merge($op['attributes'] ?? [], ['italic' => true]);
                        $ops[] = $op;
                    }
                    $i = $endPos + 1;
                    $matched = true;
                }
            }

            // Strikethrough: ~~text~~
            if (!$matched && substr($text, $i, 2) === '~~') {
                if (($endPos = strpos($text, '~~', $i + 2)) !== false) {
                    $innerContent = substr($text, $i + 2, $endPos - ($i + 2));
                    $innerOps = $this->processInlineFormatting($innerContent);
                    foreach ($innerOps as $op) {
                        $op['attributes'] = array_merge($op['attributes'] ?? [], ['strike' => true]);
                        $ops[] = $op;
                    }
                    $i = $endPos + 2;
                    $matched = true;
                }
            }

            // Inline Code: `text`
            if (!$matched && substr($text, $i, 1) === '`') {
                if (($endPos = strpos($text, '`', $i + 1)) !== false) {
                    $code = substr($text, $i + 1, $endPos - ($i + 1));
                    $ops[] = ['insert' => $code, 'attributes' => ['code' => true]];
                    $i = $endPos + 1;
                    $matched = true;
                }
            }

            // Highlight: ==text==
            if (!$matched && substr($text, $i, 2) === '==') {
                if (($endPos = strpos($text, '==', $i + 2)) !== false) {
                    $innerContent = substr($text, $i + 2, $endPos - ($i + 2));
                    $innerOps = $this->processInlineFormatting($innerContent);
                    foreach ($innerOps as $op) {
                        $op['attributes'] = array_merge($op['attributes'] ?? [], ['background' => '#ffffb8']); // Standard yellow highlight
                        $ops[] = $op;
                    }
                    $i = $endPos + 2;
                    $matched = true;
                }
            }

            // Superscript: ^text^
            if (!$matched && substr($text, $i, 1) === '^') {
                if (($endPos = strpos($text, '^', $i + 1)) !== false) {
                    $innerContent = substr($text, $i + 1, $endPos - ($i + 1));
                    $innerOps = $this->processInlineFormatting($innerContent);
                    foreach ($innerOps as $op) {
                        $op['attributes'] = array_merge($op['attributes'] ?? [], ['script' => 'super']);
                        $ops[] = $op;
                    }
                    $i = $endPos + 1;
                    $matched = true;
                }
            }

            // Subscript: ~text~ (Careful with ~~strike~~)
            // Note: Strikethrough is handled BEFORE this if it uses ~~.
            // If we are here, it's either ~text~ or ~text which isn't strike.
            // Wait, strike check is `substr($text, $i, 2) === '~~'`.
            // If text is `~~strike~~`, the strike block catches it.
            // If text is `~sub~`, strike block `substr` '~~' is false.
            if (!$matched && substr($text, $i, 1) === '~') {
                if (($endPos = strpos($text, '~', $i + 1)) !== false) {
                    $innerContent = substr($text, $i + 1, $endPos - ($i + 1));
                    $innerOps = $this->processInlineFormatting($innerContent);
                    foreach ($innerOps as $op) {
                        $op['attributes'] = array_merge($op['attributes'] ?? [], ['script' => 'sub']);
                        $ops[] = $op;
                    }
                    $i = $endPos + 1;
                    $matched = true;
                }
            }

            // Emoji: :smile:
            if (!$matched && substr($text, $i, 1) === ':') {
                 if (preg_match('/^:([a-z0-9_+\-]+):/', substr($text, $i), $matches)) {
                     $shortcode = $matches[1];
                     $emoji = $this->getEmoji($shortcode);
                     if ($emoji) {
                         $ops[] = ['insert' => $emoji];
                         $i += strlen($matches[0]);
                         $matched = true;
                     }
                 }
            }

            if (!$matched) {
                $ops[] = ['insert' => substr($text, $i, 1)];
                $i++;
            }
        }

        return $ops;
    }

    /**
     * Finds the next special character position.
     */
    private function findNextSpecialChar(string $text, int $start): int
    {
        $specialChars = ['*', '_', '`', '~', '[', '!', '=', '^', ':'];
        $minPos = strlen($text);

        foreach ($specialChars as $char) {
            $pos = strpos($text, $char, $start);
            if ($pos !== false && $pos < $minPos) {
                $minPos = $pos;
            }
        }

        return $minPos;
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
            'cloud' => '☁️'
        ];
        return $map[$shortcode] ?? null;
    }
}
