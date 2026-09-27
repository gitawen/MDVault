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

    /**
     * Windows reserved device names (case-insensitive, with or without an
     * extension). Rejected on every OS for portability.
     *
     * @var list<string>
     */
    private const RESERVED_FOLDER_NAMES = [
        'CON', 'PRN', 'AUX', 'NUL',
        'COM1', 'COM2', 'COM3', 'COM4', 'COM5', 'COM6', 'COM7', 'COM8', 'COM9',
        'LPT1', 'LPT2', 'LPT3', 'LPT4', 'LPT5', 'LPT6', 'LPT7', 'LPT8', 'LPT9',
    ];

    public function __construct(
        private readonly SettingsService $settings,
        private readonly UserDirectories $directories,
        private readonly Filesystem $files,
        private readonly Application $app,
    ) {}

    /**
     * The stored folder name, or the default (`self::FOLDER_NAME`).
     */
    public function folderName(): string
    {
        return $this->settings->string(SettingKey::StorageFolderName) ?? self::FOLDER_NAME;
    }

    /**
     * The platform-appropriate default root. Never created or persisted.
     */
    public function defaultRootPath(): string
    {
        $base = $this->directories->documentsPath() ?? $this->app->storagePath('app');

        return rtrim($base, '\\/').DIRECTORY_SEPARATOR.$this->folderName();
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
     * @return array{root_path: string, default_path: string, is_default: bool, exists: bool, writable: bool, location: string, folder_name: string}
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
            'writable' => $exists && $this->isWritable($root),
            'location' => dirname($root),
            'folder_name' => $this->folderName(),
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
     * storage root and (optionally) a new folder name. Returns the
     * canonical path.
     *
     * `$folderName` defaults to the currently effective folder name
     * (`self::folderName()`) when omitted, so existing callers that only
     * ever changed the location keep working unchanged.
     *
     * @throws InvalidStorageRootException
     */
    public function changeRoot(string $location, ?string $folderName = null): string
    {
        $effectiveName = $folderName ?? $this->folderName();
        $this->assertValidFolderName($effectiveName);

        $normalized = $this->normalize($location);

        if (! $this->isAbsolute($normalized)) {
            throw InvalidStorageRootException::notAbsolute();
        }

        $root = $this->withFolderSuffix($normalized, $effectiveName);

        if ($this->files->exists($root) && ! $this->files->isDirectory($root)) {
            throw InvalidStorageRootException::isFile();
        }

        if (! $this->files->isDirectory($root)) {
            $created = $this->files->makeDirectory($root, 0755, true, true);

            if (! $created || ! is_dir($root)) {
                throw InvalidStorageRootException::cannotCreate();
            }
        }

        $this->probeWritable($root);

        $canonical = realpath($root) ?: $root;

        // Persist the folder name first: the default-root comparison below
        // reads it back via defaultRootPath()/folderName().
        if (strcasecmp($effectiveName, self::FOLDER_NAME) === 0) {
            $this->settings->forget(SettingKey::StorageFolderName);
        } else {
            $this->settings->set(SettingKey::StorageFolderName, $effectiveName);
        }

        $defaultReal = realpath($this->defaultRootPath());

        if ($defaultReal !== false && $canonical === $defaultReal) {
            $this->settings->forget(SettingKey::StorageRootPath);
        } else {
            $this->settings->set(SettingKey::StorageRootPath, $canonical);
        }

        return $canonical;
    }

    /**
     * Reject an invalid folder name. Portable across every OS: the check
     * always includes the Windows-reserved characters and device names,
     * regardless of which OS is currently running.
     *
     * @throws InvalidStorageRootException
     */
    public function assertValidFolderName(string $name): void
    {
        if ($name === '' || $name !== trim($name) || mb_strlen($name) > 100) {
            throw InvalidStorageRootException::invalidFolderName();
        }

        if ($name === '.' || $name === '..') {
            throw InvalidStorageRootException::invalidFolderName();
        }

        if (preg_match('/[\\\\\/<>:"|?*\x00-\x1F]/u', $name) === 1) {
            throw InvalidStorageRootException::invalidFolderName();
        }

        if (str_ends_with($name, '.')) {
            throw InvalidStorageRootException::invalidFolderName();
        }

        $withoutExtension = preg_replace('/\.[^.]*$/', '', $name) ?? $name;

        if (in_array(strtoupper($withoutExtension), self::RESERVED_FOLDER_NAMES, true)) {
            throw InvalidStorageRootException::invalidFolderName();
        }
    }

    /**
     * Forget the stored root, reverting to the default (the folder name is
     * kept, so the default becomes Documents/<folder name>). Creates
     * nothing.
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

    /**
     * Append the folder-name subfolder to a chosen location, unless the
     * chosen folder is itself already named that (case-insensitive).
     */
    private function withFolderSuffix(string $normalized, string $name): string
    {
        $trimmed = rtrim($normalized, '\\/');
        $lastSeparator = strripos($trimmed, DIRECTORY_SEPARATOR);
        $basename = $lastSeparator === false ? $trimmed : substr($trimmed, $lastSeparator + 1);

        if (strcasecmp($basename, $name) === 0) {
            return $normalized;
        }

        return $trimmed.DIRECTORY_SEPARATOR.$name;
    }

    /**
     * Non-throwing writability check. `is_writable()` is unreliable on
     * Windows for OneDrive/ACL-controlled folders, so this reuses the real
     * write probe instead. Only call this when the directory exists.
     */
    private function isWritable(string $path): bool
    {
        try {
            $this->probeWritable($path);

            return true;
        } catch (InvalidStorageRootException) {
            return false;
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
