<?php

declare(strict_types=1);

namespace Nora\Http;

final readonly class ValidatedUpload
{
    public function __construct(
        private UploadedFile $file,
        private string $mimeType,
    ) {
    }

    public function size(): int
    {
        return $this->file->size();
    }

    public function mimeType(): string
    {
        return $this->mimeType;
    }

    /**
     * @param resource $destination
     */
    public function copyTo(mixed $destination): void
    {
        $this->file->copyTo($destination);
    }
}
