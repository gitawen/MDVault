<?php

namespace App\Services;

use App\Contracts\UserDirectories;
use App\Enums\SettingKey;
use App\Enums\VaultStatus;
use App\Exceptions\InvalidStorageRootException;
use App\Exceptions\VaultOperationException;
use App\Models\Note;
use App\Models\Vault;
use App\Support\RegistryResetResult;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;

/**
 * Vault lifecycle: create, register an existing folder, rename, remove
 * (unregister or move-to-trash), open/close/current, and status
 * reconciliation. See ADRs vault-registry-and-consistency and
 * vault-removal-and-rename-semantics.
 */
final class VaultService
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly StoragePathService $paths,
        private readonly FileStorageService $files,
        private readonly UserDirectories $directories,
        private readonly DatabaseManager $database,
    ) {}

    /**
     * @return list<Vault> ordered by name (case-insensitive), statuses reconciled
     */
    public function all(): array
    {
        $vaults = Vault::query()->get()
            ->sortBy(fn (Vault $vault): string => mb_strtolower($vault->name))
            ->map(fn (Vault $vault): Vault => $this->refreshStatus($vault))
            ->all();

        return array_values($vaults);
    }

    /**
     * Creates the vault's DB record and its folder.
     *
     * Order (§7): the DB record is inserted first inside a transaction,
     * then the directory is created, then it is verified writable, and
     * only then is the canonical path saved. If anything fails after the
     * directory was created by this call, that (still-empty) directory is
     * removed as compensation, since SQLite cannot roll back filesystem
     * changes. A crash between mkdir and commit can therefore leave at
     * most an empty orphan directory, which a later create with the same
     * name simply reuses (C8).
     *
     * @throws VaultOperationException
     */
    public function create(string $name, ?string $description = null): Vault
    {
        $this->assertValidName($name);
        $this->assertNameAvailable($name, null);

        try {
            $root = $this->paths->ensureRootReady();
        } catch (InvalidStorageRootException $e) {
            throw VaultOperationException::storageUnavailable($e->getMessage());
        }

        $target = $root.DIRECTORY_SEPARATOR.$name;

        if ($this->files->isFile($target)) {
            throw VaultOperationException::targetIsFile($target);
        }

        if ($this->files->isDirectory($target) && ! $this->files->isEmptyDirectory($target)) {
            throw VaultOperationException::targetNotEmpty($target);
        }

        $this->assertNoOverlap($target, 'name');

        $createdHere = false;

        try {
            return $this->database->connection()->transaction(function () use ($name, $description, $target, &$createdHere): Vault {
                $vault = Vault::query()->create([
                    'name' => $name,
                    'description' => $description,
                    'path' => $target,
                    'relative_path' => $name,
                    'status' => VaultStatus::Active,
                    'is_encrypted' => false,
                ]);

                if (! $this->files->isDirectory($target)) {
                    if (! $this->files->makeDirectory($target)) {
                        throw VaultOperationException::cannotCreate($target);
                    }

                    $createdHere = true;
                }

                if (! $this->files->isDirectory($target) || ! $this->files->isWritableDirectory($target)) {
                    throw VaultOperationException::notWritable($target, 'name');
                }

                $vault->update(['path' => $this->files->canonical($target)]);

                return $vault;
            });
        } catch (\Throwable $e) {
            if ($createdHere) {
                $this->files->deleteEmptyDirectory($target);
            }

            throw $e;
        }
    }

    /**
     * Registers an already-existing folder as a vault (C3). Creates
     * nothing on disk.
     *
     * @throws VaultOperationException
     */
    public function register(string $path, string $name, ?string $description = null): Vault
    {
        try {
            $normalized = $this->paths->normalize($path);
        } catch (InvalidStorageRootException) {
            throw VaultOperationException::notAbsolute();
        }

        if (! $this->paths->isAbsolute($normalized)) {
            throw VaultOperationException::notAbsolute();
        }

        if (! $this->files->isDirectory($normalized)) {
            throw VaultOperationException::pathNotDirectory($normalized);
        }

        $canonical = $this->files->canonical($normalized);

        $this->assertSafeFolder($canonical, 'path');
        $this->assertNoOverlap($canonical, 'path');

        if (! $this->files->isWritableDirectory($canonical)) {
            throw VaultOperationException::notWritable($canonical, 'path');
        }

        $this->assertValidName($name);
        $this->assertNameAvailable($name, null);

        return Vault::query()->create([
            'name' => $name,
            'description' => $description,
            'path' => $canonical,
            'relative_path' => $this->files->relativeTo($canonical, $this->paths->rootPath()),
            'status' => VaultStatus::Active,
            'is_encrypted' => false,
        ]);
    }

    /**
     * Renames the display name and/or description, and — when the name
     * changes and the folder's basename isn't already exactly the new
     * name — the vault's folder on disk too, in the same parent folder.
     *
     * Order: checks, then the folder rename, then a check that it worked,
     * then the DB update last. There is no DB write before the folder
     * rename; the one DB-side failure that could happen before it (a
     * unique `path` clash) is ruled out beforehand by `assertNoOverlap`,
     * so the only compensation direction needed is "rename back" if the
     * DB update itself fails. If renaming back also fails, the error is
     * reported and the user is told which folder to rename back by hand.
     *
     * @throws VaultOperationException
     */
    public function rename(Vault $vault, string $name, ?string $description): Vault
    {
        $this->assertValidName($name);
        $this->assertNameAvailable($name, $vault);

        if ($name === $vault->name) {
            $vault->update(['description' => $description]);

            return $vault;
        }

        $from = $vault->path;
        $to = $this->files->siblingPath($from, $name);

        if (basename($from) === $name) {
            $vault->update(['name' => $name, 'description' => $description]);

            return $vault;
        }

        $this->refreshStatus($vault);

        if ($vault->status === VaultStatus::Missing) {
            throw VaultOperationException::folderMissing($from, 'name');
        }

        $this->assertSafeFolder($from, 'name');

        if ($this->files->exists($to) && ! $this->files->isSameDirectory($from, $to)) {
            throw VaultOperationException::renameTargetExists($to);
        }

        $this->assertNoOverlap($to, 'name', $vault);

        if (! $this->files->renameDirectory($from, $to)) {
            if ($this->files->isDirectory($from)) {
                throw VaultOperationException::renameFailed($from, $to);
            }

            throw VaultOperationException::renameInterrupted($from, $to);
        }

        $newPath = $this->files->canonical($to);
        $newRelative = $vault->relative_path === null
            ? null
            : $this->replaceLastSegment($vault->relative_path, $name);

        $original = $vault->only(['name', 'description', 'path', 'relative_path']);

        try {
            $this->database->connection()->transaction(fn () => $vault->update([
                'name' => $name,
                'description' => $description,
                'path' => $newPath,
                'relative_path' => $newRelative,
            ]));
        } catch (\Throwable $e) {
            $vault->fill($original);

            if ($this->files->renameDirectory($newPath, $from)) {
                throw $e;
            }

            report($e);

            throw VaultOperationException::renameRollbackFailed($from, $newPath);
        }

        return $vault;
    }

    /**
     * Removes a vault. By default this only unregisters it (the folder and
     * its contents are untouched). With `$moveFolderToTrash`, the folder is
     * moved to the OS Recycle Bin / Trash first, checked afterwards, and
     * only then is the record deleted; MDVault never permanently deletes a
     * folder.
     *
     * @throws VaultOperationException
     */
    public function remove(Vault $vault, bool $moveFolderToTrash = false): void
    {
        $this->refreshStatus($vault);

        if ($moveFolderToTrash) {
            if (! $this->canTrash()) {
                throw VaultOperationException::trashUnavailable();
            }

            if ($vault->status === VaultStatus::Missing) {
                throw VaultOperationException::folderMissing($vault->path, 'move_to_trash');
            }

            $this->assertSafeFolder($vault->path, 'move_to_trash');

            if (! $this->files->moveToTrash($vault->path)) {
                throw VaultOperationException::trashFailed($vault->path);
            }
        }

        $this->database->connection()->transaction(function () use ($vault): void {
            if ($this->settings->string(SettingKey::CurrentVault) === $vault->uuid) {
                $this->settings->forget(SettingKey::CurrentVault);
            }

            $vault->delete();
        });
    }

    /**
     * Opens a vault: reconciles its status, refuses a missing folder, and
     * sets it as the current vault.
     *
     * @throws VaultOperationException
     */
    public function open(Vault $vault): Vault
    {
        $this->refreshStatus($vault);

        if ($vault->status === VaultStatus::Missing) {
            throw VaultOperationException::folderMissing($vault->path, 'vault');
        }

        $this->settings->set(SettingKey::CurrentVault, $vault->uuid);

        return $vault;
    }

    public function close(): void
    {
        $this->settings->forget(SettingKey::CurrentVault);
    }

    /**
     * Resets MDVault's vault registry (ADR `database-reset-semantics`): every
     * `notes` row, then every `vaults` row, is deleted, and `app.current_vault`
     * is forgotten, all inside one DB transaction. Notes are deleted before
     * vaults explicitly, so correctness never depends on the FK cascade being
     * enabled.
     *
     * This never touches the filesystem (no vault folders, notes, other
     * files, the storage root or staging folders), and never touches other
     * settings, `backups`, `sessions`, `cache`, `jobs` or `migrations`. It is
     * idempotent: an empty registry returns all-zero counts.
     *
     * @throws QueryException
     */
    public function resetRegistry(): RegistryResetResult
    {
        return $this->database->connection()->transaction(function (): RegistryResetResult {
            $vaults = Vault::query()->count();
            $missing = Vault::query()->where('status', VaultStatus::Missing)->count();
            $notes = Note::query()->count();

            Note::query()->delete();
            Vault::query()->delete();

            $this->settings->forget(SettingKey::CurrentVault);

            return new RegistryResetResult($vaults, $missing, $notes);
        });
    }

    /**
     * The current vault, or null. A stale UUID (pointing at a deleted
     * vault) is forgotten. A missing current vault stays current; the UI
     * shows a warning.
     */
    public function current(): ?Vault
    {
        $uuid = $this->settings->string(SettingKey::CurrentVault);

        if ($uuid === null) {
            return null;
        }

        $vault = Vault::query()->where('uuid', $uuid)->first();

        if ($vault === null) {
            $this->settings->forget(SettingKey::CurrentVault);

            return null;
        }

        return $this->refreshStatus($vault);
    }

    /**
     * Reconciles `status` from the filesystem, persisting it only when it
     * changes.
     */
    public function refreshStatus(Vault $vault): Vault
    {
        $expected = $this->files->isDirectory($vault->path) ? VaultStatus::Active : VaultStatus::Missing;

        if ($vault->status !== $expected) {
            $vault->update(['status' => $expected]);
        }

        return $vault;
    }

    public function canTrash(): bool
    {
        return $this->files->canTrash();
    }

    /**
     * The basename of a (not-yet-registered) folder, for pre-filling the
     * "Add existing folder" dialog's name field.
     */
    public function suggestedNameFor(string $path): string
    {
        $trimmed = rtrim(str_replace('\\', '/', $path), '/');
        $position = strrpos($trimmed, '/');

        return $position === false ? $trimmed : substr($trimmed, $position + 1);
    }

    /**
     * @return array{uuid: string, name: string, description: ?string, path: string, relative_path: ?string, status: string, is_current: bool, is_encrypted: bool}
     */
    public function present(Vault $vault): array
    {
        return [
            'uuid' => $vault->uuid,
            'name' => $vault->name,
            'description' => $vault->description,
            'path' => $vault->path,
            'relative_path' => $vault->relative_path,
            'status' => $vault->status->value,
            'is_current' => $this->settings->string(SettingKey::CurrentVault) === $vault->uuid,
            'is_encrypted' => $vault->is_encrypted,
        ];
    }

    /**
     * @return list<array{uuid: string, name: string, description: ?string, path: string, relative_path: ?string, status: string, is_current: bool, is_encrypted: bool}>
     */
    public function summaries(): array
    {
        try {
            return array_map(fn (Vault $vault): array => $this->present($vault), $this->all());
        } catch (QueryException $e) {
            report($e);

            return [];
        }
    }

    /**
     * @throws VaultOperationException
     */
    private function assertValidName(string $name): void
    {
        try {
            $this->paths->assertValidFolderName($name);
        } catch (InvalidStorageRootException) {
            throw VaultOperationException::invalidName();
        }
    }

    /**
     * Whether no registered vault is named $name (case-insensitive).
     */
    public function nameAvailable(string $name): bool
    {
        return $this->firstNameMatch($name, null) === null;
    }

    /**
     * The first registered vault whose path is the same as, inside, or
     * containing $path; null when none overlaps.
     */
    public function overlappingVault(string $path): ?Vault
    {
        return $this->firstOverlap($path, null);
    }

    /**
     * @throws VaultOperationException
     */
    private function assertNameAvailable(string $name, ?Vault $except): void
    {
        $match = $this->firstNameMatch($name, $except);

        if ($match !== null) {
            throw VaultOperationException::duplicateName($name);
        }
    }

    private function firstNameMatch(string $name, ?Vault $except): ?Vault
    {
        $lower = mb_strtolower($name);

        foreach (Vault::query()->get() as $vault) {
            if ($except !== null && $vault->is($except)) {
                continue;
            }

            if (mb_strtolower($vault->name) === $lower) {
                return $vault;
            }
        }

        return null;
    }

    /**
     * @throws VaultOperationException
     */
    private function assertNoOverlap(string $path, string $field, ?Vault $except = null): void
    {
        $overlap = $this->firstOverlap($path, $except);

        if ($overlap !== null) {
            throw VaultOperationException::overlapsVault($overlap->name, $field);
        }
    }

    private function firstOverlap(string $path, ?Vault $except): ?Vault
    {
        foreach (Vault::query()->get() as $vault) {
            if ($except !== null && $vault->is($except)) {
                continue;
            }

            if ($this->files->isSameOrInside($path, $vault->path) || $this->files->isSameOrInside($vault->path, $path)) {
                return $vault;
            }
        }

        return null;
    }

    /**
     * Replaces the last '/'-separated segment of a `relative_path` with
     * $name (e.g. `Team/Work` -> `Team/Office`).
     */
    private function replaceLastSegment(string $relativePath, string $name): string
    {
        $position = strrpos($relativePath, '/');

        return $position === false ? $name : substr($relativePath, 0, $position + 1).$name;
    }

    /**
     * @throws VaultOperationException
     */
    private function assertSafeFolder(string $path, string $field): void
    {
        if ($this->files->isFilesystemRoot($path)) {
            throw VaultOperationException::unsafeFolder($field);
        }

        if ($this->files->isSameOrInside($this->paths->rootPath(), $path)) {
            throw VaultOperationException::unsafeFolder($field);
        }

        $documents = $this->directories->documentsPath();

        if ($documents !== null && $this->files->isSameOrInside($documents, $path)) {
            throw VaultOperationException::unsafeFolder($field);
        }
    }
}
