<?php

declare(strict_types=1);

namespace Nora\Storage;

use Nora\Http\UploadPolicy;
use Nora\Http\UploadedFile;
use Throwable;

final class PrivateUploadService
{
    public function __construct(
        private readonly UploadPolicy $policy,
        private readonly PrivateFileStorageInterface $storage,
    ) {
    }

    public function store(UploadedFile $upload): StoredFile
    {
        return $this->storage->put($this->policy->validate($upload));
    }

    /**
     * Validate all inputs before writing any file; remove prior writes if a
     * later storage operation fails.
     *
     * @param non-empty-list<UploadedFile> $uploads
     * @return list<StoredFile>
     */
    public function storeBatch(array $uploads): array
    {
        $validated = $this->policy->validateBatch($uploads);
        $stored = [];

        try {
            foreach ($validated as $upload) {
                $stored[] = $this->storage->put($upload);
            }
        } catch (Throwable $failure) {
            $cleanupFailure = null;
            foreach (array_reverse($stored) as $file) {
                try {
                    $this->storage->delete($file->identifier());
                } catch (Throwable $exception) {
                    $cleanupFailure ??= $exception;
                }
            }
            if ($cleanupFailure !== null) {
                error_log(sprintf(
                    'Nora private upload cleanup failed (%s) after storage failure (%s).',
                    $cleanupFailure::class,
                    $failure::class,
                ));
                throw new StorageException(
                    'The upload batch failed and one or more stored files could not be removed.',
                    0,
                    $failure,
                );
            }

            throw $failure;
        }

        return $stored;
    }
}
