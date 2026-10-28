<?php

declare(strict_types=1);

namespace Nora\Tests\Unit;

use InvalidArgumentException;
use Nora\Http\UploadException;
use Nora\Http\UploadedFile;
use Nora\Http\UploadPolicy;
use Nora\Http\Request;
use Nora\Storage\AuthorizedPrivateFileReader;
use Nora\Storage\LocalPrivateFileStorage;
use Nora\Storage\PrivateFileAuthorizerInterface;
use Nora\Storage\PrivateFileStorageInterface;
use Nora\Storage\PrivateUploadService;
use Nora\Storage\StoredFile;
use Nora\Storage\StorageException;
use PHPUnit\Framework\TestCase;

final class UploadTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    /** @var list<string> */
    private array $directories = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path) || is_link($path)) {
                unlink($path);
            }
        }
        foreach ($this->directories as $directory) {
            if (!is_dir($directory)) {
                continue;
            }
            foreach (scandir($directory) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $path = $directory . DIRECTORY_SEPARATOR . $entry;
                if (is_file($path) || is_link($path)) {
                    unlink($path);
                }
            }
            rmdir($directory);
        }
    }

    public function testValidatesActualFileContentAndStoresUnderRandomPrivateIdentifier(): void
    {
        $upload = $this->upload('..\..\outside.txt', 'plain content');
        $policy = new UploadPolicy(100, 200, ['txt' => ['text/plain']]);
        $validated = $policy->validate($upload);
        $storage = new LocalPrivateFileStorage($this->directory());

        $stored = $storage->put($validated);
        $stream = $storage->open($stored->identifier());
        $contents = stream_get_contents($stream);
        fclose($stream);

        self::assertSame('text/plain', $stored->mimeType());
        self::assertSame('plain content', $contents);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/D', $stored->identifier());
        self::assertSame(strlen('plain content'), $stored->size());
        self::assertFileExists($this->directoryPath() . DIRECTORY_SEPARATOR . $stored->identifier() . '.private');
        self::assertFileDoesNotExist($this->directoryPath() . DIRECTORY_SEPARATOR . 'outside.txt');
    }

    public function testRejectsMimeSpoofingAndUnsupportedExtensions(): void
    {
        $policy = new UploadPolicy(100, 200, ['txt' => ['text/plain']]);

        $this->expectException(UploadException::class);
        $policy->validate($this->upload('payload.txt', "\x89PNG\r\n\x1A\n"));
    }

    public function testRejectsPerFileAndAggregateRequestLimits(): void
    {
        $policy = new UploadPolicy(5, 7, ['txt' => ['text/plain']]);

        try {
            $policy->validate($this->upload('oversized.txt', '123456'));
            self::fail('Expected the per-file size limit to reject the file.');
        } catch (UploadException $exception) {
            self::assertStringContainsString('per-file', $exception->getMessage());
        }

        try {
            $policy->validateBatch([
                $this->upload('one.txt', '1234'),
                $this->upload('two.txt', '5678'),
            ]);
            self::fail('Expected the aggregate request size limit to reject the files.');
        } catch (UploadException $exception) {
            self::assertStringContainsString('request size', $exception->getMessage());
        }
    }

    public function testRejectsPhpUploadErrorsAndMalformedMetadata(): void
    {
        $path = $this->temporaryFile('data');
        try {
            UploadedFile::fromPhpArray([
                'name' => 'file.txt',
                'tmp_name' => $path,
                'error' => UPLOAD_ERR_PARTIAL,
                'size' => 4,
            ]);
            self::fail('Expected a partial upload to be rejected.');
        } catch (UploadException) {
            self::assertTrue(true);
        }

        foreach (
            [
                ['name' => "bad\nname.txt", 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => 4],
                ['name' => 'file.txt', 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => '4'],
                ['name' => 'file.txt', 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => 3],
            ] as $metadata
        ) {
            try {
                UploadedFile::fromPhpArray($metadata);
                self::fail('Expected malformed upload metadata to be rejected.');
            } catch (UploadException) {
                self::assertTrue(true);
            }
        }
    }

    public function testRequestExposesSingleAndMultipleMultipartUploadFields(): void
    {
        $one = $this->temporaryFile('first');
        $two = $this->temporaryFile('second');
        $files = [
            'document' => [
                'name' => 'document.txt',
                'tmp_name' => $one,
                'error' => UPLOAD_ERR_OK,
                'size' => 5,
            ],
            'attachments' => [
                'name' => ['first.txt', 'second.txt'],
                'tmp_name' => [$one, $two],
                'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_OK],
                'size' => [5, 6],
            ],
        ];
        $request = new Request('POST', '/upload', [], '', null, $files);

        self::assertSame('document.txt', $request->file('document')?->originalName());
        self::assertSame(['first.txt', 'second.txt'], array_map(
            static fn (UploadedFile $file): string => $file->originalName(),
            $request->files('attachments'),
        ));
        self::assertNull($request->file('missing'));
    }

    public function testFromGlobalsAcceptsPhpParsedMultipartFilesWithinDeclaredLimit(): void
    {
        $path = $this->temporaryFile('content');
        $request = Request::fromGlobals(
            [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => '/upload',
                'CONTENT_TYPE' => 'multipart/form-data; boundary=fixture',
                'CONTENT_LENGTH' => '128',
            ],
            null,
            null,
            128,
            [
                'document' => [
                    'name' => 'document.txt',
                    'tmp_name' => $path,
                    'error' => UPLOAD_ERR_OK,
                    'size' => 7,
                ],
            ],
        );

        self::assertSame(7, $request->file('document')?->size());

        try {
            Request::fromGlobals(
                [
                    'REQUEST_METHOD' => 'POST',
                    'REQUEST_URI' => '/upload',
                    'CONTENT_TYPE' => 'multipart/form-data; boundary=fixture',
                    'CONTENT_LENGTH' => '129',
                ],
                null,
                null,
                128,
                [],
            );
            self::fail('Declared multipart request size must respect the configured ingress limit.');
        } catch (\Nora\Http\HttpException $exception) {
            self::assertSame(413, $exception->status());
        }
    }

    public function testUploadErrorsAndPolicySizeFailuresExposeHttpStatus(): void
    {
        try {
            UploadedFile::fromPhpArray([
                'name' => '',
                'tmp_name' => '',
                'error' => UPLOAD_ERR_INI_SIZE,
                'size' => 0,
            ]);
            self::fail('Expected PHP upload size errors to be rejected.');
        } catch (UploadException $exception) {
            self::assertSame(413, $exception->status());
        }

        try {
            (new UploadPolicy(2, 4, ['txt' => ['text/plain']]))
                ->validate($this->upload('large.txt', 'three'));
            self::fail('Expected an oversized upload to be rejected.');
        } catch (UploadException $exception) {
            self::assertSame(413, $exception->status());
        }
    }

    public function testRequestMapsPhpParsedUploadFailuresToSafeHttpErrors(): void
    {
        $request = new Request('POST', '/upload', [], '', null, [
            'large' => [
                'name' => '',
                'tmp_name' => '',
                'error' => UPLOAD_ERR_INI_SIZE,
                'size' => 0,
            ],
        ]);
        try {
            $request->file('large');
            self::fail('Oversized PHP uploads should be surfaced to the route as an HTTP error.');
        } catch (UploadException $exception) {
            self::assertSame(413, $exception->status());
            self::assertStringNotContainsString('tmp', $exception->getMessage());
        }

        $noFile = new Request('POST', '/upload', [], '', null, [
            'empty' => [
                'name' => '',
                'tmp_name' => '',
                'error' => UPLOAD_ERR_NO_FILE,
                'size' => 0,
            ],
        ]);
        self::assertNull($noFile->file('empty'));
    }

    public function testRejectsInvalidUploadPolicyAndTraversalIdentifiers(): void
    {
        foreach ([0, -1] as $limit) {
            try {
                new UploadPolicy($limit, 100, ['txt' => ['text/plain']]);
                self::fail('Expected invalid size limits to be rejected.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }

        $storage = new LocalPrivateFileStorage($this->directory());
        foreach (['../secret', str_repeat('a', 32), 'not-a-token', ''] as $identifier) {
            try {
                $storage->open($identifier);
                self::fail('Expected unsafe or unknown file identifiers to be rejected.');
            } catch (StorageException) {
                self::assertTrue(true);
            }
        }
    }

    public function testStorageFailureCleansPartialTemporaryFile(): void
    {
        $directory = $this->directory();
        $uploadPath = $this->temporaryFile('partial upload contents');
        $validated = (new UploadPolicy(100, 200, ['txt' => ['text/plain']]))
            ->validate(UploadedFile::fromPhpArray([
                'name' => 'payload.txt',
                'tmp_name' => $uploadPath,
                'error' => UPLOAD_ERR_OK,
                'size' => strlen('partial upload contents'),
            ]));
        unlink($uploadPath);

        try {
            (new LocalPrivateFileStorage($directory))->put($validated);
            self::fail('Expected missing upload source to fail storage.');
        } catch (StorageException) {
            self::assertSame([], glob($directory . DIRECTORY_SEPARATOR . '.nora-upload-*.tmp'));
        }
    }

    public function testBatchStorageFailureRemovesEarlierSuccessfulWrites(): void
    {
        $storage = new class implements PrivateFileStorageInterface {
            /** @var list<string> */
            public array $deleted = [];

            private int $writes = 0;

            public function put(\Nora\Http\ValidatedUpload $upload): StoredFile
            {
                $this->writes++;
                if ($this->writes === 2) {
                    throw new StorageException('simulated storage failure');
                }

                return new StoredFile(str_repeat('a', 31) . 'b', $upload->mimeType(), $upload->size());
            }

            public function open(string $identifier): mixed
            {
                throw new \LogicException('Not used by this test.');
            }

            public function delete(string $identifier): void
            {
                $this->deleted[] = $identifier;
            }
        };
        $service = new PrivateUploadService(
            new UploadPolicy(100, 200, ['txt' => ['text/plain']]),
            $storage,
        );

        try {
            $service->storeBatch([
                $this->upload('first.txt', 'first'),
                $this->upload('second.txt', 'second'),
            ]);
            self::fail('A failed batch storage operation must fail the request.');
        } catch (StorageException $exception) {
            self::assertSame('simulated storage failure', $exception->getMessage());
        }

        self::assertSame([str_repeat('a', 31) . 'b'], $storage->deleted);
    }

    public function testStorageRejectsNonDirectoryPath(): void
    {
        $file = $this->temporaryFile('not a directory');
        $this->expectException(StorageException::class);
        new LocalPrivateFileStorage($file);
    }

    public function testPrivateStorageRejectsTheConfiguredPublicDocumentRoot(): void
    {
        $directory = $this->directory();
        $previousDocumentRoot = $_SERVER['DOCUMENT_ROOT'] ?? null;
        $_SERVER['DOCUMENT_ROOT'] = $directory;
        try {
            new LocalPrivateFileStorage($directory);
            self::fail('Private storage must not use the public document root.');
        } catch (StorageException $exception) {
            self::assertStringContainsString('outside the public', $exception->getMessage());
        } finally {
            if ($previousDocumentRoot === null) {
                unset($_SERVER['DOCUMENT_ROOT']);
            } else {
                $_SERVER['DOCUMENT_ROOT'] = $previousDocumentRoot;
            }
        }
    }

    public function testAuthorizedPrivateReaderChecksAccessBeforeOpeningStorage(): void
    {
        $storage = new class implements PrivateFileStorageInterface {
            public bool $opened = false;

            public function put(\Nora\Http\ValidatedUpload $upload): StoredFile
            {
                throw new \LogicException('Not used by this test.');
            }

            public function open(string $identifier): mixed
            {
                $this->opened = true;

                return fopen('php://memory', 'rb');
            }

            public function delete(string $identifier): void
            {
            }
        };
        $request = new Request('GET', '/private/file');
        $file = new StoredFile(str_repeat('a', 32), 'text/plain', 10);
        $denied = new AuthorizedPrivateFileReader(
            $storage,
            new class implements PrivateFileAuthorizerInterface {
                public function canRead(Request $request, StoredFile $file): bool
                {
                    return false;
                }
            },
        );

        try {
            $denied->open($request, $file);
            self::fail('An unauthorized retrieval must be denied.');
        } catch (\Nora\Http\HttpException $exception) {
            self::assertSame(404, $exception->status());
        }
        self::assertFalse($storage->opened);

        $allowed = new AuthorizedPrivateFileReader(
            $storage,
            new class implements PrivateFileAuthorizerInterface {
                public function canRead(Request $request, StoredFile $file): bool
                {
                    return true;
                }
            },
        );
        $stream = $allowed->open($request, $file);
        self::assertIsResource($stream);
        fclose($stream);
        self::assertTrue($storage->opened);
    }

    public function testDeleteOnlyRemovesTheSelectedPrivateFile(): void
    {
        $directory = $this->directory();
        $storage = new LocalPrivateFileStorage($directory);
        $unrelated = $directory . DIRECTORY_SEPARATOR . 'keep.txt';
        file_put_contents($unrelated, 'keep');
        $stored = $storage->put(
            (new UploadPolicy(100, 200, ['txt' => ['text/plain']]))
                ->validate($this->upload('delete.txt', 'delete me')),
        );

        $storage->delete($stored->identifier());
        $storage->delete($stored->identifier());

        self::assertFileDoesNotExist($directory . DIRECTORY_SEPARATOR . $stored->identifier() . '.private');
        self::assertSame('keep', file_get_contents($unrelated));
    }

    private function upload(string $name, string $contents): UploadedFile
    {
        $path = $this->temporaryFile($contents);

        return UploadedFile::fromPhpArray([
            'name' => $name,
            'tmp_name' => $path,
            'error' => UPLOAD_ERR_OK,
            'size' => strlen($contents),
            'type' => 'application/octet-stream',
        ]);
    }

    private function temporaryFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'nora-upload-test-');
        self::assertIsString($path);
        self::assertNotFalse(file_put_contents($path, $contents));
        $this->temporaryFiles[] = $path;

        return $path;
    }

    private function directory(): string
    {
        $directory = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'nora-upload-store-'
            . bin2hex(random_bytes(8));
        $this->directories[] = $directory;

        return $directory;
    }

    private function directoryPath(): string
    {
        return end($this->directories) ?: '';
    }
}
