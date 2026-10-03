<?php

namespace App\Services;

use App\Enums\IndexMode;
use App\Enums\VaultStatus;
use App\Exceptions\EncryptionException;
use App\Exceptions\InvalidStorageRootException;
use App\Exceptions\NoteOperationException;
use App\Exceptions\VaultOperationException;
use App\Models\Note;
use App\Models\Vault;
use App\Models\VaultEncryption;
use App\Support\ConversionLock;
use App\Support\ConversionResult;
use App\Support\VaultKey;
use Illuminate\Database\DatabaseManager;

/**
 * Encrypts an existing vault and removes encryption again (ADR
 * `vault-encryption-conversion`): a converted copy is built in a staging
 * folder beside the vault, verified, and swapped in with two directory
 * renames inside the DB transaction that records the new state. At any
 * instant the vault folder is either the full original or the full converted
 * vault; `VaultRecoveryService` repairs whatever a crash leaves behind.
 *
 * After a committed encryption the replaced unencrypted original is deleted
 * permanently (E7). No exception message, log line or return value carries a
 * decrypted name; the problem lists returned by a refused conversion name
 * files that are already plaintext on disk.
 */
final class VaultConversionService
{
    /**
     * At most this many problems are listed.
     */
    public const MAX_PROBLEMS = 20;

    public function __construct(
        private readonly VaultService $vaults,
        private readonly VaultIndexService $index,
        private readonly VaultEncryptionService $vaultEncryption,
        private readonly EncryptionService $crypto,
        private readonly VaultKeyService $keys,
        private readonly EncryptedNoteService $notes,
        private readonly FileStorageService $files,
        private readonly FileHashService $hashes,
        private readonly StoragePathService $paths,
        private readonly EncryptionHeaderService $headers,
        private readonly VaultRecoveryService $recovery,
        private readonly DatabaseManager $database,
    ) {}

    /**
     * Why $vault can't be encrypted right now: an empty list means it can.
     * Looks at the whole folder without filtering (E7): anything that isn't
     * a plain Markdown note is refused, because the unencrypted original is
     * deleted after the conversion.
     *
     * @return list<string>
     */
    public function preflight(Vault $vault): array
    {
        $problems = [];
        $inventory = $this->files->inventory($vault->path);
        $total = 0;

        if (! $this->files->isWritableDirectory(dirname($vault->path))) {
            $problems[] = "MDVault can't write next to the vault folder.";
        }

        foreach ($inventory['unreadable'] as $directory) {
            $problems[] = ($directory === '' ? 'The vault folder' : $directory).": can't be read.";
        }

        foreach ($inventory['symlinks'] as $path) {
            $problems[] = "{$path}: shortcuts and links aren't supported.";
        }

        foreach ($inventory['directories'] as $path) {
            $name = basename($path);

            if (str_starts_with($name, '.') || strcasecmp($name, 'node_modules') === 0) {
                $problems[] = "{$path}: this kind of folder can't be encrypted.";
            } elseif (! $this->isEncryptableName($name)) {
                $problems[] = "{$path}: the folder name isn't supported.";
            }
        }

        /** @var array<string, string> $seen */
        $seen = [];

        foreach ($inventory['files'] as $file) {
            $path = $file['path'];
            $name = basename($path);
            $total += $file['size'];

            if ($this->isInsideSkippedFolder($path)) {
                continue;
            }

            if (str_starts_with($name, FileStorageService::SAVE_TEMP_PREFIX)) {
                $age = $file['mtime'] === null ? 0 : time() - $file['mtime'];

                if ($age < VaultIndexService::ORPHAN_MIN_AGE_SECONDS) {
                    $problems[] = "{$path}: a save is still in progress. Try again in a minute.";
                }

                continue;
            }

            if (str_starts_with($name, '.')) {
                $problems[] = "{$path}: hidden files can't be encrypted.";

                continue;
            }

            if (strcasecmp(pathinfo($name, PATHINFO_EXTENSION), 'md') !== 0) {
                $problems[] = "{$path}: only Markdown notes can be encrypted.";

                continue;
            }

            $stem = $this->stemOf($name);

            if ($file['size'] > EncryptionService::MAX_NOTE_PLAINTEXT_BYTES) {
                $problems[] = "{$path}: the note is too large to encrypt.";
            } elseif (! $this->isEncryptableName($stem)) {
                $problems[] = "{$path}: the note name isn't supported.";
            } else {
                $key = mb_strtolower(dirname($path).'/'.$stem);

                if (isset($seen[$key])) {
                    $problems[] = "{$path}: another note in this folder differs only by capital letters.";
                }

                $seen[$key] = $path;
            }
        }

        $free = $this->files->freeSpace(dirname($vault->path));

        if ($free !== null && $free < $total + 8 * 1024 * 1024) {
            $problems[] = 'There is not enough free disk space to build the encrypted copy.';
        }

        return $this->capped($problems);
    }

    /**
     * Encrypts $vault under $password. On success the vault is unlocked
     * (the result carries the token) and the unencrypted original has been
     * deleted. On any failure before the commit the vault is exactly as it
     * was.
     *
     * @throws EncryptionException
     * @throws VaultOperationException
     */
    public function encrypt(Vault $vault, #[\SensitiveParameter] string $password): ConversionResult
    {
        $this->recovery->recover($vault);
        $vault->refresh();
        $this->assertActive($vault);

        if ($vault->is_encrypted) {
            throw EncryptionException::alreadyEncrypted();
        }

        if (! $this->files->isWritableDirectory(dirname($vault->path))) {
            throw EncryptionException::parentNotWritable();
        }

        $release = $this->recovery->lock($vault);

        if ($release === null) {
            throw EncryptionException::conversionFailed();
        }

        $staging = $this->recovery->stagingPath($vault, true);
        $original = $this->recovery->originalPath($vault);
        $key = null;

        try {
            $problems = $this->preflight($vault);

            if ($problems !== []) {
                throw EncryptionException::unsupportedContents($problems);
            }

            $this->reconcile($vault);

            [$header, $key] = $this->crypto->newVaultKey($password);
            $headerBytes = $this->crypto->encodeHeader($header);
            $before = $this->snapshot($vault->path);

            $this->clearStaging($staging);

            [$notes, $folders] = $this->buildEncryptedStaging($vault, $key, $headerBytes, $staging);
            $this->verifyEncryptedStaging($vault, $key, $staging, $notes, $folders, $before);

            $headerHash = $this->hashes->hashString($headerBytes);

            $this->commit($vault, $staging, $original, function () use ($vault, $header, $headerHash, $notes): void {
                $this->headers->adopt($vault, $header, $headerHash);

                foreach ($notes as $uuid => $note) {
                    Note::query()->where('uuid', $uuid)->where('vault_id', $vault->id)->update([
                        ...$this->index->newNoteAttributes($note['path'], $note['size'], $note['hash'], encrypted: true),
                    ]);
                }
            }, fn () => $this->assertSafeToSwap($vault, $release, $before));

            $warning = $this->deleteOriginal($original);
            $this->reindex($vault);

            // The conversion is committed: a failure to store the key only
            // means the user must unlock the vault with the new password.
            try {
                $token = $this->keys->store($vault, $key);
            } catch (\Throwable) {
                $token = null;
            }

            return new ConversionResult($token, count($notes), $warning);
        } catch (EncryptionException|VaultOperationException $e) {
            $this->clearStagingQuietly($staging);

            throw $e;
        } catch (\Throwable) {
            $this->clearStagingQuietly($staging);
            $this->reportGeneric('encrypt');

            throw EncryptionException::conversionFailed();
        } finally {
            $release();
            $vault->refresh();
        }
    }

    /**
     * Removes the encryption from $vault (E8). Needs the password, not just
     * an unlocked key. The notes become plain `.md` files again and the
     * encrypted original is deleted after the commit.
     *
     * @throws EncryptionException
     * @throws VaultOperationException
     */
    public function decrypt(Vault $vault, #[\SensitiveParameter] string $password): ConversionResult
    {
        $this->recovery->recover($vault);
        $vault->refresh();
        $this->assertActive($vault);

        if (! $vault->is_encrypted) {
            throw EncryptionException::notEncrypted();
        }

        [$header] = $this->vaultEncryption->readHeader($vault);
        $key = $this->crypto->unlock($header, $password);

        if (! $this->files->isWritableDirectory(dirname($vault->path))) {
            throw EncryptionException::parentNotWritable();
        }

        $release = $this->recovery->lock($vault);

        if ($release === null) {
            throw EncryptionException::conversionFailed();
        }

        $staging = $this->recovery->stagingPath($vault, false);
        $original = $this->recovery->originalPath($vault);

        try {
            $this->reconcile($vault);
            $this->assertNoForeignFiles($vault);

            $before = $this->snapshot($vault->path);
            $this->clearStaging($staging);

            [$notes, $folders] = $this->buildPlainStaging($vault, $key, $staging);
            $this->verifyPlainStaging($vault, $staging, $notes, $before);

            $this->commit($vault, $staging, $original, function () use ($vault, $notes): void {
                VaultEncryption::query()->where('vault_id', $vault->id)->delete();
                $vault->forceFill(['is_encrypted' => false])->save();

                foreach ($notes as $uuid => $note) {
                    Note::query()->where('uuid', $uuid)->where('vault_id', $vault->id)->update([
                        ...$this->index->newNoteAttributes($note['path'], $note['size'], $note['hash']),
                    ]);
                }
            }, fn () => $this->assertSafeToSwap($vault, $release, $before));

            $this->keys->forget($vault);

            $warning = $this->deleteOriginal($original);
            $this->reindex($vault);

            return new ConversionResult(null, count($notes), $warning);
        } catch (EncryptionException|VaultOperationException $e) {
            $this->clearStagingQuietly($staging);

            throw $e;
        } catch (\Throwable) {
            $this->clearStagingQuietly($staging);
            $this->reportGeneric('decrypt');

            throw EncryptionException::conversionFailed();
        } finally {
            $release();
            $vault->refresh();
        }
    }

    /**
     * Builds the encrypted copy: the key file, every folder as `<id>/` with
     * its name file, and every note as `<id>.mdenc`.
     *
     * @return array{0: array<string, array{path: string, file_id: string, name: string, hash: string, size: int, plain_hash: string}>, 1: array<string, array{id: string, name: string}>}
     */
    private function buildEncryptedStaging(Vault $vault, VaultKey $key, string $headerBytes, string $staging): array
    {
        if (! $this->files->makeDirectory($staging) || ! $this->files->createFile($this->headers->headerPath($staging), $headerBytes)) {
            throw EncryptionException::conversionFailed();
        }

        $inventory = $this->files->inventory($vault->path);

        /** @var array<string, string> $diskOf logical directory => on-disk directory */
        $diskOf = ['' => ''];

        /** @var array<string, array{id: string, name: string}> $folders on-disk directory => id and name */
        $folders = [];

        foreach ($inventory['directories'] as $directory) {
            $parent = dirname($directory) === '.' ? '' : dirname($directory);
            $id = $this->crypto->newFileId();
            $disk = $diskOf[$parent] === '' ? $id : $diskOf[$parent].'/'.$id;
            $name = basename($directory);

            $absolute = $this->files->joinRelative($staging, $disk);

            if (! $this->files->makeDirectory($absolute)
                || ! $this->files->createFile($this->files->joinRelative($absolute, EncryptedNoteService::FOLDER_NAME_FILE), $this->crypto->encryptFolderName($key, $id, $name))) {
                throw EncryptionException::conversionFailed();
            }

            $diskOf[$directory] = $disk;
            $folders[$disk] = ['id' => $id, 'name' => $name];
        }

        $notes = [];

        foreach ($vault->notes()->orderBy('relative_path')->get() as $row) {
            $source = $this->files->joinRelative($vault->path, $row->relative_path);
            $bytes = $this->files->read($source);

            if ($bytes === null || $this->hashes->hashString($bytes) !== $row->file_hash) {
                throw EncryptionException::vaultChanged();
            }

            $directory = dirname($row->relative_path) === '.' ? '' : dirname($row->relative_path);

            if (! isset($diskOf[$directory])) {
                throw EncryptionException::vaultChanged();
            }

            $stem = $this->stemOf(basename($row->relative_path));
            $fileId = $this->crypto->newFileId();
            $cipher = $this->crypto->encryptNote($key, $fileId, $stem, $bytes);
            $relative = ($diskOf[$directory] === '' ? '' : $diskOf[$directory].'/').$fileId.'.mdenc';

            if (! $this->files->createFile($this->files->joinRelative($staging, $relative), $cipher)) {
                throw EncryptionException::conversionFailed();
            }

            $notes[$row->uuid] = [
                'path' => $relative,
                'file_id' => $fileId,
                'name' => $stem,
                'hash' => $this->hashes->hashString($cipher),
                'size' => strlen($cipher),
                'plain_hash' => $row->file_hash,
            ];
        }

        return [$notes, $folders];
    }

    /**
     * Re-reads and decrypts everything staged and compares it with the
     * source, then re-checks that the source did not change meanwhile.
     *
     * @param  array<string, array{path: string, file_id: string, name: string, hash: string, size: int, plain_hash: string}>  $notes
     * @param  array<string, array{id: string, name: string}>  $folders
     * @param  array<string, string>  $before
     */
    private function verifyEncryptedStaging(Vault $vault, VaultKey $key, string $staging, array $notes, array $folders, array $before): void
    {
        foreach ($notes as $note) {
            $bytes = $this->files->read($this->files->joinRelative($staging, $note['path']));

            if ($bytes === null || $this->hashes->hashString($bytes) !== $note['hash']) {
                throw EncryptionException::conversionFailed();
            }

            try {
                $plain = $this->crypto->decryptNote($key, $note['file_id'], $bytes);
            } catch (EncryptionException) {
                throw EncryptionException::conversionFailed();
            }

            if ($plain['name'] !== $note['name'] || $this->hashes->hashString($plain['content']) !== $note['plain_hash']) {
                throw EncryptionException::conversionFailed();
            }
        }

        foreach ($folders as $disk => $folder) {
            $bytes = $this->files->read($this->files->joinRelative($this->files->joinRelative($staging, $disk), EncryptedNoteService::FOLDER_NAME_FILE));

            try {
                if ($bytes === null || $this->crypto->decryptFolderName($key, $folder['id'], $bytes) !== $folder['name']) {
                    throw EncryptionException::conversionFailed();
                }
            } catch (EncryptionException) {
                throw EncryptionException::conversionFailed();
            }
        }

        $this->assertUnchanged($vault, $before);

        foreach ($vault->notes()->get() as $row) {
            if ($this->hashes->hashFile($this->files->joinRelative($vault->path, $row->relative_path)) !== $row->file_hash) {
                throw EncryptionException::vaultChanged();
            }
        }
    }

    /**
     * Builds the plain copy of an encrypted vault: folders by their
     * decrypted names and notes as `<name>.md` (duplicates get " (2)").
     *
     * @return array{0: array<string, array{path: string, hash: string, size: int}>, 1: list<string>}
     */
    private function buildPlainStaging(Vault $vault, VaultKey $key, string $staging): array
    {
        $namespace = $this->notes->namespace($vault, $key);

        $unreadable = [];

        foreach ($namespace->notes as $note) {
            if ($note['state'] === 'unreadable') {
                $unreadable[] = $note['display'];
            }
        }

        if ($unreadable !== []) {
            throw EncryptionException::unreadableNotes($this->capped($unreadable));
        }

        if (! $this->files->makeDirectory($staging)) {
            throw EncryptionException::conversionFailed();
        }

        $disks = array_keys($namespace->folders);
        usort($disks, 'strcmp');

        /** @var array<string, string> $target on-disk folder => plain relative folder */
        $target = ['' => ''];

        /** @var array<string, array<string, true>> $taken */
        $taken = [];

        $folderTargets = [];

        foreach ($disks as $disk) {
            $folder = $namespace->folders[$disk];
            $parentTarget = $target[$folder['parent']] ?? null;

            if ($parentTarget === null) {
                throw EncryptionException::conversionFailed();
            }

            $taken[$folder['parent']] ??= [];
            $name = $this->uniqueName($this->usableName($folder['name']), $taken[$folder['parent']]);
            $relative = $parentTarget === '' ? $name : $parentTarget.'/'.$name;

            if (! $this->files->makeDirectory($this->files->joinRelative($staging, $relative))) {
                throw EncryptionException::conversionFailed();
            }

            $target[$disk] = $relative;
            $folderTargets[] = $relative;
        }

        $uuids = array_keys($namespace->notes);
        usort($uuids, fn (string $a, string $b): int => strcmp($namespace->notes[$a]['disk'], $namespace->notes[$b]['disk']));

        /** @var array<string, array<string, true>> $takenNotes */
        $takenNotes = [];
        $notes = [];

        foreach ($uuids as $uuid) {
            $note = $namespace->notes[$uuid];
            $folderTarget = $target[$note['folder']] ?? null;

            if ($folderTarget === null) {
                throw EncryptionException::conversionFailed();
            }

            $bytes = $this->files->read($this->files->joinRelative($vault->path, $note['disk']));

            if ($bytes === null || $this->hashes->hashString($bytes) !== $note['hash']) {
                throw EncryptionException::vaultChanged();
            }

            try {
                $plain = $this->crypto->decryptNote($key, $note['file_id'], $bytes);
            } catch (EncryptionException) {
                throw EncryptionException::unreadableNotes([$note['display']]);
            }

            $takenNotes[$note['folder']] ??= [];
            $name = $this->uniqueName($this->usableName($note['name']), $takenNotes[$note['folder']]);
            $relative = ($folderTarget === '' ? '' : $folderTarget.'/').$name.'.md';

            if (! $this->files->createFile($this->files->joinRelative($staging, $relative), $plain['content'])) {
                throw EncryptionException::conversionFailed();
            }

            $notes[$uuid] = [
                'path' => $relative,
                'hash' => $this->hashes->hashString($plain['content']),
                'size' => strlen($plain['content']),
                'source_hash' => $note['hash'],
                'source' => $note['disk'],
            ];
        }

        return [$notes, $folderTargets];
    }

    /**
     * @param  array<string, array{path: string, hash: string, size: int}>  $notes
     * @param  array<string, string>  $before
     */
    private function verifyPlainStaging(Vault $vault, string $staging, array $notes, array $before): void
    {
        foreach ($notes as $note) {
            $path = $this->files->joinRelative($staging, $note['path']);

            if ($this->hashes->hashFile($path) !== $note['hash'] || $this->files->size($path) !== $note['size']) {
                throw EncryptionException::conversionFailed();
            }
        }

        $this->assertUnchanged($vault, $before);

        foreach ($vault->notes()->get() as $row) {
            if ($this->hashes->hashFile($this->files->joinRelative($vault->path, $row->relative_path)) !== $row->file_hash) {
                throw EncryptionException::vaultChanged();
            }
        }
    }

    /**
     * Records the new state and swaps the folders, all inside one DB
     * transaction: the two renames are the last step, so a failure in the
     * DB writes happens before anything moved, and a failed second rename
     * renames the original back. If the commit itself fails after the swap,
     * the swap is undone (and, if even that fails, the next recovery does
     * it).
     *
     * @param  \Closure(): void  $record
     * @param  \Closure(): void  $guard  runs after the DB writes, right before the first rename; throws to abort
     */
    private function commit(Vault $vault, string $staging, string $original, \Closure $record, \Closure $guard): void
    {
        $swapped = false;

        try {
            $this->database->connection()->transaction(function () use ($vault, $staging, $original, $record, $guard, &$swapped): void {
                $record();
                $guard();

                if (! $this->files->renameDirectory($vault->path, $original)) {
                    throw EncryptionException::swapFailed();
                }

                if (! $this->files->renameDirectory($staging, $vault->path)) {
                    $this->files->renameDirectory($original, $vault->path);

                    throw EncryptionException::swapFailed();
                }

                $swapped = true;
            });
        } catch (\Throwable $e) {
            $vault->refresh();

            if ($swapped) {
                $this->undoSwap($vault, $staging, $original);
            }

            if ($e instanceof EncryptionException) {
                throw $e;
            }

            $this->reportGeneric('commit');

            throw EncryptionException::conversionFailed();
        }
    }

    /**
     * The last check before the first rename: this conversion must still own
     * its lock (a lost lock lets recovery run underneath it), and the vault
     * folder must be exactly what was converted (a write that landed after
     * the final verify would be lost with the replaced folder).
     *
     * @param  array<string, string>  $before
     *
     * @throws EncryptionException
     */
    private function assertSafeToSwap(Vault $vault, ConversionLock $lock, array $before): void
    {
        if (! $lock->isHeld()) {
            throw EncryptionException::conversionFailed();
        }

        $this->assertUnchanged($vault, $before);
    }

    private function undoSwap(Vault $vault, string $staging, string $original): void
    {
        if ($this->files->renameDirectory($vault->path, $staging) && ! $this->files->renameDirectory($original, $vault->path)) {
            // Put the converted folder back; the next recovery repairs it.
            $this->files->renameDirectory($staging, $vault->path);
        }
    }

    /**
     * Permanently deletes the replaced original (E7). Returns a warning
     * message when it could not be removed; recovery retries later.
     */
    private function deleteOriginal(string $original): ?string
    {
        if ($this->files->deleteStagingDirectory($original)) {
            return null;
        }

        return EncryptionException::cleanupIncomplete(basename($original))->getMessage();
    }

    private function reindex(Vault $vault): void
    {
        try {
            $this->index->reconcile($vault->refresh(), IndexMode::Full);
        } catch (\Throwable) {
            $this->reportGeneric('reindex');
        }
    }

    /**
     * @throws EncryptionException
     */
    private function reconcile(Vault $vault): void
    {
        try {
            $result = $this->index->reconcile($vault, IndexMode::Full);
        } catch (NoteOperationException) {
            throw EncryptionException::conversionFailed();
        }

        if ($result->stale) {
            throw EncryptionException::vaultChanged();
        }
    }

    /**
     * @throws VaultOperationException
     */
    private function assertActive(Vault $vault): void
    {
        $this->vaults->refreshStatus($vault);

        if ($vault->status === VaultStatus::Missing) {
            throw VaultOperationException::folderMissing($vault->path, 'vault');
        }
    }

    /**
     * An encrypted vault may hold only its key file, `folder.mdenc` name
     * files, hex folders and registered notes (and old save temp files).
     * Anything else would be deleted with the original, so it is refused.
     *
     * @throws EncryptionException
     */
    private function assertNoForeignFiles(Vault $vault): void
    {
        $inventory = $this->files->inventory($vault->path);
        $registered = $vault->notes()->pluck('relative_path')->flip()->all();
        $problems = [];

        foreach ($inventory['unreadable'] as $directory) {
            $problems[] = ($directory === '' ? 'The vault folder' : $directory).": can't be read.";
        }

        foreach ($inventory['symlinks'] as $path) {
            $problems[] = "{$path}: shortcuts and links aren't supported.";
        }

        foreach ($inventory['directories'] as $path) {
            if ($this->index->encryptedFolderDirectories([$path]) === []) {
                $problems[] = "{$path}: isn't an MDVault folder.";
            }
        }

        foreach ($inventory['files'] as $file) {
            $path = $file['path'];
            $name = basename($path);

            if ($path === EncryptionHeaderService::HEADER_FILENAME || isset($registered[$path])) {
                continue;
            }

            if ($name === EncryptedNoteService::FOLDER_NAME_FILE && $this->index->encryptedFolderDirectories([dirname($path)]) !== []) {
                continue;
            }

            if (str_starts_with($name, FileStorageService::SAVE_TEMP_PREFIX) && $file['mtime'] !== null && time() - $file['mtime'] >= VaultIndexService::ORPHAN_MIN_AGE_SECONDS) {
                continue;
            }

            $problems[] = "{$path}: isn't an MDVault file.";
        }

        if ($problems !== []) {
            throw EncryptionException::foreignFiles($this->capped($problems));
        }
    }

    /**
     * A comparable picture of the vault folder: every entry with its size
     * and modification time.
     *
     * @return array<string, string>
     */
    private function snapshot(string $root): array
    {
        $inventory = $this->files->inventory($root);
        $picture = [];

        foreach ($inventory['files'] as $file) {
            $picture['f:'.$file['path']] = $file['size'].'|'.($file['mtime'] ?? 'null');
        }

        foreach ($inventory['directories'] as $directory) {
            $picture['d:'.$directory] = '';
        }

        foreach ($inventory['symlinks'] as $link) {
            $picture['l:'.$link] = '';
        }

        return $picture;
    }

    /**
     * @param  array<string, string>  $before
     *
     * @throws EncryptionException
     */
    private function assertUnchanged(Vault $vault, array $before): void
    {
        if ($this->snapshot($vault->path) !== $before) {
            throw EncryptionException::vaultChanged();
        }
    }

    /**
     * @throws EncryptionException
     */
    private function clearStaging(string $staging): void
    {
        if ($this->files->exists($staging) && ! $this->files->deleteStagingDirectory($staging)) {
            throw EncryptionException::conversionFailed();
        }
    }

    private function clearStagingQuietly(string $staging): void
    {
        try {
            if ($this->files->isDirectory($staging)) {
                $this->files->deleteStagingDirectory($staging);
            }
        } catch (\Throwable) {
            // Recovery removes a leftover staging folder later.
        }
    }

    /**
     * Reports a conversion failure without the original exception: a
     * `QueryException` carries bindings, which in a decryption are decrypted
     * names.
     */
    private function reportGeneric(string $step): void
    {
        report(new \RuntimeException("Vault conversion failed at step: {$step}."));
    }

    private function isInsideSkippedFolder(string $path): bool
    {
        $segments = explode('/', $path);
        array_pop($segments);

        foreach ($segments as $segment) {
            if (str_starts_with($segment, '.') || strcasecmp($segment, 'node_modules') === 0) {
                return true;
            }
        }

        return false;
    }

    private function stemOf(string $filename): string
    {
        return preg_replace('/\.md$/i', '', $filename, 1) ?? $filename;
    }

    private function isEncryptableName(string $name): bool
    {
        if ($name === '' || strlen($name) > EncryptionService::MAX_NAME_BYTES || ! mb_check_encoding($name, 'UTF-8')) {
            return false;
        }

        // The same rules new notes and folders in an encrypted vault follow,
        // so every converted name can be renamed and converted back.
        try {
            $this->paths->assertValidNoteSegment($name);
        } catch (InvalidStorageRootException) {
            return false;
        }

        return true;
    }

    /**
     * A decrypted name that is safe to use as a file or folder name here,
     * or "Untitled".
     */
    private function usableName(string $name): string
    {
        try {
            $this->paths->assertValidNoteSegment($name);
        } catch (InvalidStorageRootException) {
            return 'Untitled';
        }

        return $name;
    }

    /**
     * $name, or "$name (2)", "$name (3)" and so on, so it is unique
     * (case-insensitively) among $taken, which is updated.
     *
     * @param  array<string, true>  $taken
     */
    private function uniqueName(string $name, array &$taken): string
    {
        $candidate = $name;
        $counter = 2;

        while (isset($taken[mb_strtolower($candidate)])) {
            $candidate = "{$name} ({$counter})";
            $counter++;
        }

        $taken[mb_strtolower($candidate)] = true;

        return $candidate;
    }

    /**
     * @param  list<string>  $problems
     * @return list<string>
     */
    private function capped(array $problems): array
    {
        if (count($problems) <= self::MAX_PROBLEMS) {
            return $problems;
        }

        $extra = count($problems) - self::MAX_PROBLEMS;

        return [...array_slice($problems, 0, self::MAX_PROBLEMS), "…and {$extra} more."];
    }
}
