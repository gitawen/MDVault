<?php

namespace App\Services;

use App\Contracts\UserDirectories;
use App\Enums\SettingKey;
use App\Exceptions\InvalidStorageRootException;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

/**
 * Resolves and changes the folder under which future vaults are created.
 *
 * Changing or resetting the root never moves, copies or deletes user data;
 * it only records where future vaults will be created (see ADR
 * storage-root-resolution). Filesystem changes always happen before the
 * setting is written, so a failed database write can leave, at worst, an
 * empty created directory.
 */
final class StoragePathService
{
    public const FOLDER_NAME = 'MDVault';

    public function __construct(
        private readonly SettingsService $settings,
        private readonly UserDirectories $directories,
        private readonly Filesystem $files,
        private readonly Application $app,
    ) {}

    /**
     * The platform-appropriate default root. Never created or persisted.
     */
    public function defaultRootPath(): string
    {
        $base = $this->directories->documentsPath() ?? $this->app->storagePath('app');

        return rtrim($base, '\\/').DIRECTORY_SEPARATOR.self::FOLDER_NAME;
    }

    /**
     * The effective root: the stored value, or the default.
     */
    public function rootPath(): string
    {
        return $this->settings->string(SettingKey::StorageRootPath) ?? $this->defaultRootPath();
    }

    public function isUsingDefault(): bool
    {
        return ! $this->settings->has(SettingKey::StorageRootPath);
    }

    /**
     * @return array{root_path: string, default_path: string, is_default: bool, exists: bool, writable: bool}
     */
    public function summary(): array
    {
        $root = $this->rootPath();
        $exists = is_dir($root);

        return [
            'root_path' => $root,
            'default_path' => $this->defaultRootPath(),
            'is_default' => $this->isUsingDefault(),
            'exists' => $exists,
            'writable' => $exists && is_writable($root),
        ];
    }

    /**
     * Normalise a user-supplied path: trim, fix separators and collapse
     * repeated separators, without touching the filesystem.
     *
     * @throws InvalidStorageRootException
     */
    public function normalize(string $path): string
    {
        $trimmed = trim($path);

        if ($trimmed === '' || str_contains($trimmed, "\0")) {
            throw InvalidStorageRootException::invalid();
        }

        if ($this->isWindows()) {
            $trimmed = str_replace('/', '\\', $trimmed);
        }

        $separator = $this->isWindows() ? '\\' : '/';
        $isUnc = $this->isWindows() && str_starts_with($trimmed, '\\\\');
        $prefix = $isUnc ? '\\\\' : '';
        $rest = $isUnc ? substr($trimmed, 2) : $trimmed;

        $collapsed = preg_replace('#'.preg_quote($separator, '#').'{2,}#', $separator, $rest) ?? $rest;
        $normalized = $prefix.$collapsed;

        if ($this->isRoot($normalized)) {
            return $normalized;
        }

        return rtrim($normalized, '\\/');
    }

    /**
     * Whether a (already normalised) path is absolute for the current OS.
     */
    public function isAbsolute(string $path): bool
    {
        if ($this->isWindows()) {
            return (bool) preg_match('/^[A-Za-z]:\\\\/', $path)
                || (bool) preg_match('/^\\\\\\\\[^\\\\]+\\\\[^\\\\]+/', $path);
        }

        return str_starts_with($path, '/');
    }

    /**
     * Validate, create if missing, probe for writability and persist a new
     * storage root. Returns the canonical path.
     *
     * @throws InvalidStorageRootException
     */
    public function changeRoot(string $path): string
    {
        $normalized = $this->normalize($path);

        if (! $this->isAbsolute($normalized)) {
            throw InvalidStorageRootException::notAbsolute();
        }

        if ($this->files->exists($normalized) && ! $this->files->isDirectory($normalized)) {
            throw InvalidStorageRootException::isFile();
        }

        if (! $this->files->isDirectory($normalized)) {
            $created = $this->files->makeDirectory($normalized, 0755, true, true);

            if (! $created || ! is_dir($normalized)) {
                throw InvalidStorageRootException::cannotCreate();
            }
        }

        $this->probeWritable($normalized);

        $canonical = realpath($normalized) ?: $normalized;
        $defaultReal = realpath($this->defaultRootPath());

        if ($defaultReal !== false && $canonical === $defaultReal) {
            $this->settings->forget(SettingKey::StorageRootPath);
        } else {
            $this->settings->set(SettingKey::StorageRootPath, $canonical);
        }

        return $canonical;
    }

    /**
     * Forget the stored root, reverting to the default. Creates nothing.
     */
    public function resetToDefault(): void
    {
        $this->settings->forget(SettingKey::StorageRootPath);
    }

    /**
     * @throws InvalidStorageRootException
     */
    private function probeWritable(string $path): void
    {
        $probe = $path.DIRECTORY_SEPARATOR.'.mdvault-write-test-'.Str::random(12);

        try {
            $written = $this->files->put($probe, '');

            if ($written === false) {
                throw InvalidStorageRootException::notWritable();
            }
        } catch (InvalidStorageRootException $e) {
            throw $e;
        } catch (\Throwable) {
            throw InvalidStorageRootException::notWritable();
        } finally {
            if ($this->files->exists($probe)) {
                $this->files->delete($probe);
            }
        }
    }

    private function isRoot(string $path): bool
    {
        return $path === '/' || (bool) preg_match('/^[A-Za-z]:\\\\$/', $path);
    }

    private function isWindows(): bool
    {
        return PHP_OS_FAMILY === 'Windows';
    }
}
