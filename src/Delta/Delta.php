<?php

namespace Taoshan\QuillMarkdown\Delta;

/**
 * Represents a Quill Delta document.
 */
final class Delta implements \JsonSerializable
{
    private const BLOCK_ATTRIBUTES = [
        'header',
        'list',
        'indent',
        'blockquote',
        'code-block',
        'table',
        'align',
        'direction',
    ];

    /** @var list<array{insert: string|array<string, mixed>, attributes?: array<string, mixed>}> */
    private array $ops = [];

    /**
     * Creates a Delta from a Delta instance, decoded array, or JSON string.
     *
     * @param self|array<mixed>|string $input
     * @throws \JsonException
     */
    public static function from(self|array|string $input): self
    {
        if ($input instanceof self) {
            return $input;
        }

        if (is_string($input)) {
            return self::fromJson($input);
        }

        return self::fromArray($input);
    }

    /**
     * Parses a Quill Delta JSON string (`{"ops":[...]}` or `[...]`).
     *
     * @throws \JsonException
     */
    public static function fromJson(string $json): self
    {
        if (trim($json) === '') {
            return new self();
        }

        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \InvalidArgumentException('Delta JSON must decode to an object or array.');
        }

        return self::fromArray($decoded);
    }

    /**
     * Builds a Delta from a decoded `{"ops":[...]}` structure or a raw list of operations.
     *
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $delta = new self();
        if ($data === []) {
            return $delta;
        }

        if (array_key_exists('ops', $data)) {
            if (!is_array($data['ops'])) {
                throw new \InvalidArgumentException('Delta "ops" field must be an array.');
            }
            $rawOps = $data['ops'];
        } elseif (array_is_list($data)) {
            $rawOps = $data;
        } else {
            throw new \InvalidArgumentException('Delta array must be a list of operations or contain an "ops" key.');
        }

        foreach ($rawOps as $index => $op) {
            if (!is_array($op) || !array_key_exists('insert', $op)) {
                throw new \InvalidArgumentException(sprintf('Operation at index %s must be an array with an "insert" key.', (string) $index));
            }

            $insert = $op['insert'];
            if (!is_string($insert) && !is_array($insert)) {
                throw new \InvalidArgumentException(sprintf('Operation "insert" at index %s must be a string or an array.', (string) $index));
            }

            $attributes = $op['attributes'] ?? [];
            if (!is_array($attributes)) {
                throw new \InvalidArgumentException(sprintf('Operation "attributes" at index %s must be an array.', (string) $index));
            }

            $delta->insert($insert, $attributes);
        }

        return $delta;
    }

    /**
     * Appends an insert operation to the Delta.
     *
     * @param string|array<string, mixed> $insert The content or embed object to insert.
     * @param array<string, mixed> $attributes Formatting attributes.
     */
    public function insert(string|array $insert, array $attributes = []): self
    {
        if ($insert === '') {
            return $this;
        }

        $op = ['insert' => $insert];
        if ($attributes !== []) {
            $op['attributes'] = $attributes;
        }

        $this->ops[] = $op;
        return $this;
    }

    /**
     * Returns the raw list of Delta operations.
     *
     * @return list<array{insert: string|array<string, mixed>, attributes?: array<string, mixed>}>
     */
    public function getOps(): array
    {
        return $this->ops;
    }

    public function isEmpty(): bool
    {
        return $this->ops === [];
    }

    /**
     * Returns a new compacted Delta where adjacent text inserts with identical attributes are merged.
     */
    public function compact(): self
    {
        $compacted = new self();
        $buffer = [];

        foreach ($this->ops as $op) {
            $insert = $op['insert'];
            $attributes = $op['attributes'] ?? [];
            if ($attributes !== []) {
                ksort($attributes);
            }

            if (is_string($insert) && $buffer !== []) {
                $lastIdx = count($buffer) - 1;
                $lastInsert = $buffer[$lastIdx]['insert'];
                $lastAttributes = $buffer[$lastIdx]['attributes'] ?? [];
                if (is_string($lastInsert) && $lastAttributes === $attributes) {
                    $buffer[$lastIdx]['insert'] .= $insert;
                    continue;
                }
            }

            $normalizedOp = ['insert' => $insert];
            if ($attributes !== []) {
                $normalizedOp['attributes'] = $attributes;
            }
            $buffer[] = $normalizedOp;
        }

        $compacted->ops = $buffer;
        return $compacted;
    }

    /**
     * Extracts plain text from all string insert operations.
     */
    public function toPlainText(): string
    {
        $text = '';
        foreach ($this->ops as $op) {
            if (is_string($op['insert'])) {
                $text .= $op['insert'];
            }
        }
        return $text;
    }

    /**
     * Splits compacted or uncompacted Delta operations into logical lines with block attributes.
     *
     * @return list<Line>
     */
    public function splitLines(): array
    {
        $lines = [];
        /** @var list<array{insert: string|array<string, mixed>, attributes?: array<string, mixed>}> $currentInlineOps */
        $currentInlineOps = [];

        foreach ($this->ops as $op) {
            $insert = $op['insert'];
            $attributes = $op['attributes'] ?? [];

            if (is_array($insert)) {
                if ($insert === ['divider' => true]) {
                    if ($currentInlineOps !== []) {
                        $lines[] = new Line($currentInlineOps, []);
                        $currentInlineOps = [];
                    }
                    $lines[] = new Line([['insert' => ['divider' => true]]], ['divider' => true]);
                } else {
                    $embedOp = ['insert' => $insert];
                    if ($attributes !== []) {
                        $embedOp['attributes'] = $attributes;
                    }
                    $currentInlineOps[] = $embedOp;
                }
                continue;
            }

            if ($insert === "\n") {
                $lines[] = new Line($currentInlineOps, $attributes);
                $currentInlineOps = [];
                continue;
            }

            if (!str_contains($insert, "\n")) {
                $this->appendInlineOp($currentInlineOps, $insert, $attributes);
                continue;
            }

            $blockAttrs = [];
            $inlineAttrs = [];
            foreach ($attributes as $key => $value) {
                if (in_array($key, self::BLOCK_ATTRIBUTES, true)) {
                    $blockAttrs[$key] = $value;
                } else {
                    $inlineAttrs[$key] = $value;
                }
            }

            $parts = explode("\n", $insert);
            $lastIndex = count($parts) - 1;

            for ($idx = 0; $idx < $lastIndex; $idx++) {
                if ($parts[$idx] !== '') {
                    $this->appendInlineOp($currentInlineOps, $parts[$idx], $inlineAttrs);
                }
                $lines[] = new Line($currentInlineOps, $blockAttrs);
                $currentInlineOps = [];
            }

            if ($parts[$lastIndex] !== '') {
                $this->appendInlineOp($currentInlineOps, $parts[$lastIndex], $inlineAttrs);
            }
        }

        if ($currentInlineOps !== []) {
            $lines[] = new Line($currentInlineOps, []);
        }

        return $lines;
    }

    /**
     * Converts the Delta to a canonical Quill Delta associative array (`['ops' => [...]]`).
     *
     * @return array{ops: list<array{insert: string|array<string, mixed>, attributes?: array<string, mixed>}>}
     */
    public function toArray(): array
    {
        return ['ops' => $this->ops];
    }

    /**
     * Serializes the Delta to a canonical Quill Delta JSON string (`{"ops":[...]}`).
     *
     * @throws \JsonException
     */
    public function toJson(int $flags = 0): string
    {
        return json_encode($this->toArray(), $flags | JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array{ops: list<array{insert: string|array<string, mixed>, attributes?: array<string, mixed>}>}
     */
    #[\ReturnTypeWillChange]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * @param list<array{insert: string|array<string, mixed>, attributes?: array<string, mixed>}> $inlineOps
     * @param array<string, mixed> $attributes
     */
    private function appendInlineOp(array &$inlineOps, string $text, array $attributes): void
    {
        if ($text === '') {
            return;
        }

        $normalized = $attributes;
        if ($normalized !== []) {
            ksort($normalized);
        }

        if ($inlineOps !== []) {
            $lastIdx = count($inlineOps) - 1;
            $lastInsert = $inlineOps[$lastIdx]['insert'];
            $lastAttrs = $inlineOps[$lastIdx]['attributes'] ?? [];
            if ($lastAttrs !== []) {
                ksort($lastAttrs);
            }
            if (is_string($lastInsert) && $lastAttrs === $normalized) {
                $inlineOps[$lastIdx]['insert'] = $lastInsert . $text;
                return;
            }
        }

        $op = ['insert' => $text];
        if ($attributes !== []) {
            $op['attributes'] = $attributes;
        }
        $inlineOps[] = $op;
    }
}
