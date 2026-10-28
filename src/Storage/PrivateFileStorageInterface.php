<?php

declare(strict_types=1);

namespace Nora\Storage;

use Nora\Http\ValidatedUpload;

interface PrivateFileStorageInterface
{
    public function put(ValidatedUpload $upload): StoredFile;

    /**
     * Return a readable stream for an authorized application retrieval.
     *
     * @return resource
     */
    public function open(string $identifier): mixed;

    public function delete(string $identifier): void;
}
