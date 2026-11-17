<?php

namespace App\Services;

use App\Enums\VaultStatus;
use App\Exceptions\InvalidStorageRootException;
use App\Exceptions\MediaOperationException;
use App\Models\Vault;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Validates, stores, and safely resolves raster image attachments in
 * MDVault's shared root Media folder (ADR `media-attachment-storage`).
 */
final class MediaService
{
    public const MAX_UPLOAD_BYTES = 10 * 1024 * 1024;

    /**
     * Raster MIME types allowed for both upload and inline serving. SVG and
     * active XML/HTML types are excluded to prevent stored XSS.
     *
     * @var array<string, string>
     */
    public const ALLOWED_MIME_EXTENSIONS = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    private const MAX_COLLISION_ATTEMPTS = 3;

    public function __construct(
        private readonly StoragePathService $paths,
        private readonly FileStorageService $files,
        private readonly VaultService $vaults,
    ) {}

    /**
     * Validate and store an uploaded image for an active, unencrypted vault.
     *
     * @return array{filename: string, url: string, mime_type: string, size: int}
     *
     * @throws MediaOperationException
     */
    public function store(Vault $vault, UploadedFile $file): array
    {
        $this->vaults->refreshStatus($vault);

        if ($vault->status === VaultStatus::Missing) {
            throw MediaOperationException::vaultUnavailable();
        }

        if ($vault->is_encrypted) {
            throw MediaOperationException::encryptedVaultUnsupported();
        }

        $validated = $this->validateUploadedImage($file);
        $mediaDir = $this->ensureMediaDirectoryReady();

        $vaultSlug = $this->sanitizeSegment($vault->name, 48, 'vault');
        $rawStem = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $stemSlug = $this->sanitizeSegment($rawStem, 32, 'screenshot');
        $timestamp = gmdate('Ymd-His');
        $extension = self::ALLOWED_MIME_EXTENSIONS[$validated['mime']];

        for ($attempt = 0; $attempt < self::MAX_COLLISION_ATTEMPTS; $attempt++) {
            $suffix = bin2hex(random_bytes(4));
            $filename = "{$vaultSlug}-{$stemSlug}-{$timestamp}-{$suffix}.{$extension}";
            $target = $this->files->joinRelative($mediaDir, $filename);

            $written = $this->files->createFileFromPath(
                $target,
                $validated['path'],
                self::MAX_UPLOAD_BYTES,
            );

            if ($written !== null) {
                return [
                    'filename' => $filename,
                    'url' => route('media.show', ['filename' => $filename], absolute: false),
                    'mime_type' => $validated['mime'],
                    'size' => $written,
                ];
            }
        }

        throw MediaOperationException::writeFailed();
    }

    /**
     * Resolve a stored media file for safe inline serving, or return null if
     * invalid, missing, symlinked, outside Media/, or not an allowed raster image.
     *
     * @return ?array{path: string, mime: string, size: int}
     */
    public function resolveForServing(string $filename): ?array
    {
        if (
            $filename === ''
            || str_contains($filename, "\0")
            || str_contains($filename, '..')
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/', $filename) !== 1
        ) {
            return null;
        }

        $mediaDir = $this->paths->mediaDirectory();

        if (! $this->files->isDirectory($mediaDir) || $this->files->isSymlink($mediaDir)) {
            return null;
        }

        $target = $this->files->joinRelative($mediaDir, $filename);

        if (! $this->files->isFile($target) || $this->files->isSymlink($target)) {
            return null;
        }

        $canonicalMedia = $this->files->canonical($mediaDir);
        $canonicalTarget = $this->files->canonical($target);

        if (
            ! $this->files->isSameOrInside($canonicalTarget, $canonicalMedia)
            || $this->files->samePath($canonicalTarget, $canonicalMedia)
        ) {
            return null;
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($canonicalTarget);

        if (! is_string($mime) || ! array_key_exists($mime, self::ALLOWED_MIME_EXTENSIONS)) {
            return null;
        }

        $size = $this->files->size($canonicalTarget);

        if ($size === null) {
            return null;
        }

        return [
            'path' => $canonicalTarget,
            'mime' => $mime,
            'size' => $size,
        ];
    }

    /**
     * @return array{path: string, mime: string, size: int}
     *
     * @throws MediaOperationException
     */
    private function validateUploadedImage(UploadedFile $file): array
    {
        if (! $file->isValid()) {
            if (in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                throw MediaOperationException::tooLarge();
            }

            throw MediaOperationException::invalidImage();
        }

        $realPath = $file->getRealPath();

        if ($realPath === false || ! $this->files->isFile($realPath)) {
            throw MediaOperationException::invalidImage();
        }

        $size = $file->getSize();

        if (! is_int($size) || $size <= 0) {
            throw MediaOperationException::invalidImage();
        }

        if ($size > self::MAX_UPLOAD_BYTES) {
            throw MediaOperationException::tooLarge();
        }

        $mime = $file->getMimeType();

        if (! is_string($mime) || ! array_key_exists($mime, self::ALLOWED_MIME_EXTENSIONS)) {
            throw MediaOperationException::invalidImage();
        }

        $imageInfo = @getimagesize($realPath);

        if ($imageInfo === false || $imageInfo[0] <= 0 || $imageInfo[1] <= 0) {
            throw MediaOperationException::invalidImage();
        }

        if (! array_key_exists($imageInfo['mime'], self::ALLOWED_MIME_EXTENSIONS)) {
            throw MediaOperationException::invalidImage();
        }

        return [
            'path' => $realPath,
            'mime' => $mime,
            'size' => $size,
        ];
    }

    /**
     * @throws MediaOperationException
     */
    private function ensureMediaDirectoryReady(): string
    {
        try {
            $this->paths->ensureRootReady();
        } catch (InvalidStorageRootException) {
            throw MediaOperationException::mediaFolderUnavailable();
        }

        $mediaDir = $this->paths->mediaDirectory();

        if ($this->vaults->overlappingVault($mediaDir) !== null) {
            throw MediaOperationException::mediaFolderConflict();
        }

        if ($this->files->exists($mediaDir)) {
            if (! $this->files->isDirectory($mediaDir) || $this->files->isSymlink($mediaDir)) {
                throw MediaOperationException::mediaFolderUnavailable();
            }
        } elseif (! $this->files->makeDirectory($mediaDir)) {
            throw MediaOperationException::mediaFolderUnavailable();
        }

        if (! $this->files->isWritableDirectory($mediaDir)) {
            throw MediaOperationException::mediaFolderUnavailable();
        }

        return $this->files->canonical($mediaDir);
    }

    private function sanitizeSegment(string $value, int $maxLength, string $fallback): string
    {
        $ascii = Str::ascii($value);
        $slug = preg_replace('/[^A-Za-z0-9_-]+/', '-', $ascii) ?? '';
        $slug = trim($slug, '-_');
        $slug = substr($slug, 0, $maxLength);
        $slug = rtrim($slug, '-_');

        return $slug !== '' ? $slug : $fallback;
    }
}
