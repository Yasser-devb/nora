<?php

declare(strict_types=1);

namespace Nora\Storage;

final readonly class StoredFile
{
    public function __construct(
        private string $identifier,
        private string $mimeType,
        private int $size,
    ) {
    }

    public function identifier(): string
    {
        return $this->identifier;
    }

    public function mimeType(): string
    {
        return $this->mimeType;
    }

    public function size(): int
    {
        return $this->size;
    }
}
