<?php

namespace App\Services;

use App\Enums\FileReplaceResult;
use App\Enums\NoteSaveMode;
use App\Enums\SettingKey;
use App\Exceptions\EncryptionException;
use App\Exceptions\InvalidStorageRootException;
use App\Exceptions\NoteOperationException;
use App\Exceptions\NoteSaveConflictException;
use App\Exceptions\VaultLockedException;
use App\Models\Note;
use App\Models\Vault;
use App\Support\FrontmatterEdit;
use App\Support\NoteSaveResult;
use App\Support\VaultKey;
use App\Support\VaultNamespace;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;

/**
 * Every note and folder operation for encrypted vaults (ADR
 * `encrypted-vault-storage-layout`). `NoteService` delegates here at method
 * entry when a vault is encrypted, so the plaintext paths stay untouched.
 *
 * On disk everything is opaque: notes are `<32hex>.mdenc` (the real name is
 * encrypted inside the file), folders are `<32hex>/` with a `folder.mdenc`
 * holding the encrypted name. The client sees logical paths exactly as for a
 * plaintext vault; this service maps them to opaque ones through a
 * `VaultNamespace` built per request from the decrypted names. Every write
 * keeps the plaintext service's safety semantics: exclusive creates, a
 * ciphertext-hash guard on replaces, and compensation that only renames back
 * or removes a file this call created.
 *
 * No exception message, log line or return value of this class puts a
 * decrypted name or path anywhere but the namespace-derived props of an
 * unlocked vault.
 */
final class EncryptedNoteService
{
    public const FOLDER_NAME_FILE = 'folder.mdenc';

    private ?VaultNamespace $memo = null;

    private ?string $memoVault = null;

    public function __construct(
        private readonly FileStorageService $files,
        private readonly FileHashService $hashes,
        private readonly StoragePathService $paths,
        private readonly MarkdownService $markdown,
        private readonly SettingsService $settings,
        private readonly EncryptionService $encryption,
        private readonly VaultKeyService $keys,
        private readonly VaultIndexService $index,
        private readonly DatabaseManager $database,
    ) {}

    /**
     * Validates a note name; strips one trailing ".md"; returns the stem.
     *
     * @throws NoteOperationException
     */
    public function assertValidNoteName(string $name): string
    {
        $stem = preg_replace('/\.md$/i', '', $name, 1) ?? $name;

        $this->assertValidSegment($stem);

        return $stem;
    }

    /**
     * @throws NoteOperationException
     */
    public function assertValidSegment(string $name): void
    {
        try {
            $this->paths->assertValidNoteSegment($name);
        } catch (InvalidStorageRootException) {
            throw NoteOperationException::invalidName();
        }

        if (strlen($name) > EncryptionService::MAX_NAME_BYTES) {
            throw NoteOperationException::invalidName();
        }
    }

    /**
     * The decrypted name map of $vault, memoized while the registry and the
     * folders on disk are unchanged.
     */
    public function namespace(Vault $vault, VaultKey $key): VaultNamespace
    {
        $scan = $this->files->scan(
            $vault->path,
            fn (string $relative, string $name, bool $isDir): bool => $isDir
                ? ! $this->index->isIgnoredName($name, true)
                : $name === self::FOLDER_NAME_FILE,
        );

        $directories = $this->index->encryptedFolderDirectories($scan['directories']);

        $nameFiles = [];

        foreach ($scan['files'] as $file) {
            $dir = $this->parentOf($file['path']);

            if ($dir !== '' && in_array($dir, $directories, true)) {
                $nameFiles[$dir] = $file;
            }
        }

        $rows = $vault->notes()->get(['uuid', 'relative_path', 'file_hash', 'file_size']);

        $fingerprint = $this->hashes->hashString(json_encode([
            $key->keyId,
            $directories,
            array_map(fn (array $file): array => [$file['size'], $file['mtime']], $nameFiles),
            $rows->map(fn (Note $note): array => [$note->uuid, $note->relative_path, $note->file_hash])->all(),
        ], JSON_THROW_ON_ERROR));

        if ($this->memo !== null && $this->memoVault === $vault->uuid && $this->memo->fingerprint === $fingerprint) {
            return $this->memo;
        }

        $folders = [];

        foreach ($directories as $directory) {
            $folderId = $this->basename($directory);
            $parent = $this->parentOf($directory);
            $name = isset($nameFiles[$directory]) ? $this->readFolderName($vault, $key, $directory, $folderId) : null;
            $named = $name !== null;
            $name ??= 'Unnamed folder ('.substr($folderId, 0, 8).')';
            $parentLogical = $parent === '' ? '' : ($folders[$parent]['logical'] ?? null);

            if ($parentLogical === null) {
                continue;
            }

            $folders[$directory] = [
                'name' => $name,
                'logical' => $parentLogical === '' ? $name : $parentLogical.'/'.$name,
                'parent' => $parent,
                'named' => $named,
            ];
        }

        $notes = [];

        foreach ($rows as $row) {
            $disk = $this->parentOf($row->relative_path);
            $folderLogical = $disk === '' ? '' : ($folders[$disk]['logical'] ?? null);
            $absolute = $this->files->joinRelative($vault->path, $row->relative_path);

            if ($folderLogical === null || ! $this->files->isFile($absolute)) {
                continue;
            }

            $fileId = $this->fileIdOf($row->relative_path);
            $name = $fileId === null ? null : $this->readNoteName($key, $fileId, $absolute);
            $display = $name ?? 'Unreadable note ('.substr((string) $fileId, 0, 8).')';

            $notes[$row->uuid] = [
                'name' => $name ?? '',
                'display' => $display,
                'logical' => ($folderLogical === '' ? '' : $folderLogical.'/').$display.'.md',
                'disk' => $row->relative_path,
                'folder' => $disk,
                'file_id' => (string) $fileId,
                'state' => $name === null ? 'unreadable' : 'ok',
                'hash' => $row->file_hash,
                'size' => $row->file_size,
            ];
        }

        $this->memo = new VaultNamespace($folders, $notes, $directories, $fingerprint);
        $this->memoVault = $vault->uuid;

        return $this->memo;
    }

    /**
     * The logical path (with `.md`) of one note, decrypting only that note
     * and its folder chain. Null when the vault is locked or anything can't
     * be read. Used by the cheap background change check, so it never
     * touches the idle timer.
     */
    public function logicalPathFor(Vault $vault, Note $note): ?string
    {
        $key = $this->keys->keyFor($vault, false);

        if ($key === null) {
            return null;
        }

        $fileId = $this->fileIdOf($note->relative_path);
        $name = $fileId === null ? null : $this->readNoteName($key, $fileId, $this->files->joinRelative($vault->path, $note->relative_path));

        if ($name === null) {
            return null;
        }

        $segments = [];
        $disk = '';

        foreach (array_filter(explode('/', $this->parentOf($note->relative_path)), fn (string $s): bool => $s !== '') as $segment) {
            $disk = $disk === '' ? $segment : $disk.'/'.$segment;
            $segments[] = $this->readFolderName($vault, $key, $disk, $segment) ?? 'Unnamed folder ('.substr($segment, 0, 8).')';
        }

        return implode('/', [...$segments, $name.'.md']);
    }

    /**
     * @return array{tree: list<array<string, mixed>>, folders: list<string>, signature: ?string}
     *
     * @throws VaultLockedException
     */
    public function browse(Vault $vault, ?Note $open = null): array
    {
        $key = $this->keys->requireKey($vault);

        if (! $this->files->isDirectory($vault->path)) {
            return ['tree' => [], 'folders' => [''], 'signature' => null];
        }

        $ns = $this->namespace($vault, $key);

        $folders = array_values(array_unique(array_map(fn (array $f): string => $f['logical'], $ns->folders)));
        usort($folders, 'strnatcasecmp');

        $notesByFolder = [];

        foreach ($ns->notes as $uuid => $note) {
            $notesByFolder[$ns->logicalFolderOf($note['folder']) ?? ''][] = [
                'type' => 'note',
                'uuid' => $uuid,
                'title' => $note['display'],
                'path' => $note['logical'],
                'folder' => $ns->logicalFolderOf($note['folder']) ?? '',
            ];
        }

        $openPath = $open !== null ? ($ns->notes[$open->uuid]['logical'] ?? null) : null;

        return [
            'tree' => $this->buildChildren('', $folders, $notesByFolder, $openPath),
            'folders' => ['', ...$folders],
            'signature' => $this->index->treeSignatureFor($vault, $ns->directories),
        ];
    }

    /**
     * @throws NoteOperationException|VaultLockedException
     */
    public function create(Vault $vault, ?string $folder, string $name, ?string $timezone = null): Note
    {
        $key = $this->keys->requireKey($vault);
        $this->assertVaultAvailable($vault);
        $stem = $this->assertValidNoteName($name);

        $ns = $this->namespace($vault, $key);
        $folderDisk = $this->resolveFolder($ns, $folder, 'folder');

        if ($ns->noteNameTaken($folderDisk, $stem, null)) {
            throw NoteOperationException::encryptedNameTaken('name');
        }

        $source = $this->settings->boolean(SettingKey::EditorNewNoteTemplateEnabled)
            ? $this->markdown->renderNewNoteTemplate(
                (string) $this->settings->string(SettingKey::EditorNewNoteTemplate),
                $stem,
                CarbonImmutable::now($timezone ?? config('app.timezone')),
            )
            : '';

        return $this->createNoteFile($vault, $key, $folderDisk, $stem, $source, 'name');
    }

    /**
     * "Save mine as a new note": a never-overwriting copy of the edited
     * content next to the source (or at its place if it was deleted).
     *
     * @throws NoteOperationException|VaultLockedException
     */
    public function createCopy(Vault $vault, string $sourcePath, string $content, NoteSaveMode $mode, ?FrontmatterEdit $frontmatter): Note
    {
        $key = $this->keys->requireKey($vault);
        $this->assertVaultAvailable($vault);

        if (
            str_contains($sourcePath, '\\')
            || str_contains($sourcePath, "\0")
            || str_starts_with($sourcePath, '/')
            || preg_match('/^[A-Za-z]:/', $sourcePath) === 1
            || ! str_ends_with(mb_strtolower($sourcePath), '.md')
        ) {
            throw NoteOperationException::invalidFolder('source_path');
        }

        foreach (explode('/', $sourcePath) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw NoteOperationException::invalidFolder('source_path');
            }
        }

        $ns = $this->namespace($vault, $key);
        $logicalFolder = $this->parentOf($sourcePath);
        $stem = $this->assertValidNoteName($this->basename($sourcePath));

        $folderDisk = $ns->folderDisk($logicalFolder);

        if ($folderDisk === null) {
            // The source's folder is gone: the copy goes to the top level.
            $folderDisk = '';
        }

        $sourceUuid = $ns->noteUuidAt($sourcePath);
        $doc = $this->markdown->decode('');

        if ($sourceUuid !== null && $ns->notes[$sourceUuid]['state'] === 'ok') {
            $absolute = $this->files->joinRelative($vault->path, $ns->notes[$sourceUuid]['disk']);
            $current = $this->decryptFileAt($key, $absolute, $ns->notes[$sourceUuid]['file_id']);

            if ($current !== null && strlen($current['content']) <= NoteService::EDIT_LIMIT) {
                $decoded = $this->markdown->decode($current['content']);

                if ($decoded->validUtf8) {
                    $doc = $decoded;
                }
            }
        }

        $source = $mode === NoteSaveMode::Rich
            ? $this->markdown->composeRich($doc, $content, $frontmatter)
            : $this->markdown->composeSource($content);

        $bytes = $this->markdown->encode($source, $doc->eol, $doc->hasBom);

        if (strlen($bytes) > NoteService::EDIT_LIMIT) {
            throw NoteOperationException::contentTooLarge();
        }

        $truncated = mb_substr($stem, 0, 100);
        $candidates = [$stem, $truncated.' (my version)'];

        for ($n = 2; $n <= 20; $n++) {
            $candidates[] = $truncated." (my version {$n})";
        }

        foreach ($candidates as $candidate) {
            if (strlen($candidate) > EncryptionService::MAX_NAME_BYTES || $ns->noteNameTaken($folderDisk, $candidate, null)) {
                continue;
            }

            return $this->createNoteFile($vault, $key, $folderDisk, $candidate, $bytes, 'content');
        }

        throw NoteOperationException::encryptedCopyNameUnavailable();
    }

    /**
     * Renames a note by re-encrypting its file with the new name (same file,
     * same registry row, same UUID).
     *
     * @throws NoteOperationException|VaultLockedException
     */
    public function rename(Note $note, string $name): Note
    {
        $vault = $note->vault;
        $key = $this->keys->requireKey($vault);
        $this->assertVaultAvailable($vault);
        $stem = $this->assertValidNoteName($name);

        $ns = $this->namespace($vault, $key);
        $entry = $ns->notes[$note->uuid] ?? null;

        if ($entry === null) {
            throw NoteOperationException::encryptedFileMissing('name');
        }

        if ($entry['state'] !== 'ok') {
            throw NoteOperationException::encryptedUnreadable();
        }

        if ($stem === $entry['name']) {
            return $note;
        }

        if ($ns->noteNameTaken($entry['folder'], $stem, $note->uuid)) {
            throw NoteOperationException::encryptedNameTaken('name');
        }

        $absolute = $this->files->joinRelative($vault->path, $entry['disk']);
        $raw = $this->files->read($absolute);

        if ($raw === null) {
            throw NoteOperationException::encryptedMoveFailed('name');
        }

        $currentHash = $this->hashes->hashString($raw);
        $plain = $this->decryptBytes($key, $entry['file_id'], $raw);

        if ($plain === null) {
            throw NoteOperationException::encryptedUnreadable();
        }

        try {
            $bytes = $this->encryption->encryptNote($key, $entry['file_id'], $stem, $plain['content']);
        } catch (EncryptionException) {
            throw NoteOperationException::invalidName();
        }

        $result = $this->files->replaceFile(
            $absolute,
            $bytes,
            fn (): bool => $this->hashes->matches($absolute, $currentHash),
        );

        if ($result !== FileReplaceResult::Replaced) {
            throw NoteOperationException::encryptedMoveFailed('name');
        }

        $this->reconcile($note, $this->hashes->hashFile($absolute) ?? $this->hashes->hashString($bytes), strlen($bytes));

        return $note;
    }

    /**
     * @throws NoteOperationException|VaultLockedException
     */
    public function move(Note $note, ?string $folder): Note
    {
        $vault = $note->vault;
        $key = $this->keys->requireKey($vault);
        $this->assertVaultAvailable($vault);

        $ns = $this->namespace($vault, $key);
        $targetDisk = $this->resolveFolder($ns, $folder, 'folder');
        $entry = $ns->notes[$note->uuid] ?? null;

        if ($entry === null) {
            throw NoteOperationException::encryptedFileMissing('folder');
        }

        if ($entry['state'] !== 'ok') {
            throw NoteOperationException::encryptedUnreadable();
        }

        if ($targetDisk === $entry['folder']) {
            return $note;
        }

        if ($ns->noteNameTaken($targetDisk, $entry['name'], $note->uuid)) {
            throw NoteOperationException::encryptedNameTaken('folder');
        }

        $filename = $entry['file_id'].'.mdenc';
        $relative = $targetDisk === '' ? $filename : $targetDisk.'/'.$filename;
        $from = $this->files->joinRelative($vault->path, $entry['disk']);
        $to = $this->files->joinRelative($vault->path, $relative);

        if ($this->files->size($from) === null) {
            throw NoteOperationException::encryptedFileMissing('folder');
        }

        if (! $this->files->renameFile($from, $to)) {
            if ($this->files->isFile($from)) {
                throw NoteOperationException::encryptedMoveFailed('folder');
            }

            throw NoteOperationException::encryptedMoveRollbackFailed('folder');
        }

        $original = $note->only(['relative_path']);

        try {
            $this->database->connection()->transaction(fn () => $note->update(['relative_path' => $relative]));
        } catch (\Throwable $e) {
            $note->fill($original);

            if ($this->files->renameFile($to, $from)) {
                throw $e;
            }

            report($e);

            throw NoteOperationException::encryptedMoveRollbackFailed('folder');
        }

        return $note;
    }

    /**
     * @return bool true = file moved to trash, false = file was already missing (record removed)
     *
     * @throws NoteOperationException|VaultLockedException
     */
    public function delete(Note $note): bool
    {
        $vault = $note->vault;
        $this->keys->requireKey($vault);
        $this->assertVaultAvailable($vault);

        $absolute = $this->files->joinRelative($vault->path, $note->relative_path);

        if (! $this->files->exists($absolute)) {
            $note->delete();

            return false;
        }

        if (! $this->files->canTrash()) {
            throw NoteOperationException::trashUnavailable();
        }

        $safe = $this->files->isFile($absolute)
            && $this->files->isSameOrInside($absolute, $vault->path)
            && ! $this->files->samePath($absolute, $vault->path);

        if (! $safe) {
            throw NoteOperationException::invalidFolder('note');
        }

        if (! $this->files->moveToTrash($absolute)) {
            throw NoteOperationException::encryptedTrashFailed();
        }

        $note->delete();

        return true;
    }

    /**
     * @return string the new folder's logical path
     *
     * @throws NoteOperationException|VaultLockedException
     */
    public function createFolder(Vault $vault, ?string $parent, string $name): string
    {
        $key = $this->keys->requireKey($vault);
        $this->assertVaultAvailable($vault);
        $this->assertValidSegment($name);

        $ns = $this->namespace($vault, $key);
        $parentDisk = $this->resolveFolder($ns, $parent, 'parent');

        if ($ns->folderNameTaken($parentDisk, $name)) {
            throw NoteOperationException::encryptedNameTaken('name');
        }

        try {
            $nameBytes = $this->encryption->encryptFolderName($key, $folderId = $this->encryption->newFileId(), $name);
        } catch (EncryptionException) {
            throw NoteOperationException::invalidName();
        }

        $relative = $parentDisk === '' ? $folderId : $parentDisk.'/'.$folderId;
        $absolute = $this->files->joinRelative($vault->path, $relative);

        if (! $this->files->makeDirectory($absolute)) {
            throw NoteOperationException::encryptedCreateFailed('name');
        }

        $nameFile = $this->files->joinRelative($absolute, self::FOLDER_NAME_FILE);

        if (! $this->files->createFile($nameFile, $nameBytes)) {
            // Compensation: only the (still empty) folder this call made.
            $this->files->deleteNewFileWithContents($nameFile, $nameBytes);
            $this->files->deleteEmptyDirectory($absolute);

            throw NoteOperationException::encryptedCreateFailed('name');
        }

        $parentLogical = $ns->logicalFolderOf($parentDisk) ?? '';

        return $parentLogical === '' ? $name : $parentLogical.'/'.$name;
    }

    /**
     * Deletes a folder that holds nothing but its own name file.
     *
     * @throws NoteOperationException|VaultLockedException
     */
    public function deleteFolder(Vault $vault, string $path): void
    {
        $key = $this->keys->requireKey($vault);
        $this->assertVaultAvailable($vault);

        $ns = $this->namespace($vault, $key);
        $disk = $this->resolveFolder($ns, $path, 'path');

        if ($disk === '') {
            throw NoteOperationException::cannotDeleteRoot();
        }

        $absolute = $this->files->joinRelative($vault->path, $disk);
        $inside = $this->files->scan($absolute, fn (): bool => true);
        $nameFile = $this->files->joinRelative($absolute, self::FOLDER_NAME_FILE);

        $onlyNameFile = $inside['directories'] === []
            && $inside['unreadable'] === []
            && ($inside['files'] === [] || array_column($inside['files'], 'path') === [self::FOLDER_NAME_FILE]);

        $prefix = $disk.'/';
        $hasRows = $vault->notes()->get(['relative_path'])
            ->contains(fn (Note $note): bool => str_starts_with($note->relative_path, $prefix) && $this->files->isFile($this->files->joinRelative($vault->path, $note->relative_path)));

        if (! $onlyNameFile || $hasRows) {
            throw NoteOperationException::encryptedFolderNotEmpty();
        }

        $nameBytes = $inside['files'] === [] ? null : $this->files->read($nameFile);

        if ($nameBytes !== null && ! $this->files->deleteNewFileWithContents($nameFile, $nameBytes)) {
            throw NoteOperationException::encryptedFolderDeleteFailed();
        }

        if (! $this->files->deleteEmptyDirectory($absolute)) {
            if ($nameBytes !== null) {
                $this->files->createFile($nameFile, $nameBytes);
            }

            throw NoteOperationException::encryptedFolderDeleteFailed();
        }

        $this->database->connection()->transaction(function () use ($vault, $prefix): void {
            $staleIds = $vault->notes()->get(['id', 'relative_path'])
                ->filter(fn (Note $note): bool => str_starts_with($note->relative_path, $prefix))
                ->pluck('id');

            if ($staleIds->isNotEmpty()) {
                Note::query()->whereIn('id', $staleIds)->delete();
            }
        });
    }

    /**
     * @return array{content: ?string, body: ?string, frontmatter: ?string, frontmatter_yaml: ?string, base_hash: ?string, state: 'ok'|'missing'|'too_large'|'unreadable', is_valid_utf8: bool, editable: bool, read_only_reason: 'too_large'|'invalid_utf8'|null}
     *
     * @throws VaultLockedException
     */
    public function preview(Note $note): array
    {
        $vault = $note->vault;
        $key = $this->keys->requireKey($vault);
        $absolute = $this->files->joinRelative($vault->path, $note->relative_path);

        if (! $this->files->isFile($absolute)) {
            return $this->previewResult('missing');
        }

        $size = $this->files->size($absolute);

        if ($size !== null && $size > $this->maxCipherBytes()) {
            return $this->previewResult('unreadable');
        }

        $raw = $this->files->read($absolute);

        if ($raw === null) {
            return $this->previewResult('unreadable');
        }

        $hash = $this->hashes->hashString($raw);

        if ($hash !== $note->file_hash || strlen($raw) !== $note->file_size) {
            $note->update(['file_hash' => $hash, 'file_size' => strlen($raw), 'file_mtime' => null]);
        }

        $fileId = $this->fileIdOf($note->relative_path);
        $plain = $fileId === null ? null : $this->decryptBytes($key, $fileId, $raw);

        if ($plain === null) {
            return $this->previewResult('unreadable');
        }

        if (strlen($plain['content']) > NoteService::PREVIEW_LIMIT) {
            return $this->previewResult('too_large', 'too_large');
        }

        $doc = $this->markdown->decode($plain['content']);

        return [
            'content' => $doc->source,
            'body' => $doc->body,
            'frontmatter' => $doc->frontmatter,
            'frontmatter_yaml' => $doc->frontmatterYaml,
            'base_hash' => $hash,
            'state' => 'ok',
            'is_valid_utf8' => $doc->validUtf8,
            'editable' => $doc->validUtf8,
            'read_only_reason' => $doc->validUtf8 ? null : 'invalid_utf8',
        ];
    }

    /**
     * @throws NoteOperationException|NoteSaveConflictException|VaultLockedException
     */
    public function save(Note $note, string $content, string $baseHash, NoteSaveMode $mode, ?FrontmatterEdit $frontmatter = null): NoteSaveResult
    {
        $vault = $note->vault;
        $key = $this->keys->requireKey($vault);
        $this->assertVaultAvailable($vault);

        $absolute = $this->files->joinRelative($vault->path, $note->relative_path);

        if (! $this->files->isFile($absolute)) {
            throw NoteSaveConflictException::encryptedMissing();
        }

        if ($this->files->isSymlink($absolute)) {
            throw NoteOperationException::encryptedNotEditable();
        }

        $current = $this->files->read($absolute);

        if ($current === null) {
            throw NoteOperationException::encryptedSaveUnreadable();
        }

        $currentHash = $this->hashes->hashString($current);

        if (! hash_equals($currentHash, $baseHash)) {
            throw NoteSaveConflictException::encryptedChanged($currentHash);
        }

        $fileId = $this->fileIdOf($note->relative_path);
        $plain = $fileId === null ? null : $this->decryptBytes($key, $fileId, $current);

        if ($fileId === null || $plain === null) {
            throw NoteOperationException::encryptedUnreadable();
        }

        $doc = $this->markdown->decode($plain['content']);

        if (strlen($plain['content']) > NoteService::EDIT_LIMIT || ! $doc->validUtf8) {
            throw NoteOperationException::encryptedNotEditable();
        }

        $source = $mode === NoteSaveMode::Rich
            ? $this->markdown->composeRich($doc, $content, $frontmatter)
            : $this->markdown->composeSource($content);

        $bytes = $this->markdown->encode($source, $doc->eol, $doc->hasBom);

        if (strlen($bytes) > NoteService::EDIT_LIMIT) {
            throw NoteOperationException::contentTooLarge();
        }

        // A no-op save compares plaintext: re-encrypting identical content
        // would still change every byte on disk.
        if (hash_equals($plain['content'], $bytes)) {
            $this->reconcile($note, $currentHash, strlen($current));

            return new NoteSaveResult(
                written: false,
                fileHash: $currentHash,
                fileSize: strlen($current),
                updatedAt: $note->updated_at?->toIso8601String(),
            );
        }

        if (! $this->files->isWritableFile($absolute)) {
            throw NoteOperationException::encryptedReadOnlyFile();
        }

        try {
            $cipher = $this->encryption->encryptNote($key, $fileId, $plain['name'], $bytes);
        } catch (EncryptionException) {
            throw NoteOperationException::encryptedSaveWriteFailed();
        }

        $newHash = $this->hashes->hashString($cipher);

        $result = $this->files->replaceFile(
            $absolute,
            $cipher,
            fn (): bool => $this->hashes->matches($absolute, $currentHash),
        );

        match ($result) {
            FileReplaceResult::Replaced => null,
            FileReplaceResult::TargetInvalid => throw NoteSaveConflictException::encryptedMissing(),
            FileReplaceResult::WriteFailed => throw NoteOperationException::encryptedSaveWriteFailed(),
            FileReplaceResult::GuardFailed => throw NoteSaveConflictException::encryptedChanged($this->hashes->hashFile($absolute) ?? $currentHash),
            FileReplaceResult::ReplaceFailed => throw NoteOperationException::encryptedSaveLocked(),
        };

        $diskHash = $this->hashes->hashFile($absolute);

        if ($diskHash !== $newHash) {
            throw NoteSaveConflictException::encryptedChanged($diskHash ?? $newHash);
        }

        $this->reconcile($note, $newHash, strlen($cipher));

        return new NoteSaveResult(
            written: true,
            fileHash: $newHash,
            fileSize: strlen($cipher),
            updatedAt: $note->updated_at?->toIso8601String(),
        );
    }

    /**
     * @return array{uuid: string, title: string, filename: string, relative_path: string, folder: string, file_size: int, file_hash: string, updated_at: ?string}
     *
     * @throws VaultLockedException
     */
    public function present(Note $note): array
    {
        $vault = $note->vault;
        $key = $this->keys->requireKey($vault);
        $ns = $this->namespace($vault, $key);
        $entry = $ns->notes[$note->uuid] ?? null;

        $display = $entry['display'] ?? 'Missing note ('.substr((string) $this->fileIdOf($note->relative_path), 0, 8).')';
        $logical = $entry['logical'] ?? $display.'.md';

        return [
            'uuid' => $note->uuid,
            'title' => $display,
            'filename' => $display.'.md',
            'relative_path' => $logical,
            'folder' => $this->parentOf($logical),
            'file_size' => $note->file_size,
            'file_hash' => $note->file_hash,
            'updated_at' => $note->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  'missing'|'too_large'|'unreadable'  $state
     * @param  'too_large'|'invalid_utf8'|null  $readOnlyReason
     * @return array{content: ?string, body: ?string, frontmatter: ?string, frontmatter_yaml: ?string, base_hash: ?string, state: 'ok'|'missing'|'too_large'|'unreadable', is_valid_utf8: bool, editable: bool, read_only_reason: 'too_large'|'invalid_utf8'|null}
     */
    private function previewResult(string $state, ?string $readOnlyReason = null): array
    {
        return [
            'content' => null,
            'body' => null,
            'frontmatter' => null,
            'frontmatter_yaml' => null,
            'base_hash' => null,
            'state' => $state,
            'is_valid_utf8' => true,
            'editable' => false,
            'read_only_reason' => $readOnlyReason,
        ];
    }

    /**
     * Creates `<id>.mdenc` exclusively and registers it. A registry failure
     * removes the new file again (only if its bytes are still exactly what
     * this call wrote).
     *
     * @throws NoteOperationException
     */
    private function createNoteFile(Vault $vault, VaultKey $key, string $folderDisk, string $stem, string $source, string $field): Note
    {
        $fileId = $this->encryption->newFileId();

        try {
            $bytes = $this->encryption->encryptNote($key, $fileId, $stem, $source);
        } catch (EncryptionException) {
            throw NoteOperationException::invalidName();
        }

        $relative = ($folderDisk === '' ? '' : $folderDisk.'/').$fileId.'.mdenc';
        $absolute = $this->files->joinRelative($vault->path, $relative);

        if (! $this->files->createFile($absolute, $bytes)) {
            throw NoteOperationException::encryptedCreateFailed($field);
        }

        $attributes = $this->index->newNoteAttributes(
            $relative,
            $this->files->size($absolute) ?? strlen($bytes),
            $this->hashes->hashFile($absolute) ?? $this->hashes->hashString($bytes),
            encrypted: true,
        );

        try {
            return $this->database->connection()->transaction(fn (): Note => $vault->notes()->create($attributes));
        } catch (\Throwable $e) {
            $this->files->deleteNewFileWithContents($absolute, $bytes);

            throw $e;
        }
    }

    /**
     * Updates the registry hash and size to match the file. A failure is
     * reported and never compensated: the file is always the truth (Rule 9).
     */
    private function reconcile(Note $note, string $hash, int $size): void
    {
        if ($note->file_hash === $hash && $note->file_size === $size) {
            return;
        }

        try {
            $note->update(['file_hash' => $hash, 'file_size' => $size, 'file_mtime' => null]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Maps a logical folder path to its on-disk folder.
     *
     * @throws NoteOperationException
     */
    private function resolveFolder(VaultNamespace $ns, ?string $folder, string $field): string
    {
        if ($folder === null || $folder === '') {
            return '';
        }

        if (
            str_contains($folder, '\\')
            || str_contains($folder, "\0")
            || str_starts_with($folder, '/')
            || preg_match('/^[A-Za-z]:/', $folder) === 1
        ) {
            throw NoteOperationException::invalidFolder($field);
        }

        $trimmed = trim($folder, '/');
        $segments = $trimmed === '' ? [] : explode('/', $trimmed);

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw NoteOperationException::invalidFolder($field);
            }
        }

        return $ns->folderDisk(implode('/', $segments)) ?? throw NoteOperationException::encryptedFolderNotFound($field);
    }

    /**
     * @throws NoteOperationException
     */
    private function assertVaultAvailable(Vault $vault): void
    {
        if (! $this->files->isDirectory($vault->path)) {
            throw NoteOperationException::vaultUnavailable($vault->path);
        }
    }

    /**
     * The decrypted note name, or null if the file can't be read or
     * decrypted, or holds a name that isn't a safe single path segment.
     */
    private function readNoteName(VaultKey $key, string $fileId, string $absolute): ?string
    {
        $plain = $this->decryptFileAt($key, $absolute, $fileId);

        if ($plain === null || ! $this->isSafeName($plain['name'])) {
            return null;
        }

        return $plain['name'];
    }

    private function readFolderName(Vault $vault, VaultKey $key, string $diskFolder, string $folderId): ?string
    {
        $file = $this->files->joinRelative($this->files->joinRelative($vault->path, $diskFolder), self::FOLDER_NAME_FILE);
        $size = $this->files->size($file);

        if ($size === null || $size > EncryptionService::MAX_FOLDER_FILE_BYTES || $this->files->isSymlink($file)) {
            return null;
        }

        $bytes = $this->files->read($file);

        if ($bytes === null) {
            return null;
        }

        try {
            $name = $this->encryption->decryptFolderName($key, $folderId, $bytes);
        } catch (EncryptionException) {
            return null;
        }

        return $this->isSafeName($name) ? $name : null;
    }

    /**
     * @return array{name: string, content: string}|null
     */
    private function decryptFileAt(VaultKey $key, string $absolute, string $fileId): ?array
    {
        $size = $this->files->size($absolute);

        if ($size === null || $size > $this->maxCipherBytes() || $this->files->isSymlink($absolute)) {
            return null;
        }

        $bytes = $this->files->read($absolute);

        return $bytes === null ? null : $this->decryptBytes($key, $fileId, $bytes);
    }

    /**
     * @return array{name: string, content: string}|null
     */
    private function decryptBytes(VaultKey $key, string $fileId, string $bytes): ?array
    {
        try {
            return $this->encryption->decryptNote($key, $fileId, $bytes);
        } catch (EncryptionException) {
            return null;
        }
    }

    private function maxCipherBytes(): int
    {
        return EncryptionService::MAX_NOTE_PLAINTEXT_BYTES + EncryptionService::MAX_NAME_BYTES + 128;
    }

    /**
     * A decrypted name can only have come from this app, but a name that is
     * not a plain single path segment is never trusted.
     */
    private function isSafeName(string $name): bool
    {
        return $name !== '' && $name !== '.' && $name !== '..' && ! str_contains($name, '/') && ! str_contains($name, '\\') && ! str_contains($name, "\0");
    }

    private function fileIdOf(string $relativePath): ?string
    {
        $base = $this->basename($relativePath);

        return preg_match(VaultIndexService::ENCRYPTED_NOTE_PATTERN, $base) === 1 ? substr($base, 0, 32) : null;
    }

    /**
     * @param  list<string>  $allFolders  logical folder paths
     * @param  array<string, list<array<string, mixed>>>  $notesByFolder
     * @return list<array<string, mixed>>
     */
    private function buildChildren(string $parent, array $allFolders, array $notesByFolder, ?string $openPath): array
    {
        $children = [];

        foreach ($allFolders as $folder) {
            if ($this->parentOf($folder) !== $parent) {
                continue;
            }

            $children[] = [
                'type' => 'folder',
                'name' => $this->basename($folder),
                'path' => $folder,
                'open' => $openPath !== null && str_starts_with($openPath, $folder.'/'),
                'children' => $this->buildChildren($folder, $allFolders, $notesByFolder, $openPath),
            ];
        }

        foreach ($notesByFolder[$parent] ?? [] as $note) {
            $children[] = $note;
        }

        usort($children, function (array $a, array $b): int {
            if ($a['type'] !== $b['type']) {
                return $a['type'] === 'folder' ? -1 : 1;
            }

            $aName = $a['type'] === 'folder' ? $a['name'] : $a['title'];
            $bName = $b['type'] === 'folder' ? $b['name'] : $b['title'];

            return strnatcasecmp($aName, $bName);
        });

        return $children;
    }

    private function parentOf(string $path): string
    {
        $position = strrpos($path, '/');

        return $position === false ? '' : substr($path, 0, $position);
    }

    private function basename(string $path): string
    {
        $position = strrpos($path, '/');

        return $position === false ? $path : substr($path, $position + 1);
    }
}
