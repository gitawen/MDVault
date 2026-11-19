<?php

namespace App\Services;

use App\Enums\IndexMode;
use App\Enums\NoteSaveMode;
use App\Enums\VaultStatus;
use App\Exceptions\InvalidStorageRootException;
use App\Exceptions\McpKosException;
use App\Exceptions\NoteOperationException;
use App\Exceptions\NoteSaveConflictException;
use App\Exceptions\VaultOperationException;
use App\Models\Note;
use App\Models\Vault;
use Illuminate\Support\Str;

/**
 * Core Knowledge Operating System (KOS) domain logic for MDVault's MCP server
 * and AI workspace management: two-tier vault permissions, encrypted-vault
 * exclusion, path safety, note/folder operations, project scaffolding, core
 * memory (`AI/about-me.md`), and skills management.
 */
final class McpKosService
{
    public const AI_VAULT_NAME = 'AI';

    public const ABOUT_ME_FILENAME = 'about-me.md';

    public const SKILLS_FOLDER = 'skills';

    public const DEFAULT_CONTEXT_MAX_CHARS = 2000;

    public const MAX_SKILL_RECOMMENDED_CHARS = 4000;

    public function __construct(
        private readonly VaultService $vaults,
        private readonly NoteService $notes,
        private readonly VaultIndexService $index,
        private readonly FileStorageService $files,
        private readonly MarkdownService $markdown,
        private readonly StoragePathService $paths,
    ) {}

    /**
     * Lists all registered vaults with MCP access tier, permission level,
     * and note count (only queried for accessible unencrypted active vaults).
     *
     * @return array{
     *     vaults: list<array{
     *         uuid: string,
     *         name: string,
     *         description: ?string,
     *         status: string,
     *         is_current: bool,
     *         is_encrypted: bool,
     *         accessible: bool,
     *         tier: 'ai_workspace'|'personal'|'excluded',
     *         permission: 'full_crud'|'read_safe_write'|'none',
     *         note_count: ?int,
     *         exclusion_reason: ?string
     *     }>,
     *     ai_workspace: array{
     *         name: string,
     *         provisioned: bool,
     *         accessible: bool,
     *         vault_uuid: ?string,
     *         note: string
     *     }
     * }
     */
    public function listVaults(): array
    {
        $allVaults = $this->vaults->all();
        $accessibleIds = [];

        foreach ($allVaults as $vault) {
            if ($vault->status === VaultStatus::Active && ! $vault->is_encrypted) {
                $accessibleIds[] = $vault->id;
            }
        }

        $noteCounts = $accessibleIds === []
            ? []
            : Note::query()
                ->selectRaw('vault_id, count(*) as aggregate')
                ->whereIn('vault_id', $accessibleIds)
                ->groupBy('vault_id')
                ->pluck('aggregate', 'vault_id')
                ->all();

        $entries = [];
        $aiVault = null;

        foreach ($allVaults as $vault) {
            $presented = $this->vaults->present($vault);
            $accessible = $vault->status === VaultStatus::Active && ! $vault->is_encrypted;
            $isAi = $this->isAiVault($vault);

            if ($isAi && $aiVault === null) {
                $aiVault = $vault;
            }

            $tier = match (true) {
                ! $accessible => 'excluded',
                $isAi => 'ai_workspace',
                default => 'personal',
            };

            $permission = match (true) {
                ! $accessible => 'none',
                $isAi => 'full_crud',
                default => 'read_safe_write',
            };

            $exclusionReason = match (true) {
                $vault->is_encrypted => 'encrypted',
                $vault->status === VaultStatus::Missing => 'missing',
                default => null,
            };

            $entries[] = [
                'uuid' => $vault->uuid,
                'name' => $vault->name,
                'description' => $vault->description,
                'status' => $vault->status->value,
                'is_current' => $presented['is_current'],
                'is_encrypted' => $vault->is_encrypted,
                'accessible' => $accessible,
                'tier' => $tier,
                'permission' => $permission,
                'note_count' => $accessible ? (int) ($noteCounts[$vault->id] ?? 0) : null,
                'exclusion_reason' => $exclusionReason,
            ];
        }

        $aiAccessible = $aiVault !== null && $aiVault->status === VaultStatus::Active && ! $aiVault->is_encrypted;
        $aiNote = match (true) {
            $aiVault === null => 'AI workspace is not provisioned yet; it will be auto-created on first AI write or can be provisioned from Settings.',
            $aiVault->is_encrypted => 'AI vault is encrypted and excluded from MCP access.',
            $aiVault->status === VaultStatus::Missing => 'AI vault folder is currently missing on disk.',
            default => 'AI workspace is active with full CRUD permissions.',
        };

        return [
            'vaults' => $entries,
            'ai_workspace' => [
                'name' => self::AI_VAULT_NAME,
                'provisioned' => $aiVault !== null,
                'accessible' => $aiAccessible,
                'vault_uuid' => $aiVault?->uuid,
                'note' => $aiNote,
            ],
        ];
    }

    /**
     * Resolves an active, unencrypted vault by UUID or case-insensitive name.
     *
     * @throws McpKosException
     */
    public function resolveAccessibleVault(string $identifier): Vault
    {
        $vault = $this->findVault($identifier);

        if ($vault === null) {
            throw McpKosException::vaultNotFound($identifier);
        }

        $this->vaults->refreshStatus($vault);

        if ($vault->is_encrypted) {
            throw McpKosException::encryptedVaultForbidden($vault->name);
        }

        if ($vault->status === VaultStatus::Missing) {
            throw McpKosException::vaultMissing($vault->name, $vault->path);
        }

        return $vault;
    }

    /**
     * Resolves the dedicated AI workspace vault, auto-provisioning it when absent.
     *
     * @throws McpKosException
     */
    public function resolveOrProvisionAiVault(): Vault
    {
        $existing = $this->findVault(self::AI_VAULT_NAME);

        if ($existing !== null) {
            $this->vaults->refreshStatus($existing);

            if ($existing->is_encrypted) {
                throw McpKosException::encryptedVaultForbidden($existing->name);
            }

            if ($existing->status === VaultStatus::Missing) {
                throw McpKosException::vaultMissing($existing->name, $existing->path);
            }

            return $existing;
        }

        try {
            return $this->vaults->create(self::AI_VAULT_NAME, 'Dedicated AI Agent KOS Workspace');
        } catch (VaultOperationException $e) {
            throw McpKosException::aiVaultUnavailable($e->getMessage());
        }
    }

    public function isAiVault(Vault $vault): bool
    {
        return mb_strtolower($vault->name) === 'ai';
    }

    /**
     * @throws McpKosException
     */
    public function assertAiVault(Vault $vault, string $action): void
    {
        if (! $this->isAiVault($vault)) {
            throw McpKosException::permissionDenied($action, $vault->name);
        }
    }

    /**
     * Validates and normalizes a vault-relative path for a note or folder.
     *
     * @throws McpKosException
     */
    public function normalizeRelativePath(string $path, string $field, bool $expectMarkdown = false): string
    {
        if (
            $path === ''
            || str_contains($path, "\0")
            || str_contains($path, '\\')
            || preg_match('/^[A-Za-z]:/', $path) === 1
        ) {
            throw McpKosException::invalidPath($field);
        }

        $trimmed = str_starts_with($path, '/') ? substr($path, 1) : $path;

        if ($trimmed === '') {
            throw McpKosException::invalidPath($field);
        }

        $segments = explode('/', $trimmed);
        $lastIndex = count($segments) - 1;

        foreach ($segments as $index => $segment) {
            if (
                $segment === ''
                || $segment === '.'
                || $segment === '..'
                || str_starts_with($segment, '.')
                || strcasecmp($segment, 'node_modules') === 0
            ) {
                throw McpKosException::invalidPath($field);
            }

            $segmentToCheck = ($expectMarkdown && $index === $lastIndex)
                ? (preg_replace('/\.md$/i', '', $segment, 1) ?? $segment)
                : $segment;

            try {
                $this->paths->assertValidNoteSegment($segmentToCheck);
            } catch (InvalidStorageRootException) {
                throw McpKosException::invalidPath($field);
            }
        }

        $normalized = implode('/', $segments);

        if ($expectMarkdown && ! str_ends_with(mb_strtolower($normalized), '.md')) {
            $normalized .= '.md';
        }

        return $normalized;
    }

    /**
     * Normalizes an optional folder path, allowing empty string for vault root.
     *
     * @throws McpKosException
     */
    public function normalizeFolderPath(string $folder, string $field = 'folder'): string
    {
        if ($folder === '' || $folder === '/') {
            return '';
        }

        $trimmed = str_starts_with($folder, '/') ? substr($folder, 1) : $folder;

        if (str_ends_with($trimmed, '/') && ! str_ends_with($trimmed, '//')) {
            $trimmed = substr($trimmed, 0, -1);
        }

        if ($trimmed === '') {
            return '';
        }

        return $this->normalizeRelativePath($trimmed, $field, expectMarkdown: false);
    }

    /**
     * Reconciles quick index and resolves a note by UUID or vault-relative path.
     *
     * @throws McpKosException
     */
    public function resolveNote(Vault $vault, ?string $path, ?string $uuid): Note
    {
        $hasUuid = $uuid !== null && trim($uuid) !== '';
        $hasPath = $path !== null && trim($path) !== '';

        if (! $hasUuid && ! $hasPath) {
            throw McpKosException::invalidInput('Provide either path or uuid to identify the note.');
        }

        try {
            $this->index->reconcile($vault, IndexMode::Quick);
        } catch (NoteOperationException $e) {
            throw McpKosException::operationFailed($e->getMessage());
        }

        if ($hasUuid) {
            $trimmedUuid = trim((string) $uuid);

            if (! Str::isUuid($trimmedUuid)) {
                throw McpKosException::invalidInput('The supplied note uuid is not a valid UUID.');
            }

            $note = $vault->notes()->where('uuid', strtolower($trimmedUuid))->first();

            if ($note === null) {
                throw McpKosException::noteNotFound($trimmedUuid, $vault->name);
            }

            $note->setRelation('vault', $vault);

            return $note;
        }

        $normalized = $this->normalizeRelativePath((string) $path, 'path', expectMarkdown: true);
        $note = $vault->notes()->where('relative_path', $normalized)->first();

        if ($note === null) {
            $lower = mb_strtolower($normalized);
            $note = $vault->notes()->get()->first(
                fn (Note $candidate): bool => mb_strtolower($candidate->relative_path) === $lower,
            );
        }

        if ($note === null) {
            throw McpKosException::noteNotFound($normalized, $vault->name);
        }

        $note->setRelation('vault', $vault);

        return $note;
    }

    /**
     * Lists folders and notes in an accessible vault (reconciling Quick index first).
     *
     * @return array<string, mixed>
     *
     * @throws McpKosException
     */
    public function listNotes(string $vaultIdentifier, ?string $folder = null): array
    {
        $normalizedFolder = $folder !== null ? $this->normalizeFolderPath($folder, 'folder') : '';

        if ($this->isAiIdentifier($vaultIdentifier) && $this->findVault(self::AI_VAULT_NAME) === null) {
            return [
                'vault' => self::AI_VAULT_NAME,
                'vault_uuid' => null,
                'provisioned' => false,
                'folder' => $normalizedFolder,
                'folders' => [''],
                'tree' => [],
                'note_count' => 0,
            ];
        }

        $vault = $this->resolveAccessibleVault($vaultIdentifier);

        try {
            $this->index->reconcile($vault, IndexMode::Quick);
            $browse = $this->index->browse($vault);
        } catch (NoteOperationException $e) {
            throw McpKosException::operationFailed($e->getMessage());
        }

        $folders = $browse['folders'];
        $tree = $browse['tree'];

        if ($normalizedFolder !== '') {
            $matchedFolder = $this->matchFolderInList($folders, $normalizedFolder);

            if ($matchedFolder === null) {
                throw McpKosException::folderNotFound($normalizedFolder, $vault->name);
            }

            $normalizedFolder = $matchedFolder;
            $tree = $this->extractFolderChildren($tree, $normalizedFolder);
            $prefix = $normalizedFolder.'/';
            $folders = array_values(array_filter(
                $folders,
                fn (string $f): bool => $f === $normalizedFolder || str_starts_with($f, $prefix),
            ));
        }

        return [
            'vault' => $vault->name,
            'vault_uuid' => $vault->uuid,
            'provisioned' => true,
            'folder' => $normalizedFolder,
            'folders' => $folders,
            'tree' => $tree,
            'note_count' => $this->countNotesInTree($tree),
        ];
    }

    /**
     * Reads a note's Markdown content, frontmatter, and fresh SHA-256 file_hash.
     *
     * @return array<string, mixed>
     *
     * @throws McpKosException
     */
    public function readNote(string $vaultIdentifier, ?string $path, ?string $uuid): array
    {
        $vault = $this->resolveAccessibleVault($vaultIdentifier);
        $note = $this->resolveNote($vault, $path, $uuid);
        $absolute = $this->notes->absolutePath($note);

        if ($this->files->isSymlink($absolute)) {
            throw McpKosException::noteUnreadable($note->relative_path, 'symlink');
        }

        $preview = $this->notes->preview($note);

        if ($preview['state'] !== 'ok' || ! $preview['editable']) {
            $reason = $preview['read_only_reason'] ?? $preview['state'];

            throw McpKosException::noteUnreadable($note->relative_path, (string) $reason);
        }

        $note->refresh();
        $note->setRelation('vault', $vault);

        return [
            'vault' => $vault->name,
            'vault_uuid' => $vault->uuid,
            ...$this->notes->present($note),
            'file_hash' => (string) $preview['base_hash'],
            'content' => (string) $preview['content'],
            'body' => (string) $preview['body'],
            'frontmatter_yaml' => $preview['frontmatter_yaml'],
        ];
    }

    /**
     * Creates a new note atomically (never overwriting existing files).
     *
     * @return array<string, mixed>
     *
     * @throws McpKosException
     */
    public function createNote(
        string $vaultIdentifier,
        string $path,
        ?string $content = null,
        ?bool $applyTemplate = null,
    ): array {
        $vault = $this->isAiIdentifier($vaultIdentifier)
            ? $this->resolveOrProvisionAiVault()
            : $this->resolveAccessibleVault($vaultIdentifier);

        $normalized = $this->normalizeRelativePath($path, 'path', expectMarkdown: true);
        [$folder, $filename] = $this->splitRelativePath($normalized);
        $effectiveTemplate = $applyTemplate ?? ($content === null || $content === '');

        try {
            if (($content === null || $content === '') && $effectiveTemplate) {
                $note = $this->notes->create($vault, $folder === '' ? null : $folder, $filename);
            } else {
                $note = $this->notes->createWithContent(
                    $vault,
                    $folder === '' ? null : $folder,
                    $filename,
                    $content ?? '',
                    $effectiveTemplate,
                );
            }
        } catch (NoteOperationException $e) {
            throw McpKosException::operationFailed($e->getMessage());
        }

        $note->setRelation('vault', $vault);

        return [
            'vault' => $vault->name,
            'vault_uuid' => $vault->uuid,
            ...$this->notes->present($note),
            'created' => true,
        ];
    }

    /**
     * Updates an existing note atomically with mandatory SHA-256 optimistic locking.
     *
     * @return array<string, mixed>
     *
     * @throws McpKosException
     */
    public function updateNote(
        string $vaultIdentifier,
        ?string $path,
        ?string $uuid,
        string $content,
        string $fileHash,
    ): array {
        $this->assertValidSha256($fileHash);

        $vault = $this->resolveAccessibleVault($vaultIdentifier);
        $note = $this->resolveNote($vault, $path, $uuid);

        try {
            $result = $this->notes->save($note, $content, strtolower($fileHash), NoteSaveMode::Source);
        } catch (NoteSaveConflictException $e) {
            throw McpKosException::conflict($e->getMessage(), $e->currentHash(), $e->reason());
        } catch (NoteOperationException $e) {
            throw McpKosException::operationFailed($e->getMessage());
        }

        $note->refresh();
        $note->setRelation('vault', $vault);

        return [
            'vault' => $vault->name,
            'vault_uuid' => $vault->uuid,
            ...$this->notes->present($note),
            'written' => $result->written,
            'file_hash' => $result->fileHash,
            'file_size' => $result->fileSize,
            'updated_at' => $result->updatedAt,
        ];
    }

    /**
     * Appends content to an existing note using optimistic locking.
     *
     * @return array<string, mixed>
     *
     * @throws McpKosException
     */
    public function appendNote(
        string $vaultIdentifier,
        ?string $path,
        ?string $uuid,
        string $content,
        ?string $fileHash = null,
        bool $ensureNewline = true,
    ): array {
        if ($fileHash !== null) {
            $this->assertValidSha256($fileHash);
        }

        $vault = $this->resolveAccessibleVault($vaultIdentifier);
        $note = $this->resolveNote($vault, $path, $uuid);
        $absolute = $this->notes->absolutePath($note);

        if ($this->files->isSymlink($absolute)) {
            throw McpKosException::noteUnreadable($note->relative_path, 'symlink');
        }

        $preview = $this->notes->preview($note);

        if ($preview['state'] !== 'ok' || ! $preview['editable']) {
            $reason = $preview['read_only_reason'] ?? $preview['state'];

            throw McpKosException::noteUnreadable($note->relative_path, (string) $reason);
        }

        $liveHash = (string) $preview['base_hash'];

        if ($fileHash !== null && ! hash_equals($liveHash, strtolower($fileHash))) {
            throw McpKosException::conflict(
                "Note \u{201c}{$note->relative_path}\u{201d} was changed before appending.",
                $liveHash,
                'changed',
            );
        }

        $existing = (string) $preview['content'];
        $normalizedAppend = str_replace("\r\n", "\n", $content);
        $needsSeparator = $ensureNewline
            && $existing !== ''
            && $normalizedAppend !== ''
            && ! str_ends_with($existing, "\n")
            && ! str_starts_with($normalizedAppend, "\n");
        $combined = $existing.($needsSeparator ? "\n" : '').$normalizedAppend;

        try {
            $result = $this->notes->save($note, $combined, $liveHash, NoteSaveMode::Source);
        } catch (NoteSaveConflictException $e) {
            throw McpKosException::conflict($e->getMessage(), $e->currentHash(), $e->reason());
        } catch (NoteOperationException $e) {
            throw McpKosException::operationFailed($e->getMessage());
        }

        $note->refresh();
        $note->setRelation('vault', $vault);

        return [
            'vault' => $vault->name,
            'vault_uuid' => $vault->uuid,
            ...$this->notes->present($note),
            'written' => $result->written,
            'file_hash' => $result->fileHash,
            'file_size' => $result->fileSize,
            'updated_at' => $result->updatedAt,
            'appended_bytes' => strlen($normalizedAppend),
        ];
    }

    /**
     * Renames a note inside the AI vault.
     *
     * @return array<string, mixed>
     *
     * @throws McpKosException
     */
    public function renameNote(string $vaultIdentifier, ?string $path, ?string $uuid, string $newName): array
    {
        $vault = $this->resolveAccessibleVault($vaultIdentifier);
        $this->assertAiVault($vault, 'rename_note');

        $note = $this->resolveNote($vault, $path, $uuid);
        $previousPath = $note->relative_path;

        try {
            $updated = $this->notes->rename($note, $newName);
        } catch (NoteOperationException $e) {
            throw McpKosException::operationFailed($e->getMessage());
        }

        $updated->setRelation('vault', $vault);

        return [
            'vault' => $vault->name,
            'vault_uuid' => $vault->uuid,
            'previous_path' => $previousPath,
            ...$this->notes->present($updated),
        ];
    }

    /**
     * Moves a note to another folder inside the AI vault.
     *
     * @return array<string, mixed>
     *
     * @throws McpKosException
     */
    public function moveNote(string $vaultIdentifier, ?string $path, ?string $uuid, string $targetFolder): array
    {
        $vault = $this->resolveAccessibleVault($vaultIdentifier);
        $this->assertAiVault($vault, 'move_note');

        $note = $this->resolveNote($vault, $path, $uuid);
        $previousPath = $note->relative_path;
        $normalizedFolder = $this->normalizeFolderPath($targetFolder, 'target_folder');

        try {
            $updated = $this->notes->move($note, $normalizedFolder === '' ? null : $normalizedFolder);
        } catch (NoteOperationException $e) {
            throw McpKosException::operationFailed($e->getMessage());
        }

        $updated->setRelation('vault', $vault);

        return [
            'vault' => $vault->name,
            'vault_uuid' => $vault->uuid,
            'previous_path' => $previousPath,
            ...$this->notes->present($updated),
        ];
    }

    /**
     * Deletes a note from the AI vault, moving it to OS Trash when available
     * or falling back to `AI/.trash/` in CLI/headless stdio mode.
     *
     * @return array<string, mixed>
     *
     * @throws McpKosException
     */
    public function deleteNote(string $vaultIdentifier, ?string $path, ?string $uuid): array
    {
        $vault = $this->resolveAccessibleVault($vaultIdentifier);
        $this->assertAiVault($vault, 'delete_note');

        $note = $this->resolveNote($vault, $path, $uuid);
        $relativePath = $note->relative_path;
        $noteUuid = $note->uuid;

        if ($this->notes->canTrash()) {
            try {
                $moved = $this->notes->delete($note);
            } catch (NoteOperationException $e) {
                throw McpKosException::operationFailed($e->getMessage());
            }

            return [
                'vault' => $vault->name,
                'vault_uuid' => $vault->uuid,
                'uuid' => $noteUuid,
                'relative_path' => $relativePath,
                'deleted' => true,
                'trashed_to' => $moved ? 'os_trash' : 'already_missing',
            ];
        }

        $absolute = $this->notes->absolutePath($note);

        if (! $this->files->exists($absolute)) {
            $note->delete();

            return [
                'vault' => $vault->name,
                'vault_uuid' => $vault->uuid,
                'uuid' => $noteUuid,
                'relative_path' => $relativePath,
                'deleted' => true,
                'trashed_to' => 'already_missing',
            ];
        }

        $safe = $this->files->isFile($absolute)
            && ! $this->files->isSymlink($absolute)
            && $this->files->isSameOrInside($absolute, $vault->path)
            && ! $this->files->samePath($absolute, $vault->path);

        if (! $safe) {
            throw McpKosException::invalidPath('path');
        }

        $trashDir = $this->files->joinRelative($vault->path, '.trash');

        if ($this->files->exists($trashDir) && (! $this->files->isDirectory($trashDir) || $this->files->isSymlink($trashDir))) {
            throw McpKosException::operationFailed('AI/.trash exists but is not a regular directory.');
        }

        if (! $this->files->isDirectory($trashDir) && ! $this->files->makeDirectory($trashDir)) {
            throw McpKosException::operationFailed('Could not create AI/.trash directory.');
        }

        $trashFilename = date('Ymd-His').'_'.$note->uuid.'_'.$note->filename;
        $trashTarget = $this->files->joinRelative($trashDir, $trashFilename);

        if (! $this->files->renameFile($absolute, $trashTarget)) {
            throw McpKosException::operationFailed("Could not move \u{201c}{$relativePath}\u{201d} to AI/.trash.");
        }

        $note->delete();

        return [
            'vault' => $vault->name,
            'vault_uuid' => $vault->uuid,
            'uuid' => $noteUuid,
            'relative_path' => $relativePath,
            'deleted' => true,
            'trashed_to' => 'vault_trash',
            'trash_path' => '.trash/'.$trashFilename,
        ];
    }

    /**
     * Creates a folder (and optionally missing parent segments) in the AI vault.
     *
     * @return array{vault: string, vault_uuid: string, path: string, created_segments: list<string>, already_existed: bool}
     *
     * @throws McpKosException
     */
    public function createFolder(string $vaultIdentifier, string $path, bool $recursive = true): array
    {
        $vault = $this->isAiIdentifier($vaultIdentifier)
            ? $this->resolveOrProvisionAiVault()
            : $this->resolveAccessibleVault($vaultIdentifier);

        $this->assertAiVault($vault, 'create_folder');

        $normalized = $this->normalizeRelativePath($path, 'path', expectMarkdown: false);
        $segments = explode('/', $normalized);

        if (! $recursive) {
            $leaf = (string) array_pop($segments);
            $parent = implode('/', $segments);

            try {
                $relative = $this->notes->createFolder($vault, $parent === '' ? null : $parent, $leaf);
            } catch (NoteOperationException $e) {
                throw McpKosException::operationFailed($e->getMessage());
            }

            return [
                'vault' => $vault->name,
                'vault_uuid' => $vault->uuid,
                'path' => $relative,
                'created_segments' => [$relative],
                'already_existed' => false,
            ];
        }

        $parent = '';
        $created = [];

        foreach ($segments as $segment) {
            $current = $parent === '' ? $segment : $parent.'/'.$segment;
            $absolute = $this->files->joinRelative($vault->path, $current);

            if ($this->files->isSymlink($absolute) || ($this->files->exists($absolute) && ! $this->files->isDirectory($absolute))) {
                throw McpKosException::operationFailed("\u{201c}{$current}\u{201d} already exists and is not a folder.");
            }

            if (! $this->files->isDirectory($absolute)) {
                try {
                    $this->notes->createFolder($vault, $parent === '' ? null : $parent, $segment);
                } catch (NoteOperationException $e) {
                    throw McpKosException::operationFailed($e->getMessage());
                }

                $created[] = $current;
            }

            $parent = $current;
        }

        return [
            'vault' => $vault->name,
            'vault_uuid' => $vault->uuid,
            'path' => $normalized,
            'created_segments' => $created,
            'already_existed' => $created === [],
        ];
    }

    /**
     * Deletes an empty folder in the AI vault.
     *
     * @return array{vault: string, vault_uuid: string, path: string, deleted: bool}
     *
     * @throws McpKosException
     */
    public function deleteFolder(string $vaultIdentifier, string $path): array
    {
        $vault = $this->resolveAccessibleVault($vaultIdentifier);
        $this->assertAiVault($vault, 'delete_folder');

        $normalized = $this->normalizeFolderPath($path, 'path');

        if ($normalized === '') {
            throw McpKosException::invalidPath('path');
        }

        try {
            $this->notes->deleteFolder($vault, $normalized);
        } catch (NoteOperationException $e) {
            throw McpKosException::operationFailed($e->getMessage());
        }

        return [
            'vault' => $vault->name,
            'vault_uuid' => $vault->uuid,
            'path' => $normalized,
            'deleted' => true,
        ];
    }

    /**
     * Scaffolds an AI project workspace (`AI/<name>/`, `AI/<name>/skills/`,
     * `overview.md`, `tasks.md`, `receipts.md`) idempotently without overwriting edits.
     *
     * @return array<string, mixed>
     *
     * @throws McpKosException
     */
    public function initAiProject(string $name, ?string $description = null, ?string $stack = null): array
    {
        try {
            $this->notes->assertValidFolderName($name);
            $this->paths->assertValidNoteSegment($name);
        } catch (NoteOperationException|InvalidStorageRootException) {
            throw McpKosException::invalidPath('name');
        }

        $aiVault = $this->resolveOrProvisionAiVault();
        $this->createFolder(self::AI_VAULT_NAME, $name.'/'.self::SKILLS_FOLDER, recursive: true);

        try {
            $this->index->reconcile($aiVault, IndexMode::Quick);
        } catch (NoteOperationException $e) {
            throw McpKosException::operationFailed($e->getMessage());
        }

        $existingPathsLower = $aiVault->notes()
            ->pluck('relative_path')
            ->map(fn (string $p): string => mb_strtolower($p))
            ->all();

        $starters = $this->starterProjectFiles($name, $description, $stack);
        $created = [];
        $existing = [];

        foreach ($starters as $filename => $content) {
            $relative = $name.'/'.$filename;
            $absolute = $this->files->joinRelative($aiVault->path, $relative);

            if ($this->files->exists($absolute) || in_array(mb_strtolower($relative), $existingPathsLower, true)) {
                $existing[] = $relative;

                continue;
            }

            try {
                $this->notes->createWithContent($aiVault, $name, $filename, $content, applyTemplate: false);
            } catch (NoteOperationException $e) {
                throw McpKosException::operationFailed($e->getMessage());
            }

            $created[] = $relative;
        }

        try {
            $this->index->reconcile($aiVault, IndexMode::Quick);
        } catch (NoteOperationException $e) {
            throw McpKosException::operationFailed($e->getMessage());
        }

        return [
            'vault' => $aiVault->name,
            'vault_uuid' => $aiVault->uuid,
            'project' => $name,
            'project_path' => $name,
            'skills_path' => $name.'/'.self::SKILLS_FOLDER,
            'created' => $created,
            'existing' => $existing,
        ];
    }

    /**
     * Loads KOS session-bootstrap context: `AI/about-me.md` (truncated cleanly at
     * `$maxChars`), accessible/excluded vaults summary, and discovered AI projects.
     *
     * @return array<string, mixed>
     *
     * @throws McpKosException
     */
    public function getKosContext(int $maxChars = self::DEFAULT_CONTEXT_MAX_CHARS): array
    {
        $maxChars = max(100, min(50000, $maxChars));
        $vaultsSummary = $this->compactVaultsSummary();
        $aiVault = $this->findVault(self::AI_VAULT_NAME);

        if ($aiVault === null) {
            return [
                'found' => false,
                'vault_provisioned' => false,
                'accessible' => false,
                'vault' => self::AI_VAULT_NAME,
                'vault_uuid' => null,
                'uuid' => null,
                'relative_path' => self::ABOUT_ME_FILENAME,
                'file_hash' => null,
                'char_count' => 0,
                'returned_char_count' => 0,
                'truncated' => false,
                'content' => null,
                'vaults_summary' => $vaultsSummary,
                'ai_projects' => [],
                'note' => 'AI workspace is not provisioned yet. Create AI/about-me.md or provision the AI workspace in Settings -> AI & MCP.',
            ];
        }

        $this->vaults->refreshStatus($aiVault);

        if ($aiVault->is_encrypted || $aiVault->status === VaultStatus::Missing) {
            return [
                'found' => false,
                'vault_provisioned' => true,
                'accessible' => false,
                'vault' => $aiVault->name,
                'vault_uuid' => $aiVault->uuid,
                'uuid' => null,
                'relative_path' => self::ABOUT_ME_FILENAME,
                'file_hash' => null,
                'char_count' => 0,
                'returned_char_count' => 0,
                'truncated' => false,
                'content' => null,
                'vaults_summary' => $vaultsSummary,
                'ai_projects' => [],
                'note' => $aiVault->is_encrypted
                    ? 'AI vault is encrypted and excluded from MCP access.'
                    : 'AI vault folder is missing on disk.',
            ];
        }

        try {
            $this->index->reconcile($aiVault, IndexMode::Quick);
            $browse = $this->index->browse($aiVault);
        } catch (NoteOperationException $e) {
            throw McpKosException::operationFailed($e->getMessage());
        }

        $aiProjects = array_values(array_filter(
            $browse['folders'],
            fn (string $folder): bool => $folder !== ''
                && ! str_contains($folder, '/')
                && strcasecmp($folder, self::SKILLS_FOLDER) !== 0,
        ));

        $aboutMeNote = $aiVault->notes()
            ->whereRaw('LOWER(relative_path) = ?', [self::ABOUT_ME_FILENAME])
            ->first();

        if ($aboutMeNote === null) {
            return [
                'found' => false,
                'vault_provisioned' => true,
                'accessible' => true,
                'vault' => $aiVault->name,
                'vault_uuid' => $aiVault->uuid,
                'uuid' => null,
                'relative_path' => self::ABOUT_ME_FILENAME,
                'file_hash' => null,
                'char_count' => 0,
                'returned_char_count' => 0,
                'truncated' => false,
                'content' => null,
                'vaults_summary' => $vaultsSummary,
                'ai_projects' => $aiProjects,
                'note' => 'AI/about-me.md does not exist yet. Create it with create_note to store durable KOS core memory.',
            ];
        }

        $aboutMeNote->setRelation('vault', $aiVault);
        $preview = $this->notes->preview($aboutMeNote);

        if ($preview['state'] !== 'ok' || ! $preview['editable']) {
            $reason = $preview['read_only_reason'] ?? $preview['state'];

            throw McpKosException::noteUnreadable($aboutMeNote->relative_path, (string) $reason);
        }

        $fullContent = (string) $preview['content'];
        $totalChars = mb_strlen($fullContent);
        $truncated = $totalChars > $maxChars;
        $content = $truncated ? mb_substr($fullContent, 0, $maxChars) : $fullContent;

        return [
            'found' => true,
            'vault_provisioned' => true,
            'accessible' => true,
            'vault' => $aiVault->name,
            'vault_uuid' => $aiVault->uuid,
            'uuid' => $aboutMeNote->uuid,
            'relative_path' => $aboutMeNote->relative_path,
            'file_hash' => (string) $preview['base_hash'],
            'char_count' => $totalChars,
            'returned_char_count' => mb_strlen($content),
            'truncated' => $truncated,
            'content' => $content,
            'vaults_summary' => $vaultsSummary,
            'ai_projects' => $aiProjects,
        ];
    }

    /**
     * Returns a token-lean summary of registered vaults for session bootstrap without disk scans.
     *
     * @return list<array{name: string, tier: string, accessible: bool}>
     */
    private function compactVaultsSummary(): array
    {
        return array_values(Vault::query()
            ->orderBy('name')
            ->get(['id', 'name', 'status', 'is_encrypted'])
            ->map(function (Vault $vault): array {
                $accessible = $vault->status === VaultStatus::Active && ! $vault->is_encrypted;
                $tier = match (true) {
                    ! $accessible => 'excluded',
                    $this->isAiVault($vault) => 'ai_workspace',
                    default => 'personal',
                };

                return [
                    'name' => $vault->name,
                    'tier' => $tier,
                    'accessible' => $accessible,
                ];
            })
            ->all());
    }

    /**
     * Provisions the dedicated AI vault and optional starter `AI/about-me.md`.
     *
     * @return array{vault: array<string, mixed>, memory_created: bool}
     *
     * @throws McpKosException
     */
    public function provisionAiWorkspace(bool $createStarterMemory = true): array
    {
        $aiVault = $this->resolveOrProvisionAiVault();
        $this->createFolder(self::AI_VAULT_NAME, self::SKILLS_FOLDER, recursive: true);

        try {
            $this->index->reconcile($aiVault, IndexMode::Quick);
        } catch (NoteOperationException $e) {
            throw McpKosException::operationFailed($e->getMessage());
        }

        $memoryCreated = false;

        if ($createStarterMemory) {
            $existsInDb = $aiVault->notes()
                ->get()
                ->contains(fn (Note $n): bool => mb_strtolower($n->relative_path) === self::ABOUT_ME_FILENAME);
            $existsOnDisk = $this->files->exists($this->files->joinRelative($aiVault->path, self::ABOUT_ME_FILENAME));

            if (! $existsInDb && ! $existsOnDisk) {
                try {
                    $this->notes->createWithContent(
                        $aiVault,
                        null,
                        self::ABOUT_ME_FILENAME,
                        $this->starterAboutMeContent(),
                        applyTemplate: false,
                    );
                    $memoryCreated = true;
                } catch (NoteOperationException $e) {
                    throw McpKosException::operationFailed($e->getMessage());
                }
            }
        }

        return [
            'vault' => $this->vaults->present($aiVault),
            'memory_created' => $memoryCreated,
        ];
    }

    /**
     * Lists reusable skills across active unencrypted vaults (`<vault>/skills/*.md`
     * and `AI/<project>/skills/*.md`).
     *
     * @return array{skills: list<array<string, mixed>>, count: int}
     *
     * @throws McpKosException
     */
    public function listSkills(?string $vaultIdentifier = null, ?string $project = null): array
    {
        $targetProject = $project !== null && trim($project) !== ''
            ? $this->normalizeFolderPath(trim($project), 'project')
            : null;

        if ($targetProject !== null && str_contains($targetProject, '/')) {
            throw McpKosException::invalidPath('project');
        }

        if ($targetProject !== null) {
            if ($vaultIdentifier !== null && trim($vaultIdentifier) !== '' && ! $this->isAiIdentifier($vaultIdentifier)) {
                throw McpKosException::permissionDenied('list_skills (project scope)', $vaultIdentifier);
            }

            if ($this->findVault(self::AI_VAULT_NAME) === null) {
                return ['skills' => [], 'count' => 0];
            }

            $targetVaults = [$this->resolveAccessibleVault(self::AI_VAULT_NAME)];
        } elseif ($vaultIdentifier !== null && trim($vaultIdentifier) !== '') {
            if ($this->isAiIdentifier($vaultIdentifier) && $this->findVault(self::AI_VAULT_NAME) === null) {
                return ['skills' => [], 'count' => 0];
            }

            $targetVaults = [$this->resolveAccessibleVault($vaultIdentifier)];
        } else {
            $targetVaults = array_values(array_filter(
                $this->vaults->all(),
                fn (Vault $v): bool => $v->status === VaultStatus::Active && ! $v->is_encrypted,
            ));
        }

        $skills = [];

        foreach ($targetVaults as $vault) {
            try {
                $this->index->reconcile($vault, IndexMode::Quick);
            } catch (NoteOperationException $e) {
                throw McpKosException::operationFailed($e->getMessage());
            }

            $isAi = $this->isAiVault($vault);
            $notes = $vault->notes()->orderBy('relative_path')->get();

            foreach ($notes as $note) {
                $parsed = $this->parseSkillLocation($note->relative_path, $isAi);

                if ($parsed === null) {
                    continue;
                }

                if ($targetProject !== null) {
                    if ($parsed['scope'] !== 'project' || mb_strtolower((string) $parsed['project']) !== mb_strtolower($targetProject)) {
                        continue;
                    }
                }

                $previewText = $this->extractSkillPreview($vault, $note);
                $slug = $parsed['slug'];
                $resourceUri = null;

                if (! str_contains($slug, '/')) {
                    $resourceUri = $parsed['scope'] === 'project'
                        ? 'mdvault://projects/'.rawurlencode((string) $parsed['project']).'/skills/'.rawurlencode($slug)
                        : 'mdvault://skills/'.rawurlencode($vault->name).'/'.rawurlencode($slug);
                }

                $skills[] = [
                    'slug' => $slug,
                    'title' => $note->title,
                    'vault' => $vault->name,
                    'vault_uuid' => $vault->uuid,
                    'scope' => $parsed['scope'],
                    'project' => $parsed['project'],
                    'relative_path' => $note->relative_path,
                    'uuid' => $note->uuid,
                    'file_hash' => $note->file_hash,
                    'file_size' => $note->file_size,
                    'preview' => $previewText,
                    'resource_uri' => $resourceUri,
                ];
            }
        }

        return [
            'skills' => $skills,
            'count' => count($skills),
        ];
    }

    /**
     * Creates or updates a skill file under `AI/skills/`, `AI/<project>/skills/`,
     * or `<personal-vault>/skills/` (where the personal vault's `skills/` folder
     * must already exist). Overwriting an existing skill requires `file_hash`.
     *
     * @return array<string, mixed>
     *
     * @throws McpKosException
     */
    public function saveSkill(
        string $name,
        string $content,
        ?string $project = null,
        ?string $vaultIdentifier = null,
        ?string $fileHash = null,
    ): array {
        try {
            $stem = $this->notes->assertValidNoteName($name);
            $this->paths->assertValidNoteSegment($stem);
        } catch (NoteOperationException|InvalidStorageRootException) {
            throw McpKosException::invalidPath('name');
        }

        if ($fileHash !== null) {
            $this->assertValidSha256($fileHash);
        }

        $targetProject = $project !== null && trim($project) !== '' ? trim($project) : null;

        if ($targetProject !== null) {
            try {
                $this->paths->assertValidNoteSegment($targetProject);
            } catch (InvalidStorageRootException) {
                throw McpKosException::invalidPath('project');
            }

            if ($vaultIdentifier !== null && trim($vaultIdentifier) !== '' && ! $this->isAiIdentifier($vaultIdentifier)) {
                throw McpKosException::permissionDenied('save_skill (project)', $vaultIdentifier);
            }

            $vault = $this->resolveOrProvisionAiVault();
            $skillsFolder = $targetProject.'/'.self::SKILLS_FOLDER;
            $this->createFolder(self::AI_VAULT_NAME, $skillsFolder, recursive: true);
        } else {
            $targetVaultName = ($vaultIdentifier !== null && trim($vaultIdentifier) !== '')
                ? trim($vaultIdentifier)
                : self::AI_VAULT_NAME;

            if ($this->isAiIdentifier($targetVaultName)) {
                $vault = $this->resolveOrProvisionAiVault();
                $skillsFolder = self::SKILLS_FOLDER;
                $this->createFolder(self::AI_VAULT_NAME, $skillsFolder, recursive: true);
            } else {
                $vault = $this->resolveAccessibleVault($targetVaultName);
                $skillsFolder = self::SKILLS_FOLDER;
                $skillsAbsolute = $this->files->joinRelative($vault->path, self::SKILLS_FOLDER);

                if (! $this->files->isDirectory($skillsAbsolute) || $this->files->isSymlink($skillsAbsolute)) {
                    throw McpKosException::folderNotFound(self::SKILLS_FOLDER, $vault->name);
                }
            }
        }

        try {
            $this->index->reconcile($vault, IndexMode::Quick);
        } catch (NoteOperationException $e) {
            throw McpKosException::operationFailed($e->getMessage());
        }

        $relative = $skillsFolder.'/'.$stem.'.md';
        $lower = mb_strtolower($relative);
        $existing = $vault->notes()->get()->first(
            fn (Note $candidate): bool => mb_strtolower($candidate->relative_path) === $lower,
        );

        if ($existing !== null) {
            $existing->setRelation('vault', $vault);
            $preview = $this->notes->preview($existing);

            if ($preview['state'] !== 'ok' || ! $preview['editable']) {
                $reason = $preview['read_only_reason'] ?? $preview['state'];

                throw McpKosException::noteUnreadable($existing->relative_path, (string) $reason);
            }

            $currentHash = (string) $preview['base_hash'];

            if ($fileHash === null) {
                throw McpKosException::hashRequiredForExistingSkill($existing->relative_path, $currentHash);
            }

            $saved = $this->updateNote($vault->name, null, $existing->uuid, $content, $fileHash);
            $action = 'updated';
        } else {
            try {
                $note = $this->notes->createWithContent($vault, $skillsFolder, $stem, $content, applyTemplate: false);
            } catch (NoteOperationException $e) {
                throw McpKosException::operationFailed($e->getMessage());
            }

            $note->setRelation('vault', $vault);
            $saved = [
                'vault' => $vault->name,
                'vault_uuid' => $vault->uuid,
                ...$this->notes->present($note),
            ];
            $action = 'created';
        }

        $charCount = mb_strlen($content);
        $resourceUri = $targetProject !== null
            ? 'mdvault://projects/'.rawurlencode($targetProject).'/skills/'.rawurlencode($stem)
            : 'mdvault://skills/'.rawurlencode($vault->name).'/'.rawurlencode($stem);

        return [
            ...$saved,
            'skill' => $stem,
            'scope' => $targetProject !== null ? 'project' : 'vault',
            'project' => $targetProject,
            'action' => $action,
            'char_count' => $charCount,
            'resource_uri' => $resourceUri,
            'length_warning' => $charCount > self::MAX_SKILL_RECOMMENDED_CHARS
                ? 'Skill exceeds '.self::MAX_SKILL_RECOMMENDED_CHARS.' characters; consider keeping reusable skills concise.'
                : null,
        ];
    }

    /**
     * Reads a vault-level skill resource (`mdvault://skills/{vault}/{skill}`).
     *
     * @throws McpKosException
     */
    public function readSkillResource(string $vaultIdentifier, string $skillSlug): string
    {
        $decodedVault = rawurldecode($vaultIdentifier);
        $decodedSkill = rawurldecode($skillSlug);

        try {
            $stem = $this->notes->assertValidNoteName($decodedSkill);
            $this->paths->assertValidNoteSegment($stem);
        } catch (NoteOperationException|InvalidStorageRootException) {
            throw McpKosException::invalidPath('skill');
        }

        $note = $this->readNote($decodedVault, self::SKILLS_FOLDER.'/'.$stem.'.md', null);

        return (string) $note['content'];
    }

    /**
     * Reads a project-level skill resource (`mdvault://projects/{project}/skills/{skill}`).
     *
     * @throws McpKosException
     */
    public function readProjectSkillResource(string $project, string $skillSlug): string
    {
        $decodedProject = rawurldecode($project);
        $decodedSkill = rawurldecode($skillSlug);

        try {
            $this->paths->assertValidNoteSegment($decodedProject);
            $stem = $this->notes->assertValidNoteName($decodedSkill);
            $this->paths->assertValidNoteSegment($stem);
        } catch (NoteOperationException|InvalidStorageRootException) {
            throw McpKosException::invalidPath('skill');
        }

        $note = $this->readNote(
            self::AI_VAULT_NAME,
            $decodedProject.'/'.self::SKILLS_FOLDER.'/'.$stem.'.md',
            null,
        );

        return (string) $note['content'];
    }

    /**
     * Reads a note resource (`mdvault://notes/{vault}/{uuid}`).
     *
     * @throws McpKosException
     */
    public function readNoteResource(string $vaultIdentifier, string $uuid): string
    {
        $decodedVault = rawurldecode($vaultIdentifier);
        $decodedUuid = rawurldecode($uuid);
        $note = $this->readNote($decodedVault, null, $decodedUuid);

        return (string) $note['content'];
    }

    private function findVault(string $identifier): ?Vault
    {
        $trimmed = trim($identifier);

        if ($trimmed === '') {
            return null;
        }

        $vaults = Vault::query()->get();

        if (Str::isUuid($trimmed)) {
            $lowerUuid = strtolower($trimmed);
            $byUuid = $vaults->first(fn (Vault $v): bool => strtolower($v->uuid) === $lowerUuid);

            if ($byUuid !== null) {
                return $byUuid;
            }
        }

        $lowerName = mb_strtolower($trimmed);

        return $vaults->first(fn (Vault $v): bool => mb_strtolower($v->name) === $lowerName);
    }

    private function isAiIdentifier(string $identifier): bool
    {
        return mb_strtolower(trim($identifier)) === 'ai';
    }

    /**
     * @throws McpKosException
     */
    private function assertValidSha256(string $hash): void
    {
        if (preg_match('/^[0-9a-f]{64}$/i', $hash) !== 1) {
            throw McpKosException::invalidInput('file_hash must be a 64-character SHA-256 hex string returned by read_note.');
        }
    }

    /**
     * @return array{0: string, 1: string} [folder, filename]
     */
    private function splitRelativePath(string $relative): array
    {
        $pos = strrpos($relative, '/');

        if ($pos === false) {
            return ['', $relative];
        }

        return [substr($relative, 0, $pos), substr($relative, $pos + 1)];
    }

    /**
     * @param  list<string>  $folders
     */
    private function matchFolderInList(array $folders, string $target): ?string
    {
        if (in_array($target, $folders, true)) {
            return $target;
        }

        $lower = mb_strtolower($target);

        foreach ($folders as $folder) {
            if (mb_strtolower($folder) === $lower) {
                return $folder;
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $tree
     * @return list<array<string, mixed>>
     */
    private function extractFolderChildren(array $tree, string $targetFolder): array
    {
        foreach ($tree as $node) {
            if (($node['type'] ?? null) !== 'folder') {
                continue;
            }

            $nodePath = (string) ($node['path'] ?? '');

            if ($nodePath === $targetFolder) {
                /** @var list<array<string, mixed>> $children */
                $children = $node['children'] ?? [];

                return $children;
            }

            if ($nodePath !== '' && str_starts_with($targetFolder, $nodePath.'/')) {
                /** @var list<array<string, mixed>> $children */
                $children = $node['children'] ?? [];

                return $this->extractFolderChildren($children, $targetFolder);
            }
        }

        return [];
    }

    /**
     * @param  list<array<string, mixed>>  $tree
     */
    private function countNotesInTree(array $tree): int
    {
        $count = 0;

        foreach ($tree as $node) {
            if (($node['type'] ?? null) === 'note') {
                $count++;
            } elseif (($node['type'] ?? null) === 'folder') {
                /** @var list<array<string, mixed>> $children */
                $children = $node['children'] ?? [];
                $count += $this->countNotesInTree($children);
            }
        }

        return $count;
    }

    /**
     * @return array{scope: 'vault'|'project', project: ?string, slug: string}|null
     */
    private function parseSkillLocation(string $relativePath, bool $isAiVault): ?array
    {
        $segments = explode('/', $relativePath);

        if (count($segments) >= 2 && strcasecmp($segments[0], self::SKILLS_FOLDER) === 0) {
            $slugWithExt = implode('/', array_slice($segments, 1));
            $slug = preg_replace('/\.md$/i', '', $slugWithExt, 1) ?? $slugWithExt;

            return [
                'scope' => 'vault',
                'project' => null,
                'slug' => $slug,
            ];
        }

        if ($isAiVault && count($segments) >= 3 && strcasecmp($segments[1], self::SKILLS_FOLDER) === 0) {
            $slugWithExt = implode('/', array_slice($segments, 2));
            $slug = preg_replace('/\.md$/i', '', $slugWithExt, 1) ?? $slugWithExt;

            return [
                'scope' => 'project',
                'project' => $segments[0],
                'slug' => $slug,
            ];
        }

        return null;
    }

    private function extractSkillPreview(Vault $vault, Note $note): ?string
    {
        $absolute = $this->files->joinRelative($vault->path, $note->relative_path);

        if (! $this->files->isFile($absolute) || $this->files->isSymlink($absolute)) {
            return null;
        }

        $size = $this->files->size($absolute);

        if ($size === null || $size > NoteService::PREVIEW_LIMIT) {
            return null;
        }

        $raw = $this->files->read($absolute);

        if ($raw === null) {
            return null;
        }

        $doc = $this->markdown->decode($raw);

        if (! $doc->validUtf8) {
            return null;
        }

        foreach (explode("\n", $doc->body) as $line) {
            $trimmed = trim($line);

            if ($trimmed !== '') {
                return mb_strlen($trimmed) > 160 ? mb_substr($trimmed, 0, 157).'...' : $trimmed;
            }
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function starterProjectFiles(string $name, ?string $description, ?string $stack): array
    {
        $date = date('Y-m-d');
        $descLine = $description !== null && trim($description) !== ''
            ? trim($description)
            : "Project workspace for {$name}.";
        $stackLine = $stack !== null && trim($stack) !== ''
            ? trim($stack)
            : '_Unspecified_';

        $overview = <<<MD
---
title: "{$name} — Overview"
project: "{$name}"
created: {$date}
---

# {$name}

{$descLine}

## Stack & Context
- **Stack**: {$stackLine}

## Goals
- Define core milestones and deliverables.

## Key Decisions
- Record architectural and workflow decisions here.
MD;

        $tasks = <<<MD
---
title: "{$name} — Tasks"
project: "{$name}"
created: {$date}
---

# {$name} Tasks

## Active
- [ ] Review project overview and define first milestone

## Backlog

## Completed
MD;

        $receipts = <<<MD
---
title: "{$name} — Receipts"
project: "{$name}"
created: {$date}
---

# {$name} Receipts

## {$date}
- Initialized project workspace in `AI/{$name}/`.
MD;

        return [
            'overview.md' => $overview."\n",
            'tasks.md' => $tasks."\n",
            'receipts.md' => $receipts."\n",
        ];
    }

    private function starterAboutMeContent(): string
    {
        $date = date('Y-m-d');

        return <<<MD
---
title: "About Me (KOS Core Memory)"
updated: {$date}
---

# About Me

This note is MDVault's durable KOS core memory (`AI/about-me.md`). AI assistants read the top of this file at session start via `get_kos_context` and `mdvault://memory/about-me`.

## Identity & Preferences
- **Role / Focus**: _Add your role, primary work, and preferred working style._
- **Communication Style**: _Concise, technical, direct._

## Active Priorities
- _List current high-level goals or active projects._

## Conventions
- Keep durable facts and preferences here; keep project-specific notes in `AI/<project>/`.
- Keep reusable procedures in `AI/skills/` or `AI/<project>/skills/`.

MD;
    }
}
