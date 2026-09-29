<?php

namespace App\Services;

use App\Enums\FileReplaceResult;
use App\Enums\NoteSaveMode;
use App\Enums\SettingKey;
use App\Exceptions\InvalidStorageRootException;
use App\Exceptions\NoteOperationException;
use App\Exceptions\NoteSaveConflictException;
use App\Models\Note;
use App\Models\Vault;
use App\Support\FrontmatterEdit;
use App\Support\NoteSaveResult;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;

/**
 * Note and folder operations: create, rename, move and delete a note
 * (delete goes to the OS Recycle Bin / Trash), create a folder and delete
 * an empty one, the read-only preview and the Rich/Source save. See ADR
 * `note-file-operations`: every file operation is checked afterwards and
 * never overwrites, and compensation only renames back or removes a 0-byte
 * file this call created. See ADR `note-save-atomic-replace` for `save()`.
 */
final class NoteService
{
    public const PREVIEW_LIMIT = 1_048_576;

    public const EDIT_LIMIT = self::PREVIEW_LIMIT;

    public const EXTENSION = 'md';

    public function __construct(
        private readonly FileStorageService $files,
        private readonly FileHashService $hashes,
        private readonly StoragePathService $paths,
        private readonly MarkdownService $markdown,
        private readonly SettingsService $settings,
        private readonly DatabaseManager $database,
    ) {}

    /**
     * Validates a note name; strips one trailing ".md" (any case); returns
     * the stem.
     *
     * @throws NoteOperationException
     */
    public function assertValidNoteName(string $name): string
    {
        $stem = preg_replace('/\.md$/i', '', $name, 1) ?? $name;

        $this->assertValidFolderName($stem);

        return $stem;
    }

    /**
     * @throws NoteOperationException
     */
    public function assertValidFolderName(string $name): void
    {
        try {
            $this->paths->assertValidFolderName($name);
        } catch (InvalidStorageRootException) {
            throw NoteOperationException::invalidName();
        }

        if (str_starts_with($name, '.')) {
            throw NoteOperationException::invalidName();
        }

        if (strcasecmp($name, 'node_modules') === 0) {
            throw NoteOperationException::invalidName();
        }
    }

    /**
     * Creates a note. When `SettingKey::EditorNewNoteTemplateEnabled` is on
     * (the default), the new file starts with a rendered frontmatter
     * template (Phase 4 Revision 4: `{{title}}`/`{{date}}`, the latter in
     * $timezone or the app's own timezone); otherwise the file is empty, as
     * before. The template is written in the exclusive create itself, so
     * there is never a second write: a DB failure compensates by removing
     * the new file only if its bytes still exactly equal what was written.
     *
     * @throws NoteOperationException
     */
    public function create(Vault $vault, ?string $folder, string $name, ?string $timezone = null): Note
    {
        $this->assertVaultAvailable($vault);
        $stem = $this->assertValidNoteName($name);
        [$folderRel] = $this->resolveFolder($vault, $folder, 'folder');

        $filename = $stem.'.'.self::EXTENSION;
        $relative = $this->relativeFor($folderRel, $filename);
        $absolute = $this->files->joinRelative($vault->path, $relative);

        $this->assertNoConflict($vault, $relative, $absolute, 'name', null, null);

        $source = $this->settings->boolean(SettingKey::EditorNewNoteTemplateEnabled)
            ? $this->markdown->renderNewNoteTemplate(
                (string) $this->settings->string(SettingKey::EditorNewNoteTemplate),
                $stem,
                CarbonImmutable::now($timezone ?? config('app.timezone')),
            )
            : '';

        if (! $this->files->createFile($absolute, $source)) {
            throw NoteOperationException::createFailed($relative, 'name');
        }

        $hash = $this->hashes->hashFile($absolute) ?? $this->hashes->hashString($source);
        $size = $this->files->size($absolute) ?? strlen($source);

        try {
            return $vault->notes()->create([
                'title' => $stem,
                'filename' => $filename,
                'relative_path' => $relative,
                'extension' => self::EXTENSION,
                'mime_type' => Note::MIME_TYPE,
                'file_size' => $size,
                'file_hash' => $hash,
                'is_encrypted' => false,
            ]);
        } catch (\Throwable $e) {
            $this->files->deleteNewFileWithContents($absolute, $source);

            throw $e;
        }
    }

    /**
     * @throws NoteOperationException
     */
    public function rename(Note $note, string $name): Note
    {
        $stem = $this->assertValidNoteName($name);
        $currentFolder = $this->parentFolder($note->relative_path);
        [$folderRel] = $this->resolveFolder($note->vault, $currentFolder, 'name');

        return $this->relocate($note, $folderRel, $stem.'.'.self::EXTENSION, 'name');
    }

    /**
     * @throws NoteOperationException
     */
    public function move(Note $note, ?string $folder): Note
    {
        [$folderRel] = $this->resolveFolder($note->vault, $folder, 'folder');

        return $this->relocate($note, $folderRel, $note->filename, 'folder');
    }

    /**
     * @return bool true = file moved to trash, false = file was already missing (record removed)
     *
     * @throws NoteOperationException
     */
    public function delete(Note $note): bool
    {
        $this->assertVaultAvailable($note->vault);
        $absolute = $this->absolutePath($note);

        if (! $this->files->exists($absolute)) {
            $note->delete();

            return false;
        }

        if (! $this->canTrash()) {
            throw NoteOperationException::trashUnavailable();
        }

        $safe = $this->files->isFile($absolute)
            && $this->files->isSameOrInside($absolute, $note->vault->path)
            && ! $this->files->samePath($absolute, $note->vault->path);

        if (! $safe) {
            throw NoteOperationException::invalidFolder('note');
        }

        if (! $this->files->moveToTrash($absolute)) {
            throw NoteOperationException::trashFailed($note->relative_path);
        }

        $note->delete();

        return true;
    }

    /**
     * @return string the new folder's relative path
     *
     * @throws NoteOperationException
     */
    public function createFolder(Vault $vault, ?string $parent, string $name): string
    {
        $this->assertVaultAvailable($vault);
        $this->assertValidFolderName($name);
        [$parentRel] = $this->resolveFolder($vault, $parent, 'parent');

        $relative = $this->relativeFor($parentRel, $name);
        $absolute = $this->files->joinRelative($vault->path, $relative);

        if ($this->files->exists($absolute)) {
            throw NoteOperationException::targetExists($relative, 'name');
        }

        if (! $this->files->makeDirectory($absolute)) {
            throw NoteOperationException::createFailed($relative, 'name');
        }

        return $relative;
    }

    /**
     * @throws NoteOperationException
     */
    public function deleteFolder(Vault $vault, string $path): void
    {
        $this->assertVaultAvailable($vault);
        [$relative, $absolute] = $this->resolveFolder($vault, $path, 'path');

        if ($relative === '') {
            throw NoteOperationException::cannotDeleteRoot();
        }

        if (! $this->files->isEmptyDirectory($absolute)) {
            throw NoteOperationException::folderNotEmpty($relative);
        }

        if (! $this->files->deleteEmptyDirectory($absolute)) {
            throw NoteOperationException::folderDeleteFailed($relative);
        }

        $this->database->connection()->transaction(function () use ($vault, $relative): void {
            $prefix = $relative.'/';

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
     */
    public function preview(Note $note): array
    {
        $absolute = $this->absolutePath($note);

        if (! $this->files->isFile($absolute)) {
            return $this->previewResult(state: 'missing');
        }

        $size = $this->files->size($absolute);

        if ($size !== null && $size > self::PREVIEW_LIMIT) {
            $hash = $this->hashes->hashFile($absolute);

            if ($hash !== null && ($hash !== $note->file_hash || $size !== $note->file_size)) {
                $note->update(['file_hash' => $hash, 'file_size' => $size]);
            }

            return $this->previewResult(state: 'too_large', readOnlyReason: 'too_large');
        }

        $raw = $this->files->read($absolute);

        if ($raw === null) {
            return $this->previewResult(state: 'unreadable');
        }

        $hash = $this->hashes->hashString($raw);

        if ($hash !== $note->file_hash || strlen($raw) !== $note->file_size) {
            $note->update(['file_hash' => $hash, 'file_size' => strlen($raw)]);
        }

        $doc = $this->markdown->decode($raw);

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
     * Saves a Rich- or Source-mode edit through the atomic replace (ADR
     * `note-save-atomic-replace`). A no-op save (identical bytes) never
     * writes the file but still reconciles a stale DB hash.
     *
     * @throws NoteOperationException|NoteSaveConflictException
     */
    public function save(Note $note, string $content, string $baseHash, NoteSaveMode $mode, ?FrontmatterEdit $frontmatter = null): NoteSaveResult
    {
        $this->assertVaultAvailable($note->vault);
        $absolute = $this->absolutePath($note);

        if (! $this->files->isFile($absolute)) {
            throw NoteSaveConflictException::missing($note->relative_path);
        }

        if ($this->files->isSymlink($absolute)) {
            throw NoteOperationException::notEditable($note->relative_path);
        }

        $current = $this->files->read($absolute);

        if ($current === null) {
            throw NoteOperationException::saveUnreadable($note->relative_path);
        }

        $currentHash = $this->hashes->hashString($current);

        if (! hash_equals($currentHash, $baseHash)) {
            throw NoteSaveConflictException::changed($note->relative_path, $currentHash);
        }

        $doc = $this->markdown->decode($current);

        if (strlen($current) > self::EDIT_LIMIT || ! $doc->validUtf8) {
            throw NoteOperationException::notEditable($note->relative_path);
        }

        $source = $mode === NoteSaveMode::Rich
            ? $this->markdown->composeRich($doc, $content, $frontmatter)
            : $this->markdown->composeSource($content);

        $bytes = $this->markdown->encode($source, $doc->eol, $doc->hasBom);

        if (strlen($bytes) > self::EDIT_LIMIT) {
            throw NoteOperationException::contentTooLarge();
        }

        $newHash = $this->hashes->hashString($bytes);

        if ($newHash === $currentHash) {
            $this->reconcile($note, $newHash, strlen($bytes));

            return new NoteSaveResult(
                written: false,
                fileHash: $newHash,
                fileSize: strlen($bytes),
                updatedAt: $note->updated_at?->toIso8601String(),
            );
        }

        if (! $this->files->isWritableFile($absolute)) {
            throw NoteOperationException::readOnlyFile($note->relative_path);
        }

        $result = $this->files->replaceFile(
            $absolute,
            $bytes,
            fn (): bool => $this->hashes->matches($absolute, $currentHash),
        );

        match ($result) {
            FileReplaceResult::Replaced => null,
            FileReplaceResult::TargetInvalid => throw NoteSaveConflictException::missing($note->relative_path),
            FileReplaceResult::WriteFailed => throw NoteOperationException::saveWriteFailed($note->relative_path),
            FileReplaceResult::GuardFailed => throw NoteSaveConflictException::changed(
                $note->relative_path,
                $this->hashes->hashFile($absolute) ?? $currentHash,
            ),
            FileReplaceResult::ReplaceFailed => throw NoteOperationException::saveLocked($note->relative_path),
        };

        $diskHash = $this->hashes->hashFile($absolute);

        if ($diskHash !== $newHash) {
            throw NoteSaveConflictException::changed($note->relative_path, $diskHash ?? $newHash);
        }

        $this->reconcile($note, $newHash, strlen($bytes));

        return new NoteSaveResult(
            written: true,
            fileHash: $newHash,
            fileSize: strlen($bytes),
            updatedAt: $note->updated_at?->toIso8601String(),
        );
    }

    /**
     * Updates the DB hash/size to match the file. A failure is reported and
     * never compensated: the file is always the truth (Rule 9).
     */
    private function reconcile(Note $note, string $hash, int $size): void
    {
        try {
            $note->update(['file_hash' => $hash, 'file_size' => $size]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * @return array{uuid: string, title: string, filename: string, relative_path: string, folder: string, file_size: int, file_hash: string, updated_at: ?string}
     */
    public function present(Note $note): array
    {
        return [
            'uuid' => $note->uuid,
            'title' => $note->title,
            'filename' => $note->filename,
            'relative_path' => $note->relative_path,
            'folder' => $this->parentFolder($note->relative_path),
            'file_size' => $note->file_size,
            'file_hash' => $note->file_hash,
            'updated_at' => $note->updated_at?->toIso8601String(),
        ];
    }

    public function absolutePath(Note $note): string
    {
        return $this->files->joinRelative($note->vault->path, $note->relative_path);
    }

    public function canTrash(): bool
    {
        return $this->files->canTrash();
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
     * @return array{0: string, 1: string} [normalizedRelative, absolute]
     *
     * @throws NoteOperationException
     */
    private function resolveFolder(Vault $vault, ?string $folder, string $field): array
    {
        if ($folder === null || $folder === '') {
            return ['', $vault->path];
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

        $relative = implode('/', $segments);
        $absolute = $this->files->joinRelative($vault->path, $relative);

        if (! $this->files->isDirectory($absolute)) {
            throw NoteOperationException::folderNotFound($relative, $field);
        }

        $walked = $vault->path;

        foreach ($segments as $segment) {
            $walked = $this->files->joinRelative($walked, $segment);

            if ($this->files->isSymlink($walked)) {
                throw NoteOperationException::folderNotFound($relative, $field);
            }
        }

        if (! $this->files->isSameOrInside($this->files->canonical($absolute), $vault->path)) {
            throw NoteOperationException::invalidFolder($field);
        }

        return [$relative, $absolute];
    }

    private function relativeFor(string $folder, string $filename): string
    {
        return $folder === '' ? $filename : $folder.'/'.$filename;
    }

    /**
     * @throws NoteOperationException
     */
    private function assertNoConflict(Vault $vault, string $relative, string $absolute, string $field, ?Note $except, ?string $fromAbsolute): void
    {
        if ($this->files->exists($absolute) && ! ($fromAbsolute !== null && $this->files->isSameFile($fromAbsolute, $absolute))) {
            throw NoteOperationException::targetExists($relative, $field);
        }

        $lower = mb_strtolower($relative);

        foreach ($vault->notes()->pluck('relative_path', 'uuid') as $uuid => $path) {
            if ($except !== null && $uuid === $except->uuid) {
                continue;
            }

            if (mb_strtolower($path) === $lower) {
                throw NoteOperationException::targetExists($relative, $field);
            }
        }
    }

    /**
     * @throws NoteOperationException
     */
    private function relocate(Note $note, string $folderRel, string $filename, string $field): Note
    {
        $this->assertVaultAvailable($note->vault);
        $from = $this->absolutePath($note);

        if (! $this->files->isFile($from)) {
            throw NoteOperationException::noteFileMissing($note->relative_path, $field);
        }

        $relative = $this->relativeFor($folderRel, $filename);
        $to = $this->files->joinRelative($note->vault->path, $relative);

        if ($relative === $note->relative_path) {
            return $note;
        }

        $this->assertNoConflict($note->vault, $relative, $to, $field, $note, $from);

        if (! $this->files->renameFile($from, $to)) {
            if ($this->files->isFile($from)) {
                throw NoteOperationException::moveFailed($field);
            }

            throw NoteOperationException::moveInterrupted($note->relative_path, $relative, $field);
        }

        $original = $note->only(['relative_path', 'filename', 'title', 'extension']);
        $attributes = [
            'relative_path' => $relative,
            'filename' => $filename,
            'title' => pathinfo($filename, PATHINFO_FILENAME),
            'extension' => mb_strtolower(pathinfo($filename, PATHINFO_EXTENSION)),
        ];

        try {
            $this->database->connection()->transaction(fn () => $note->update($attributes));
        } catch (\Throwable $e) {
            $note->fill($original);

            if ($this->files->renameFile($to, $from)) {
                throw $e;
            }

            report($e);

            throw NoteOperationException::moveRollbackFailed($relative, $field);
        }

        return $note;
    }

    private function parentFolder(string $relativePath): string
    {
        $position = strrpos($relativePath, '/');

        return $position === false ? '' : substr($relativePath, 0, $position);
    }
}
