<?php

namespace MarkdownToQuill\Delta;

/**
 * Represents a Quill Delta object.
 */
class Delta
{
    /** @var array<array<string, mixed>> */
    private array $ops = [];

    /**
     * Adds an operation to the Delta.
     *
     * @param string|array $insert The content to insert.
     * @param array<string, mixed> $attributes Formatting attributes.
     * @return self
     */
    public function insert($insert, array $attributes = []): self
    {
        $op = ['insert' => $insert];
        if (!empty($attributes)) {
            $op['attributes'] = $attributes;
        }
        $this->ops[] = $op;
        return $this;
    }

    /**
     * Returns the Delta operations as an array.
     *
     * @return array<array<string, mixed>>
     */
    public function getOps(): array
    {
        return $this->ops;
    }

    /**
     * Converts the Delta to a JSON-ready array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['ops' => $this->ops];
    }
}
