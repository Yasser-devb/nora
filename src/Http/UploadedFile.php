<?php

declare(strict_types=1);

namespace Nora\Http;

use InvalidArgumentException;

final readonly class UploadedFile
{
    private function __construct(
        private string $temporaryPath,
        private string $originalName,
        private int $reportedSize,
        private int $sourceDevice,
        private int $sourceInode,
    ) {
    }

    /**
     * Create an upload from one entry in PHP's $_FILES array.
     *
     * @param array<string, mixed> $file
     */
    public static function fromPhpArray(array $file): self
    {
        $name = $file['name'] ?? null;
        $temporaryPath = $file['tmp_name'] ?? null;
        $error = $file['error'] ?? null;
        $size = $file['size'] ?? null;

        if (!is_int($error)) {
            throw new UploadException('The uploaded file metadata is invalid.');
        }
        if ($error !== UPLOAD_ERR_OK) {
            $status = in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 413 : 400;
            throw new UploadException('The upload did not complete successfully.', $status);
        }
        if (
            !is_string($name)
            || $name === ''
            || strlen($name) > 255
            || preg_match('/[\x00-\x1F\x7F]/', $name) === 1
        ) {
            throw new UploadException('The uploaded filename is invalid.');
        }
        if (!is_string($temporaryPath) || $temporaryPath === '' || !is_int($size)) {
            throw new UploadException('The uploaded file metadata is invalid.');
        }
        if (
            $size < 0
            || is_link($temporaryPath)
            || !is_file($temporaryPath)
            || !is_readable($temporaryPath)
        ) {
            throw new UploadException('The uploaded temporary file is unavailable.');
        }

        $sourceStat = stat($temporaryPath);
        if (
            $sourceStat === false
            || !isset($sourceStat['size'], $sourceStat['dev'], $sourceStat['ino'])
            || $sourceStat['size'] !== $size
        ) {
            throw new UploadException('The uploaded file size is inconsistent.');
        }

        return new self($temporaryPath, $name, $size, $sourceStat['dev'], $sourceStat['ino']);
    }

    public function originalName(): string
    {
        return $this->originalName;
    }

    public function size(): int
    {
        return $this->reportedSize;
    }

    public function extension(): string
    {
        $separator = strrpos($this->originalName, '.');
        if ($separator === false || $separator === strlen($this->originalName) - 1) {
            throw new UploadException('The uploaded filename must have an allowed extension.');
        }

        $extension = strtolower(substr($this->originalName, $separator + 1));
        if (preg_match('/\A[a-z0-9]{1,16}\z/D', $extension) !== 1) {
            throw new UploadException('The uploaded filename extension is invalid.');
        }

        return $extension;
    }

    public function detectMimeType(): string
    {
        if (!class_exists(\finfo::class)) {
            throw new UploadException('The PHP fileinfo extension is required for upload validation.');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($this->temporaryPath);
        if (!is_string($mimeType) || $mimeType === '') {
            throw new UploadException('Unable to detect the uploaded file content type.');
        }

        return strtolower($mimeType);
    }

    /**
     * Copy the validated upload to a writable stream.
     *
     * @param resource $destination
     */
    public function copyTo(mixed $destination): void
    {
        if (!is_resource($destination)) {
            throw new InvalidArgumentException('The upload destination must be a stream.');
        }

        $source = @fopen($this->temporaryPath, 'rb');
        if ($source === false) {
            throw new UploadException('Unable to read the uploaded temporary file.');
        }

        try {
            $stat = fstat($source);
            if (
                $stat === false
                || !isset($stat['size'], $stat['dev'], $stat['ino'])
                || $stat['size'] !== $this->reportedSize
                || $stat['dev'] !== $this->sourceDevice
                || $stat['ino'] !== $this->sourceInode
            ) {
                throw new UploadException('The uploaded file changed after validation.');
            }

            $copied = stream_copy_to_stream($source, $destination);
            if ($copied === false || $copied !== $this->reportedSize) {
                throw new UploadException('Unable to copy the complete uploaded file.');
            }
        } finally {
            fclose($source);
        }
    }
}
