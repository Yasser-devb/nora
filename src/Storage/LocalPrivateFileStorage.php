<?php

declare(strict_types=1);

namespace Nora\Storage;

use Nora\Http\ValidatedUpload;

final class LocalPrivateFileStorage implements PrivateFileStorageInterface
{
    private readonly string $directory;

    public function __construct(string $directory)
    {
        if ($directory === '') {
            throw new StorageException('A private storage directory is required.');
        }

        $normalized = rtrim($directory, '/\\');
        if ($normalized !== '' && is_link($normalized)) {
            throw new StorageException('The storage directory must not be a symbolic link.');
        }
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new StorageException('Unable to create the private storage directory.');
        }
        if (is_link($directory) || !is_dir($directory)) {
            throw new StorageException('The storage path must be a real directory.');
        }

        $realDirectory = realpath($directory);
        if ($realDirectory === false) {
            throw new StorageException('Unable to resolve the private storage directory.');
        }
        $documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? null;
        if (is_string($documentRoot) && $documentRoot !== '') {
            $realDocumentRoot = realpath($documentRoot);
            if ($realDocumentRoot !== false && self::isWithinDirectory($realDirectory, $realDocumentRoot)) {
                throw new StorageException('Private storage must be outside the public document root.');
            }
        }
        if (DIRECTORY_SEPARATOR !== '\\' && !@chmod($realDirectory, 0700)) {
            throw new StorageException('Unable to restrict private storage directory permissions.');
        }
        $this->directory = $realDirectory;
    }

    public function put(ValidatedUpload $upload): StoredFile
    {
        try {
            $identifier = bin2hex(random_bytes(16));
        } catch (\Throwable $exception) {
            throw new StorageException('Unable to generate a private storage identifier.', 0, $exception);
        }

        $temporaryPath = $this->directory . DIRECTORY_SEPARATOR . '.nora-upload-' . $identifier . '.tmp';
        $targetPath = $this->filePath($identifier);
        $stream = @fopen($temporaryPath, 'x+b');
        if ($stream === false) {
            throw new StorageException('Unable to create a private upload file.');
        }

        $stored = false;
        try {
            if (DIRECTORY_SEPARATOR !== '\\' && !@chmod($temporaryPath, 0600)) {
                throw new StorageException('Unable to restrict private upload file permissions.');
            }
            $upload->copyTo($stream);
            if (!fflush($stream)) {
                throw new StorageException('Unable to flush the private upload file.');
            }
            if (!fclose($stream)) {
                $stream = null;
                throw new StorageException('Unable to close the private upload file.');
            }
            $stream = null;

            if (file_exists($targetPath) || is_link($targetPath) || !@rename($temporaryPath, $targetPath)) {
                throw new StorageException('Unable to finalize the private upload file.');
            }
            $stored = true;

            return new StoredFile($identifier, $upload->mimeType(), $upload->size());
        } catch (StorageException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new StorageException('The private upload could not be stored.', 0, $exception);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            if (!$stored && file_exists($temporaryPath) && !@unlink($temporaryPath)) {
                throw new StorageException('Unable to remove a partial private upload.');
            }
        }
    }

    public function open(string $identifier): mixed
    {
        $path = $this->filePath($identifier);
        if (is_link($path) || !is_file($path) || !is_readable($path)) {
            throw new StorageException('The private file is unavailable.');
        }

        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            throw new StorageException('Unable to open the private file.');
        }

        return $stream;
    }

    public function delete(string $identifier): void
    {
        $path = $this->filePath($identifier);
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_link($path) || !is_file($path) || !@unlink($path)) {
            throw new StorageException('Unable to remove the private file.');
        }
    }

    private function filePath(string $identifier): string
    {
        if (preg_match('/\A[a-f0-9]{32}\z/D', $identifier) !== 1) {
            throw new StorageException('The private file identifier is invalid.');
        }

        return $this->directory . DIRECTORY_SEPARATOR . $identifier . '.private';
    }

    private static function isWithinDirectory(string $path, string $directory): bool
    {
        $path = rtrim(str_replace('\\', '/', $path), '/') . '/';
        $directory = rtrim(str_replace('\\', '/', $directory), '/') . '/';
        if (DIRECTORY_SEPARATOR === '\\') {
            $path = strtolower($path);
            $directory = strtolower($directory);
        }

        return str_starts_with($path, $directory);
    }
}
