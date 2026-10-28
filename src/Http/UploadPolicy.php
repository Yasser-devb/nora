<?php

declare(strict_types=1);

namespace Nora\Http;

use InvalidArgumentException;

final class UploadPolicy
{
    /**
     * @param array<string, list<string>> $allowedExtensionsAndMimeTypes
     */
    public function __construct(
        private readonly int $maximumFileBytes,
        private readonly int $maximumRequestBytes,
        private readonly array $allowedExtensionsAndMimeTypes,
    ) {
        if (
            $maximumFileBytes < 1
            || $maximumRequestBytes < 1
            || $maximumFileBytes > $maximumRequestBytes
            || $maximumFileBytes > 1_073_741_824
            || $maximumRequestBytes > 4_294_967_296
        ) {
            throw new InvalidArgumentException('Upload size limits are invalid.');
        }
        if ($allowedExtensionsAndMimeTypes === []) {
            throw new InvalidArgumentException('At least one allowed upload extension is required.');
        }

        foreach ($allowedExtensionsAndMimeTypes as $extension => $mimeTypes) {
            if (preg_match('/\A[a-z0-9]{1,16}\z/D', $extension) !== 1 || $mimeTypes === []) {
                throw new InvalidArgumentException('The upload allowlist is invalid.');
            }
            foreach ($mimeTypes as $mimeType) {
                if (preg_match('~\A[a-z0-9.+-]+/[a-z0-9.+-]+\z~D', $mimeType) !== 1) {
                    throw new InvalidArgumentException('The upload MIME allowlist is invalid.');
                }
            }
        }
    }

    public function validate(UploadedFile $file): ValidatedUpload
    {
        $this->assertFileWithinLimit($file);
        return $this->validateOne($file);
    }

    private function validateOne(UploadedFile $file): ValidatedUpload
    {
        $extension = $file->extension();
        $mimeType = $file->detectMimeType();
        $allowedTypes = $this->allowedExtensionsAndMimeTypes[$extension] ?? null;
        if ($allowedTypes === null || !in_array($mimeType, $allowedTypes, true)) {
            throw new UploadException('The uploaded file type is not allowed.');
        }

        return new ValidatedUpload($file, $mimeType);
    }

    /**
     * @param non-empty-list<UploadedFile> $files
     * @return list<ValidatedUpload>
     */
    public function validateBatch(array $files): array
    {
        if ($files === []) {
            throw new UploadException('At least one file is required.');
        }

        $totalBytes = 0;
        foreach ($files as $file) {
            if (!$file instanceof UploadedFile) {
                throw new UploadException('The uploaded file collection is invalid.');
            }
            $this->assertFileWithinLimit($file);
            if ($file->size() > $this->maximumRequestBytes - $totalBytes) {
                throw new UploadException('The uploaded files exceed the request size limit.', 413);
            }
            $totalBytes += $file->size();
        }

        return array_map($this->validateOne(...), $files);
    }

    private function assertFileWithinLimit(UploadedFile $file): void
    {
        if ($file->size() > $this->maximumFileBytes) {
            throw new UploadException('The uploaded file exceeds the per-file size limit.', 413);
        }
    }
}
