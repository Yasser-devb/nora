<?php

declare(strict_types=1);

namespace Nora\Storage;

use Nora\Http\HttpException;
use Nora\Http\Request;

final class AuthorizedPrivateFileReader
{
    public function __construct(
        private readonly PrivateFileStorageInterface $storage,
        private readonly PrivateFileAuthorizerInterface $authorizer,
    ) {
    }

    /**
     * The application must resolve $file from trusted metadata before calling.
     *
     * @return resource
     */
    public function open(Request $request, StoredFile $file): mixed
    {
        if (!$this->authorizer->canRead($request, $file)) {
            throw new HttpException(404, 'The private file is unavailable.');
        }

        return $this->storage->open($file->identifier());
    }
}
