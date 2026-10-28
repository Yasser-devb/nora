<?php

declare(strict_types=1);

namespace Nora\Storage;

use Nora\Http\Request;

interface PrivateFileAuthorizerInterface
{
    public function canRead(Request $request, StoredFile $file): bool;
}
