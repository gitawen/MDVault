<?php

namespace App\Services;

use App\Enums\BackupScope;
use App\Enums\IndexMode;
use App\Enums\RestoreAction;
use App\Enums\VaultStatus;
use App\Exceptions\BackupException;
use App\Exceptions\InvalidStorageRootException;
use App\Exceptions\NoteOperationException;
use App\Models\Backup;
use App\Models\Note;
use App\Models\Vault;
use App\Support\BackupInspection;
use App\Support\BackupResult;
use App\Support\RestoreResult;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Backup creation, inspection and restore (ADRs `backup-archive-format`,
 * `backup-restore-semantics`). No raw filesystem functions: all disk access
 * goes through FileStorageService/ArchiveService/FileHashService.
 */
final class BackupService
{
    public const MANIFEST_NAME = 'manifest.json';

    public const VAULTS_PREFIX = 'vaults/';

    public const FORMAT = 'mdvault-backup';

    public const FORMAT_VERSION = 1;

    public const DATABASE_VERSION = 1;

    public const MANIFEST_MAX_BYTES = 16 * 1024 * 1024;

    public const MAX_ENTRIES = 100_000;

    public const SPACE_MARGIN_BYTES = 64 * 1024 * 1024;

    /**
     * Absolute sanity caps on a manifest entry's declared size and the
     * backup's declared total, independent of free space or the entry
     * count. Without these, a hostile archive can declare an astronomical
     * `file_size` for a single, well-compressed deflate entry and force
     * `inspect()` to stream-hash an unbounded amount of data (a zip-bomb
     * DoS) before any free-space-style gate would ever apply. Chosen high
     * enough that no legitimate note or attachment should ever hit them.
     */
    public const MAX_DECLARED_FILE_BYTES = 10 * 1024 * 1024 * 1024; // 10 GiB

    public const MAX_DECLARED_TOTAL_BYTES = 100 * 1024 * 1024 * 1024; // 100 GiB

    /**
     * QA round 2 (`mdv-p6`): the absolute caps above alone don't bound
     * Stage 8's worst case to anything small — a small, well-compressed
     * physical archive can declare a size anywhere under either cap and
     * still reach Stage 8. This gate targets the common zip-bomb mechanism
     * directly: in Stage 7, once an expected entry's uncompressed size is
     * confirmed to match the manifest, an uncompressed-to-compressed ratio
     * above this value (using `compressed_size` from `ArchiveService::
     * entries()`, i.e. the ZIP central directory's `statIndex()` figure)
     * is rejected before Stage 8 ever runs.
     *
     * QA round 3 (`mdv-p6`, QA-05): this is a best-effort early-rejection
     * HEURISTIC, not a guarantee — it only evaluates entries individually,
     * and only above the 1 MiB floor below. Its real, verified bypass is
     * sub-floor chunking: splitting a large, highly compressible payload
     * into several entries each AT or under `COMPRESSION_RATIO_MIN_BYTES`
     * evades this gate entirely, with completely genuine ZIP metadata — no
     * forgery needed. (QA round 3 also raised forging the central
     * directory's `compressed_size` directly, e.g. via a 4-byte edit; that
     * specific technique was tested against this codebase's actual read
     * path and does NOT work here: `ArchiveService` always opens with
     * `ZipArchive::CHECKCONS`, and libzip's consistency check rejects ANY
     * mismatch it finds between the central directory's record for an
     * entry and that entry's own local file header — in either direction,
     * not only an inflated one — so a forged *increase* (the direction that
     * would lower the reported ratio) or a forged *decrease* are both
     * rejected as "not a valid ZIP" before the manifest is even read — a
     * stronger, pre-existing protection than this gate. QA round 4
     * confirmed this empirically for both directions, the decrease case
     * failing with `ER_INCONS`.) Either way, on its own this gate
     * does not bound anything against a deliberately crafted archive. The
     * actual unconditional bound is the aggregate physical-size gate in
     * `validateArchive()` (Stage 7.5, using `AGGREGATE_RATIO_ALLOWANCE_
     * BYTES` below), combined with `hashStream()`'s per-entry
     * declared-size cap — see those for the real guarantee.
     */
    public const MAX_COMPRESSION_RATIO = 250;

    /**
     * The ratio gate only applies to entries whose uncompressed size
     * exceeds this floor, so small, ordinarily-compressible files (short
     * notes, empty files) are never affected by it. This is also exactly
     * the gap a sub-floor-chunked payload exploits (see
     * `MAX_COMPRESSION_RATIO`'s docblock) — by design, since a floor is
     * needed to avoid punishing small legitimately-compressible files.
     */
    public const COMPRESSION_RATIO_MIN_BYTES = 1024 * 1024; // 1 MiB

    /**
     * QA round 3 (`mdv-p6`, QA-05): slack added on top of `physicalBytes *
     * MAX_COMPRESSION_RATIO` in the aggregate gate (`validateArchive()`
     * Stage 7.5), so a small archive of ordinary, highly compressible
     * Markdown notes is never rejected purely from legitimate compression.
     * Chosen generously relative to the size of backups this app actually
     * produces.
     */
    public const AGGREGATE_RATIO_ALLOWANCE_BYTES = 64 * 1024 * 1024; // 64 MiB

    public const STALE_TEMP_SECONDS = 3600;

    public const NAME_SUFFIX_LIMIT = 20;

    public const MAX_PROBLEMS = 20;

    /**
     * @var list<string>
     */
    private const WINDOWS_RESERVED_NAMES = [
        'CON', 'PRN', 'AUX', 'NUL',
        'COM1', 'COM2', 'COM3', 'COM4', 'COM5', 'COM6', 'COM7', 'COM8', 'COM9',
        'LPT1', 'LPT2', 'LPT3', 'LPT4', 'LPT5', 'LPT6', 'LPT7', 'LPT8', 'LPT9',
    ];

    public function __construct(
        private readonly VaultService $vaults,
        private readonly VaultIndexService $index,
        private readonly FileStorageService $files,
        private readonly FileHashService $hashes,
        private readonly ArchiveService $archives,
        private readonly StoragePathService $paths,
        private readonly DatabaseManager $database,
        private readonly Repository $config,
        /**
         * Defaults to `AGGREGATE_RATIO_ALLOWANCE_BYTES` so the container
         * resolves normal production behaviour with no wiring. Exists as a
         * constructor seam (QA round 3, `mdv-p6`, QA-05) so tests can lower
         * the aggregate gate's allowance and exercise it with small
         * fixtures, instead of building a 64 MiB+ archive to clear the
         * default allowance.
         */
        private readonly int $aggregateRatioAllowanceBytes = self::AGGREGATE_RATIO_ALLOWANCE_BYTES,
    ) {}

    // =========================================================================
    // Backup creation (FR-01 to FR-07)
    // =========================================================================

    public function suggestedFilename(?Vault $vault): string
    {
        $timestamp = now()->format('Y-m-d-His');

        return $vault === null
            ? "MDVault-Backup-{$timestamp}.zip"
            : "MDVault-Backup-{$vault->name}-{$timestamp}.zip";
    }

    /**
     * Default folder + suggested name. With $create, creates the default
     * folder (non-recursive) when missing.
     *
     * @throws BackupException
     */
    public function defaultDestination(?Vault $vault, bool $create): string
    {
        $dir = $this->paths->defaultBackupDirectory();

        if ($create) {
            if (! $this->files->isDirectory($dir) && ! $this->files->makeDirectory($dir)) {
                throw BackupException::destinationFolderUnavailable($dir);
            }

            return $dir.DIRECTORY_SEPARATOR.$this->suggestedFilename($vault);
        }

        if (! $this->files->isDirectory($dir)) {
            return dirname($dir).DIRECTORY_SEPARATOR.$this->suggestedFilename($vault);
        }

        return $dir.DIRECTORY_SEPARATOR.$this->suggestedFilename($vault);
    }

    /**
     * $vault null = all vaults.
     *
     * @throws BackupException
     */
    public function create(?Vault $vault, string $destination): BackupResult
    {
        @set_time_limit(0);

        $target = $this->assertDestination($destination);
        $dir = dirname($target);

        [$selected, $skipped] = $this->planBackupVaults($vault);

        /** @var list<array<string, mixed>> $manifestVaults */
        $manifestVaults = [];
        /** @var list<string> $zipDirectories */
        $zipDirectories = [];
        /** @var list<array{name: string, source: string}> $zipFiles */
        $zipFiles = [];
        $vaultCount = 0;
        $noteCount = 0;
        $fileCount = 0;
        $totalBytes = 0;

        foreach ($selected as $selectedVault) {
            try {
                $reconcileResult = $this->index->reconcile($selectedVault, IndexMode::Quick);
            } catch (NoteOperationException) {
                if ($vault !== null) {
                    throw BackupException::vaultMissing($selectedVault->name);
                }

                $skipped[] = $selectedVault->name;

                continue;
            }

            if ($reconcileResult->stale) {
                throw BackupException::vaultBusy($selectedVault->name);
            }

            $scan = $this->files->scan(
                $selectedVault->path,
                fn (string $relative, string $name, bool $isDir): bool => ! $this->index->isIgnoredName($name, $isDir),
            );

            if ($scan['unreadable'] !== []) {
                throw BackupException::unreadable($selectedVault->name, $scan['unreadable']);
            }

            $rows = $selectedVault->notes()->get()->keyBy('relative_path');

            $manifestNotes = [];
            $manifestFiles = [];
            $vaultBytes = 0;

            foreach ($scan['files'] as $file) {
                $relativePath = $file['path'];

                if (! mb_check_encoding($relativePath, 'UTF-8')) {
                    throw BackupException::unsupportedName($selectedVault->name, $relativePath);
                }

                $absolute = $this->files->joinRelative($selectedVault->path, $relativePath);
                $hash = $this->hashes->hashFile($absolute);
                $size = $this->files->size($absolute);

                if ($hash === null || $size === null) {
                    throw BackupException::unreadable($selectedVault->name, [$relativePath]);
                }

                if ($this->index->isIndexableFileName(basename($relativePath))) {
                    $row = $rows->get($relativePath);

                    if ($row === null) {
                        throw BackupException::vaultChanged($selectedVault->name);
                    }

                    $manifestNotes[] = [
                        'uuid' => $row->uuid,
                        'relative_path' => $relativePath,
                        'file_size' => $size,
                        'file_hash' => $hash,
                        'modified_at' => $file['mtime'],
                        'created_at' => ($row->created_at ?? now())->toIso8601ZuluString(),
                        'updated_at' => ($row->updated_at ?? now())->toIso8601ZuluString(),
                    ];
                    $noteCount++;
                } else {
                    $manifestFiles[] = [
                        'relative_path' => $relativePath,
                        'file_size' => $size,
                        'file_hash' => $hash,
                        'modified_at' => $file['mtime'],
                    ];
                    $fileCount++;
                }

                $zipFiles[] = ['name' => self::VAULTS_PREFIX."{$selectedVault->name}/{$relativePath}", 'source' => $absolute];
                $vaultBytes += $size;
            }

            $zipDirectories[] = self::VAULTS_PREFIX."{$selectedVault->name}/";

            foreach ($scan['directories'] as $directory) {
                $zipDirectories[] = self::VAULTS_PREFIX."{$selectedVault->name}/{$directory}/";
            }

            $manifestVaults[] = [
                'uuid' => $selectedVault->uuid,
                'name' => $selectedVault->name,
                'description' => $selectedVault->description,
                'is_encrypted' => false,
                'encryption' => null,
                'archive_path' => self::VAULTS_PREFIX.$selectedVault->name,
                'created_at' => $selectedVault->created_at->toIso8601ZuluString(),
                'directories' => $scan['directories'],
                'notes' => $manifestNotes,
                'files' => $manifestFiles,
            ];

            $vaultCount++;
            $totalBytes += $vaultBytes;
        }

        if ($manifestVaults === []) {
            throw BackupException::nothingToBackUp();
        }

        $manifest = [
            'application' => 'MDVault',
            'format' => self::FORMAT,
            'format_version' => self::FORMAT_VERSION,
            'database_version' => self::DATABASE_VERSION,
            'app_version' => (string) $this->config->get('nativephp.version'),
            'created_at' => now('UTC')->toIso8601ZuluString(),
            'scope' => $vault !== null ? BackupScope::Vault->value : BackupScope::All->value,
            'hash_algorithm' => FileHashService::ALGORITHM,
            'vaults' => $vaultCount,
            'notes' => $noteCount,
            'files' => $fileCount,
            'total_bytes' => $totalBytes,
            'contents' => $manifestVaults,
        ];

        $json = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $this->files->discardStaleTempFiles($dir, FileStorageService::BACKUP_TEMP_PREFIX, self::STALE_TEMP_SECONDS);

        $tmp = $dir.DIRECTORY_SEPARATOR.FileStorageService::BACKUP_TEMP_PREFIX.Str::random(12).'.zip';

        try {
            $this->archives->write($tmp, [self::MANIFEST_NAME => $json], $zipDirectories, $zipFiles);
        } catch (\Throwable $e) {
            if (! $e instanceof BackupException) {
                report($e);
            }

            $this->files->discardTempFile($tmp);

            throw BackupException::archiveWriteFailed($dir);
        }

        [, $problems] = $this->validateArchive($tmp, verifyHashes: true);

        if ($problems !== []) {
            report(new \RuntimeException('Backup verification failed: '.implode(' | ', $problems)));
            $this->files->discardTempFile($tmp);

            throw BackupException::changedDuringBackup();
        }

        $zipHash = $this->hashes->hashFile($tmp);
        $zipSize = $this->files->size($tmp);

        if ($zipHash === null || $zipSize === null || ! $this->files->renameFile($tmp, $target)) {
            $this->files->discardTempFile($tmp);

            throw $this->files->exists($target) ? BackupException::destinationExists($target) : BackupException::archiveWriteFailed($dir);
        }

        $recorded = true;

        try {
            Backup::query()->create([
                'scope' => $vault !== null ? BackupScope::Vault : BackupScope::All,
                'filename' => basename($target),
                'path' => $target,
                'file_size' => $zipSize,
                'file_hash' => $zipHash,
                'format_version' => self::FORMAT_VERSION,
                'vault_count' => $vaultCount,
                'note_count' => $noteCount,
                'file_count' => $fileCount,
                'contents' => array_map(
                    fn (array $v): array => ['uuid' => $v['uuid'], 'name' => $v['name'], 'notes' => count($v['notes'])],
                    $manifestVaults,
                ),
            ]);
        } catch (\Throwable $e) {
            report($e);
            $recorded = false;
        }

        return new BackupResult(
            path: $target,
            filename: basename($target),
            size: $zipSize,
            hash: $zipHash,
            vaultCount: $vaultCount,
            noteCount: $noteCount,
            fileCount: $fileCount,
            skippedVaults: $skipped,
            recorded: $recorded,
        );
    }

    /**
     * @return list<array{uuid: string, scope: string, filename: string, path: string, file_size: int, vault_count: int, note_count: int, file_count: int, vaults: list<string>, created_at: string, exists: bool}>
     */
    public function recent(int $limit = 20): array
    {
        $backups = Backup::query()->latest()->limit($limit)->get()
            ->map(fn (Backup $backup): array => [
                'uuid' => $backup->uuid,
                'scope' => $backup->scope->value,
                'filename' => $backup->filename,
                'path' => $backup->path,
                'file_size' => $backup->file_size,
                'vault_count' => $backup->vault_count,
                'note_count' => $backup->note_count,
                'file_count' => $backup->file_count,
                'vaults' => array_column($backup->contents, 'name'),
                'created_at' => $backup->created_at->toIso8601ZuluString(),
                'exists' => $this->files->isFile($backup->path),
            ])
            ->all();

        return array_values($backups);
    }

    /**
     * @throws BackupException
     */
    private function assertDestination(string $destination): string
    {
        try {
            $normalized = $this->paths->normalize($destination);
        } catch (InvalidStorageRootException) {
            throw BackupException::invalidDestination();
        }

        if (! $this->paths->isAbsolute($normalized)) {
            throw BackupException::invalidDestination();
        }

        $target = str_ends_with(mb_strtolower($normalized), '.zip') ? $normalized : $normalized.'.zip';
        $basename = basename($target);

        if (str_starts_with($basename, '.')) {
            throw BackupException::invalidDestinationName();
        }

        try {
            $this->paths->assertValidFolderName($basename);
        } catch (InvalidStorageRootException) {
            throw BackupException::invalidDestinationName();
        }

        $dir = dirname($target);

        if (! $this->files->isDirectory($dir) || ! $this->files->isWritableDirectory($dir)) {
            throw BackupException::destinationFolderUnavailable($dir);
        }

        if ($this->files->exists($target)) {
            throw BackupException::destinationExists($target);
        }

        $overlap = $this->vaults->overlappingVault($target);

        if ($overlap !== null) {
            throw BackupException::destinationInsideVault($overlap->name);
        }

        return $target;
    }

    /**
     * @return array{0: list<Vault>, 1: list<string>}
     *
     * @throws BackupException
     */
    private function planBackupVaults(?Vault $vault): array
    {
        if ($vault !== null) {
            $this->vaults->refreshStatus($vault);

            if ($vault->status === VaultStatus::Missing) {
                throw BackupException::vaultMissing($vault->name);
            }

            if ($vault->is_encrypted) {
                throw BackupException::encryptedNotSupported($vault->name);
            }

            return [[$vault], []];
        }

        $selected = [];
        $skipped = [];

        foreach ($this->vaults->all() as $candidate) {
            if ($candidate->status === VaultStatus::Missing || $candidate->is_encrypted) {
                $skipped[] = $candidate->name;

                continue;
            }

            $selected[] = $candidate;
        }

        if ($selected === []) {
            throw BackupException::nothingToBackUp();
        }

        return [$selected, $skipped];
    }

    // =========================================================================
    // Validation core (FR-08, FR-09) — shared by inspect() and restore()
    // =========================================================================

    /**
     * Implements ADR `backup-restore-semantics` stages 2-8. Never throws
     * for content problems (they are returned); only truly unreadable
     * archives raise a problem string too, so this method itself never
     * throws.
     *
     * @return array{0: ?array<string, mixed>, 1: list<string>}
     */
    private function validateArchive(string $archive, bool $verifyHashes): array
    {
        try {
            $entries = $this->archives->entries($archive);
        } catch (BackupException) {
            return [null, ["This isn't a valid ZIP file, so it can't be an MDVault backup."]];
        }

        if (count($entries) > self::MAX_ENTRIES) {
            return [null, ['This backup has too many entries to be a valid MDVault backup.']];
        }

        /** @var list<string> $problems */
        $problems = [];
        $entriesByName = [];

        foreach ($entries as $entry) {
            $entriesByName[$entry['name']] = $entry;

            if ($entry['is_symlink']) {
                $problems[] = "\u{201c}{$entry['name']}\u{201d} is a symlink, which isn't allowed in a backup.";
            }

            if ($entry['is_encrypted']) {
                $problems[] = "\u{201c}{$entry['name']}\u{201d} is encrypted; this version of MDVault can't read it.";
            }
        }

        if ($problems !== []) {
            return [null, array_slice($problems, 0, self::MAX_PROBLEMS)];
        }

        $manifestEntry = $entriesByName[self::MANIFEST_NAME] ?? null;

        if ($manifestEntry === null) {
            return [null, ["\u{201c}manifest.json\u{201d} is missing, so this isn't an MDVault backup."]];
        }

        if ($manifestEntry['size'] > self::MANIFEST_MAX_BYTES) {
            return [null, ['The manifest is too large to be a valid MDVault backup.']];
        }

        $json = $this->archives->readEntry($archive, self::MANIFEST_NAME, self::MANIFEST_MAX_BYTES);

        if ($json === null) {
            return [null, ["\u{201c}manifest.json\u{201d} couldn't be read."]];
        }

        try {
            $manifest = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [null, ["\u{201c}manifest.json\u{201d} isn't valid JSON."]];
        }

        if (! is_array($manifest) || array_is_list($manifest)) {
            return [null, ["\u{201c}manifest.json\u{201d} isn't a valid MDVault manifest."]];
        }

        if (($manifest['application'] ?? null) !== 'MDVault' || ($manifest['format'] ?? null) !== self::FORMAT) {
            return [null, ["This isn't an MDVault backup."]];
        }

        $formatVersion = $manifest['format_version'] ?? null;

        if (! is_int($formatVersion) || $formatVersion < 1) {
            return [null, ["\u{201c}manifest.json\u{201d} has an invalid format version."]];
        }

        if ($formatVersion > self::FORMAT_VERSION) {
            return [null, ["This backup was made by a newer version of MDVault (format {$formatVersion})."]];
        }

        $databaseVersion = $manifest['database_version'] ?? null;

        if (! is_int($databaseVersion) || $databaseVersion < 1) {
            return [null, ["\u{201c}manifest.json\u{201d} has an invalid database version."]];
        }

        if ($databaseVersion > self::DATABASE_VERSION) {
            return [null, ["This backup was made by a newer version of MDVault (database version {$databaseVersion})."]];
        }

        if (($manifest['hash_algorithm'] ?? null) !== FileHashService::ALGORITHM) {
            return [null, ['This backup uses an unsupported hash algorithm.']];
        }

        // Analyst review AR-01/AR-02 (`mdv-p6`): the remaining header keys
        // that inspect()/restore() read directly were never validated for
        // presence or type, so a hand-crafted or third-party manifest
        // missing one could pass this far and then hit an undefined-array-
        // key ErrorException downstream. Validated here, strictly, so the
        // normalized manifest this method returns (see below) always has
        // every key inspect()/restore() read.
        $headerCreatedAt = $manifest['created_at'] ?? null;

        if (! is_string($headerCreatedAt) || ! $this->isValidTimestamp($headerCreatedAt)) {
            return [null, ["\u{201c}manifest.json\u{201d} has an invalid creation time."]];
        }

        $appVersion = $manifest['app_version'] ?? null;

        if (! is_string($appVersion)) {
            return [null, ["\u{201c}manifest.json\u{201d} has an invalid app version."]];
        }

        $scope = $manifest['scope'] ?? null;

        if (! in_array($scope, [BackupScope::All->value, BackupScope::Vault->value], true)) {
            return [null, ["\u{201c}manifest.json\u{201d} has an invalid scope."]];
        }

        $contents = $manifest['contents'] ?? null;

        if (! is_array($contents) || ! array_is_list($contents)) {
            return [null, ["\u{201c}manifest.json\u{201d} has an invalid vault list."]];
        }

        $caseFold = PHP_OS_FAMILY === 'Windows' || PHP_OS_FAMILY === 'Darwin';

        /** @var array<string, bool> $seenVaultUuids */
        $seenVaultUuids = [];
        /** @var array<string, bool> $seenVaultNames */
        $seenVaultNames = [];
        /** @var array<string, bool> $seenNoteUuids */
        $seenNoteUuids = [];
        /** @var array<string, array{size: int, hash: string}> $expectedFiles */
        $expectedFiles = [];
        /** @var array<string, bool> $allowedDirEntries */
        $allowedDirEntries = [];
        /** @var list<array<string, mixed>> $normalizedVaults */
        $normalizedVaults = [];

        $totalNotes = 0;
        $totalFiles = 0;
        $totalBytes = 0;

        foreach ($contents as $vaultData) {
            if (! is_array($vaultData)) {
                $problems[] = 'A vault entry in the manifest is invalid.';

                continue;
            }

            $uuid = $vaultData['uuid'] ?? null;

            if (! is_string($uuid) || ! Str::isUuid($uuid)) {
                $problems[] = 'A vault in the manifest has an invalid UUID.';
            } elseif (isset($seenVaultUuids[$uuid])) {
                $problems[] = "The vault UUID \u{201c}{$uuid}\u{201d} appears more than once in the manifest.";
            } else {
                $seenVaultUuids[$uuid] = true;
            }

            $name = $vaultData['name'] ?? null;

            if (! is_string($name) || $name === '') {
                $problems[] = 'A vault in the manifest has an invalid name.';

                continue;
            }

            try {
                $this->paths->assertValidFolderName($name);
            } catch (InvalidStorageRootException) {
                $problems[] = "\u{201c}{$name}\u{201d} isn't a valid vault name.";

                continue;
            }

            $nameKey = mb_strtolower($name);

            if (isset($seenVaultNames[$nameKey])) {
                $problems[] = "The vault name \u{201c}{$name}\u{201d} appears more than once in the manifest.";
            }

            $seenVaultNames[$nameKey] = true;

            if (($vaultData['archive_path'] ?? null) !== self::VAULTS_PREFIX.$name) {
                $problems[] = "\u{201c}{$name}\u{201d}'s archive path doesn't match its name.";
            }

            $description = $vaultData['description'] ?? null;

            if ($description !== null && ! is_string($description)) {
                $problems[] = "\u{201c}{$name}\u{201d} has an invalid description.";
                $description = null;
            }

            if (! array_key_exists('created_at', $vaultData)) {
                $problems[] = "\u{201c}{$name}\u{201d} is missing a creation time.";
                $vaultCreatedAt = null;
            } else {
                $vaultCreatedAt = $vaultData['created_at'];

                if (! $this->isValidTimestamp($vaultCreatedAt)) {
                    $problems[] = "\u{201c}{$name}\u{201d} has an invalid creation time.";
                    $vaultCreatedAt = null;
                }
            }

            $isEncrypted = $vaultData['is_encrypted'] ?? null;
            $hasEncryptionKey = array_key_exists('encryption', $vaultData);
            $encryptionValue = $hasEncryptionKey ? $vaultData['encryption'] : 'missing';

            if ($isEncrypted !== false || $encryptionValue !== null) {
                $problems[] = "\u{201c}{$name}\u{201d} is encrypted, which this version of MDVault can't restore.";
            }

            $allowedDirEntries[self::VAULTS_PREFIX."{$name}/"] = true;

            $directories = $vaultData['directories'] ?? [];

            if (! is_array($directories) || ! array_is_list($directories)) {
                $problems[] = "\u{201c}{$name}\u{201d} has an invalid directory list.";
                $directories = [];
            }

            /** @var array<string, bool> $declaredDirs */
            $declaredDirs = ['' => true];
            /** @var list<string> $normalizedDirectories */
            $normalizedDirectories = [];

            foreach ($directories as $directory) {
                if (! is_string($directory)) {
                    $problems[] = "\u{201c}{$name}\u{201d} has an invalid directory entry.";

                    continue;
                }

                $dirProblem = $this->relativePathProblem($directory);

                if ($dirProblem !== null) {
                    $problems[] = $dirProblem;

                    continue;
                }

                $declaredDirs[$directory] = true;
                $normalizedDirectories[] = $directory;
                $allowedDirEntries[self::VAULTS_PREFIX."{$name}/{$directory}/"] = true;
            }

            $notes = $vaultData['notes'] ?? [];
            $files = $vaultData['files'] ?? [];

            if (! is_array($notes) || ! array_is_list($notes) || ! is_array($files) || ! array_is_list($files)) {
                $problems[] = "\u{201c}{$name}\u{201d} has invalid notes or files.";

                continue;
            }

            /** @var array<string, bool> $vaultPaths */
            $vaultPaths = [];
            /** @var list<array<string, mixed>> $normalizedNotes */
            $normalizedNotes = [];
            /** @var list<array<string, mixed>> $normalizedFiles */
            $normalizedFiles = [];

            foreach ($notes as $noteData) {
                $parsed = $this->parseRegistryEntry($noteData, isNote: true);

                if ($parsed['problem'] !== null) {
                    $problems[] = $parsed['problem'];

                    continue;
                }

                $relativePath = $parsed['relative_path'];
                $pathKey = $caseFold ? mb_strtolower($relativePath) : $relativePath;

                if (isset($vaultPaths[$pathKey])) {
                    $problems[] = "\u{201c}{$name}/{$relativePath}\u{201d} is listed more than once.";

                    continue;
                }

                $vaultPaths[$pathKey] = true;

                if (isset($seenNoteUuids[$parsed['uuid']])) {
                    $problems[] = "The note UUID \u{201c}{$parsed['uuid']}\u{201d} appears more than once in the manifest.";

                    continue;
                }

                $seenNoteUuids[$parsed['uuid']] = true;

                $parentDir = $this->parentDirectoryOf($relativePath);

                if (! isset($declaredDirs[$parentDir])) {
                    $problems[] = "\u{201c}{$name}/{$relativePath}\u{201d}'s folder isn't listed in the manifest.";

                    continue;
                }

                $expectedFiles[self::VAULTS_PREFIX."{$name}/{$relativePath}"] = ['size' => $parsed['file_size'], 'hash' => $parsed['file_hash']];
                $normalizedNotes[] = [
                    'uuid' => $parsed['uuid'],
                    'relative_path' => $relativePath,
                    'file_size' => $parsed['file_size'],
                    'file_hash' => $parsed['file_hash'],
                    'modified_at' => $parsed['modified_at'],
                    'created_at' => $parsed['created_at'],
                    'updated_at' => $parsed['updated_at'],
                ];
                $totalNotes++;
                $totalBytes += $parsed['file_size'];
            }

            foreach ($files as $fileData) {
                $parsed = $this->parseRegistryEntry($fileData, isNote: false);

                if ($parsed['problem'] !== null) {
                    $problems[] = $parsed['problem'];

                    continue;
                }

                $relativePath = $parsed['relative_path'];
                $pathKey = $caseFold ? mb_strtolower($relativePath) : $relativePath;

                if (isset($vaultPaths[$pathKey])) {
                    $problems[] = "\u{201c}{$name}/{$relativePath}\u{201d} is listed more than once.";

                    continue;
                }

                $vaultPaths[$pathKey] = true;

                $parentDir = $this->parentDirectoryOf($relativePath);

                if (! isset($declaredDirs[$parentDir])) {
                    $problems[] = "\u{201c}{$name}/{$relativePath}\u{201d}'s folder isn't listed in the manifest.";

                    continue;
                }

                $expectedFiles[self::VAULTS_PREFIX."{$name}/{$relativePath}"] = ['size' => $parsed['file_size'], 'hash' => $parsed['file_hash']];
                $normalizedFiles[] = [
                    'relative_path' => $relativePath,
                    'file_size' => $parsed['file_size'],
                    'file_hash' => $parsed['file_hash'],
                    'modified_at' => $parsed['modified_at'],
                ];
                $totalFiles++;
                $totalBytes += $parsed['file_size'];
            }

            $normalizedVaults[] = [
                'uuid' => is_string($uuid) ? $uuid : '',
                'name' => $name,
                'description' => $description,
                'archive_path' => self::VAULTS_PREFIX.$name,
                'created_at' => $vaultCreatedAt,
                'directories' => $normalizedDirectories,
                'notes' => $normalizedNotes,
                'files' => $normalizedFiles,
            ];

            if (count($problems) >= self::MAX_PROBLEMS) {
                return [null, array_slice($problems, 0, self::MAX_PROBLEMS)];
            }
        }

        if (($manifest['vaults'] ?? null) !== count($contents)) {
            $problems[] = 'The manifest lists a different number of vaults than it contains.';
        }

        if (($manifest['notes'] ?? null) !== $totalNotes) {
            $listed = $manifest['notes'] ?? '?';
            $problems[] = "The backup lists {$listed} notes but contains {$totalNotes}.";
        }

        if (($manifest['files'] ?? null) !== $totalFiles) {
            $listed = $manifest['files'] ?? '?';
            $problems[] = "The backup lists {$listed} files but contains {$totalFiles}.";
        }

        if (($manifest['total_bytes'] ?? null) !== $totalBytes) {
            $problems[] = "The manifest's total size doesn't match its contents.";
        }

        if ($totalBytes > self::MAX_DECLARED_TOTAL_BYTES) {
            $problems[] = 'This backup declares more data than MDVault allows.';
        }

        if ($problems !== []) {
            return [null, array_slice($problems, 0, self::MAX_PROBLEMS)];
        }

        // Stage 7: the entry set must be exactly manifest.json + every
        // expected file + optional declared directory entries.
        $remainingEntries = $entriesByName;
        unset($remainingEntries[self::MANIFEST_NAME]);

        foreach ($expectedFiles as $entryName => $expected) {
            $actual = $entriesByName[$entryName] ?? null;

            if ($actual === null) {
                $problems[] = "\u{201c}{$entryName}\u{201d} is missing from the backup.";

                continue;
            }

            if ($actual['is_directory']) {
                $problems[] = "\u{201c}{$entryName}\u{201d} should be a file, not a directory.";

                continue;
            }

            if ($actual['size'] !== $expected['size']) {
                $problems[] = "\u{201c}{$entryName}\u{201d}'s size doesn't match the manifest.";
            } elseif ($actual['size'] > self::COMPRESSION_RATIO_MIN_BYTES) {
                $compressedSize = $actual['compressed_size'];

                if ($compressedSize <= 0 || ($actual['size'] / $compressedSize) > self::MAX_COMPRESSION_RATIO) {
                    $problems[] = "\u{201c}{$entryName}\u{201d} is compressed far more than any real file MDVault expects; this backup is refused as a precaution.";
                }
            }

            unset($remainingEntries[$entryName]);
        }

        foreach (array_keys($remainingEntries) as $entryName) {
            if (isset($allowedDirEntries[$entryName]) && $entriesByName[$entryName]['is_directory']) {
                continue;
            }

            $problems[] = "\u{201c}{$entryName}\u{201d} isn't declared in the manifest.";
        }

        if ($problems !== []) {
            return [null, array_slice($problems, 0, self::MAX_PROBLEMS)];
        }

        // Stage 7.5 (QA round 3, `mdv-p6`, QA-05): an unforgeable aggregate
        // gate. The per-entry ratio gate above only evaluates entries
        // individually and only above the 1 MiB floor, so an attacker can
        // evade it entirely — with completely genuine ZIP metadata, no
        // forgery required — by splitting a large, highly compressible
        // payload into several entries each AT or under
        // COMPRESSION_RATIO_MIN_BYTES (sub-floor chunking). This gate
        // instead compares $totalBytes — the same declared total that
        // Stage 8 below is about to stream-hash across every expected
        // entry — against the archive's own real, on-disk size, which no
        // manifest or per-entry ZIP metadata can misrepresent. Combined
        // with `hashStream()`'s `expected['size'] + 1` cap (Stage 8), this
        // makes Stage 8's total work provably bounded by roughly
        // physicalBytes * MAX_COMPRESSION_RATIO, regardless of how the
        // declared total is distributed across entries or what the ZIP's
        // own metadata claims — the actual unconditional bound. The
        // allowance keeps small, legitimately well-compressed backups
        // (ordinary Markdown notes) from tripping it. A null physical size
        // (the archive became unreadable between opening it above and this
        // stat) is treated as a failure, since the bound can't be
        // established.
        $physicalBytes = $this->files->size($archive);

        if ($physicalBytes === null || $totalBytes > ($physicalBytes * self::MAX_COMPRESSION_RATIO) + $this->aggregateRatioAllowanceBytes) {
            $problems[] = 'This backup declares far more data than its file size could plausibly contain; this backup is refused as a precaution.';
        }

        if ($problems !== []) {
            return [null, array_slice($problems, 0, self::MAX_PROBLEMS)];
        }

        // Stage 8: integrity (streamed SHA-256 of every expected entry).
        // hashStream()'s `expected['size'] + 1` cap, together with the
        // Stage 7.5 aggregate gate above, is what actually bounds this
        // stage's total work — not the per-entry ratio heuristic.
        if ($verifyHashes) {
            /** @var list<string> $damaged */
            $damaged = [];

            $this->archives->eachEntryStream($archive, array_keys($expectedFiles), function (string $name, mixed $stream) use ($expectedFiles, &$damaged): void {
                $expected = $expectedFiles[$name];

                if ($stream === null) {
                    $damaged[] = $name;

                    return;
                }

                $result = $this->hashes->hashStream($stream, $expected['size'] + 1);

                if ($result === null || $result['bytes'] !== $expected['size'] || ! hash_equals($expected['hash'], $result['hash'])) {
                    $damaged[] = $name;
                }
            });

            foreach ($damaged as $name) {
                $problems[] = "\u{201c}{$name}\u{201d} is damaged (its checksum doesn't match).";
            }
        }

        if ($problems !== []) {
            return [null, array_slice($problems, 0, self::MAX_PROBLEMS)];
        }

        // Analyst review AR-02 (`mdv-p6`): return a normalized manifest —
        // every key present, correctly typed, defaults applied — rather
        // than the raw JSON-decoded array, so inspect() and restore() never
        // read an unvalidated manifest key directly.
        return [[
            'created_at' => $headerCreatedAt,
            'app_version' => $appVersion,
            'format_version' => $formatVersion,
            'database_version' => $databaseVersion,
            'scope' => $scope,
            'hash_algorithm' => FileHashService::ALGORITHM,
            'vaults' => count($contents),
            'notes' => $totalNotes,
            'files' => $totalFiles,
            'total_bytes' => $totalBytes,
            'contents' => $normalizedVaults,
        ], []];
    }

    /**
     * @return array{problem: ?string, relative_path: ?string, file_size: ?int, file_hash: ?string, modified_at: ?int, uuid: ?string, created_at: ?string, updated_at: ?string}
     */
    private function parseRegistryEntry(mixed $entry, bool $isNote): array
    {
        $empty = ['problem' => null, 'relative_path' => null, 'file_size' => null, 'file_hash' => null, 'modified_at' => null, 'uuid' => null, 'created_at' => null, 'updated_at' => null];

        if (! is_array($entry)) {
            return [...$empty, 'problem' => 'The manifest contains an invalid entry.'];
        }

        $relativePath = $entry['relative_path'] ?? null;

        if (! is_string($relativePath) || $relativePath === '') {
            return [...$empty, 'problem' => 'The manifest contains an entry with an invalid path.'];
        }

        $pathProblem = $this->relativePathProblem($relativePath);

        if ($pathProblem !== null) {
            return [...$empty, 'relative_path' => $relativePath, 'problem' => $pathProblem];
        }

        if ($isNote && ! str_ends_with(mb_strtolower($relativePath), '.md')) {
            return [...$empty, 'relative_path' => $relativePath, 'problem' => "\u{201c}{$relativePath}\u{201d} isn't a Markdown note path."];
        }

        $size = $entry['file_size'] ?? null;

        if (! is_int($size) || $size < 0) {
            return [...$empty, 'relative_path' => $relativePath, 'problem' => "\u{201c}{$relativePath}\u{201d} has an invalid size."];
        }

        if ($size > self::MAX_DECLARED_FILE_BYTES) {
            return [...$empty, 'relative_path' => $relativePath, 'problem' => "\u{201c}{$relativePath}\u{201d} claims to be larger than MDVault allows in a backup."];
        }

        $hash = $entry['file_hash'] ?? null;

        if (! is_string($hash) || preg_match('/^[0-9a-f]{64}$/', $hash) !== 1) {
            return [...$empty, 'relative_path' => $relativePath, 'problem' => "\u{201c}{$relativePath}\u{201d} has an invalid hash."];
        }

        if (! array_key_exists('modified_at', $entry)) {
            return [...$empty, 'relative_path' => $relativePath, 'problem' => "\u{201c}{$relativePath}\u{201d} is missing a modified time."];
        }

        $modifiedAt = $entry['modified_at'];

        if ($modifiedAt !== null && ! is_int($modifiedAt)) {
            return [...$empty, 'relative_path' => $relativePath, 'problem' => "\u{201c}{$relativePath}\u{201d} has an invalid modified time."];
        }

        $uuid = null;
        $createdAt = null;
        $updatedAt = null;

        if ($isNote) {
            $uuid = $entry['uuid'] ?? null;

            if (! is_string($uuid) || ! Str::isUuid($uuid)) {
                return [...$empty, 'relative_path' => $relativePath, 'problem' => "\u{201c}{$relativePath}\u{201d} has an invalid UUID."];
            }

            $createdAt = $entry['created_at'] ?? null;
            $updatedAt = $entry['updated_at'] ?? null;

            if (! $this->isValidTimestamp($createdAt) || ! $this->isValidTimestamp($updatedAt)) {
                return [...$empty, 'relative_path' => $relativePath, 'problem' => "\u{201c}{$relativePath}\u{201d} has an invalid timestamp."];
            }
        }

        return [
            'problem' => null,
            'relative_path' => $relativePath,
            'file_size' => $size,
            'file_hash' => $hash,
            'modified_at' => $modifiedAt,
            'uuid' => $uuid,
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
        ];
    }

    /**
     * Strict ISO-8601 check (analyst review AR-02, `mdv-p6`): true for null,
     * or a string in exactly the Zulu form MDVault itself writes
     * (`toIso8601ZuluString()`, e.g. `2026-09-30T05:36:18Z`) or the ATOM
     * form with a numeric UTC offset (e.g. `2026-09-30T05:36:18+02:00`).
     * Deliberately stricter than `Carbon::parse()`, which accepts far more
     * than ISO-8601 (relative phrases, partial dates, etc.) and would let
     * those slip through validateArchive() as "valid".
     */
    private function isValidTimestamp(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (! is_string($value) || $value === '') {
            return false;
        }

        $zulu = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $value);

        if ($zulu !== false && $zulu->format('Y-m-d\TH:i:s\Z') === $value) {
            return true;
        }

        $atom = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $value);

        return $atom !== false && $atom->format(\DateTimeInterface::ATOM) === $value;
    }

    /**
     * Path-safety rules (ADR `backup-restore-semantics`, stage 6), applied
     * to both manifest relative paths and declared directory names.
     */
    private function relativePathProblem(string $path): ?string
    {
        if (! mb_check_encoding($path, 'UTF-8')) {
            return "\u{201c}{$path}\u{201d} isn't valid UTF-8.";
        }

        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '\\') || preg_match('/[\x00-\x1F]/', $path) === 1) {
            return "\u{201c}{$path}\u{201d} has an unsafe path.";
        }

        if (preg_match('#^[A-Za-z]:#', $path) === 1) {
            return "\u{201c}{$path}\u{201d} has an unsafe path.";
        }

        if (strlen($path) > 1024) {
            return "\u{201c}{$path}\u{201d} has an unsafe path.";
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || str_starts_with($segment, '.')) {
                return "\u{201c}{$path}\u{201d} has an unsafe path.";
            }

            if (strlen($segment) > 255) {
                return "\u{201c}{$path}\u{201d} has an unsafe path.";
            }

            if (PHP_OS_FAMILY === 'Windows') {
                if (preg_match('/[<>:"|?*]/', $segment) === 1 || str_ends_with($segment, '.') || str_ends_with($segment, ' ')) {
                    return "\u{201c}{$path}\u{201d} has a name that isn't valid on Windows.";
                }

                $withoutExtension = preg_replace('/\.[^.]*$/', '', $segment) ?? $segment;

                if (in_array(strtoupper($withoutExtension), self::WINDOWS_RESERVED_NAMES, true)) {
                    return "\u{201c}{$path}\u{201d} has a name that isn't valid on Windows.";
                }
            }
        }

        return null;
    }

    private function parentDirectoryOf(string $relativePath): string
    {
        $position = strrpos($relativePath, '/');

        return $position === false ? '' : substr($relativePath, 0, $position);
    }

    // =========================================================================
    // Inspect (FR-08)
    // =========================================================================

    /**
     * @throws BackupException archiveNotFound|notZipFile (unusable path only)
     */
    public function inspect(string $path): BackupInspection
    {
        @set_time_limit(0);

        $archive = $this->assertArchivePath($path);

        [$manifest, $problems] = $this->validateArchive($archive, verifyHashes: true);

        if ($manifest === null) {
            return new BackupInspection(valid: false, problems: $problems, backup: null, vaults: []);
        }

        $root = $this->paths->rootPath();

        $backup = [
            'created_at' => $manifest['created_at'],
            'app_version' => $manifest['app_version'],
            'format_version' => $manifest['format_version'],
            'scope' => $manifest['scope'],
            'vault_count' => $manifest['vaults'],
            'note_count' => $manifest['notes'],
            'file_count' => $manifest['files'],
            'total_bytes' => $manifest['total_bytes'],
            'archive_size' => $this->files->size($archive) ?? 0,
        ];

        /** @var list<string> $claimed */
        $claimed = [];
        $vaults = [];

        foreach ($manifest['contents'] as $vaultData) {
            $exists = Vault::query()->where('uuid', $vaultData['uuid'])->exists();
            $priorClaimed = $claimed;

            $restoreName = $exists ? null : $this->resolveName($vaultData['name'], $root, $priorClaimed);
            $copyName = $this->resolveName($vaultData['name'], $root, $priorClaimed);

            $reserved = $exists ? $copyName : $restoreName;

            if ($reserved !== null) {
                $claimed[] = mb_strtolower($reserved);
            }

            $totalBytes = array_sum(array_column($vaultData['notes'], 'file_size')) + array_sum(array_column($vaultData['files'], 'file_size'));

            $vaults[] = [
                'uuid' => $vaultData['uuid'],
                'name' => $vaultData['name'],
                'description' => $vaultData['description'],
                'note_count' => count($vaultData['notes']),
                'file_count' => count($vaultData['files']),
                'total_bytes' => $totalBytes,
                'state' => $exists ? 'exists' : 'new',
                'restore_name' => $restoreName,
                'copy_name' => $copyName,
                'default_action' => ($exists ? RestoreAction::Skip : RestoreAction::Restore)->value,
            ];
        }

        return new BackupInspection(valid: true, problems: [], backup: $backup, vaults: $vaults);
    }

    /**
     * @throws BackupException archiveNotFound|notZipFile
     */
    private function assertArchivePath(string $path): string
    {
        try {
            $normalized = $this->paths->normalize($path);
        } catch (InvalidStorageRootException) {
            throw BackupException::archiveNotFound($path);
        }

        if (! $this->paths->isAbsolute($normalized) || ! $this->files->isFile($normalized) || $this->files->isSymlink($normalized)) {
            throw BackupException::archiveNotFound($normalized);
        }

        if (! str_ends_with(mb_strtolower($normalized), '.zip')) {
            throw BackupException::notZipFile($normalized);
        }

        return $normalized;
    }

    /**
     * Finds the first free vault name at $root: $base, "$base (restored)",
     * "$base (restored 2)" ... "$base (restored 20)". Null when none is
     * free. For prediction only when called from `inspect()`.
     *
     * @param  list<string>  $claimed  lower-cased names already spoken for elsewhere in this operation
     */
    private function resolveName(string $base, string $root, array $claimed): ?string
    {
        $suffixes = ['', ' (restored)'];

        for ($n = 2; $n <= self::NAME_SUFFIX_LIMIT; $n++) {
            $suffixes[] = " (restored {$n})";
        }

        foreach ($suffixes as $suffix) {
            $truncatedBase = rtrim(mb_substr($base, 0, 100 - mb_strlen($suffix)), ' .');
            $candidate = $truncatedBase.$suffix;

            if ($this->nameCandidateAvailable($candidate, $root, $claimed)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $claimed
     */
    private function nameCandidateAvailable(string $candidate, string $root, array $claimed): bool
    {
        try {
            $this->paths->assertValidFolderName($candidate);
        } catch (InvalidStorageRootException) {
            return false;
        }

        if (! $this->vaults->nameAvailable($candidate)) {
            return false;
        }

        if (in_array(mb_strtolower($candidate), $claimed, true)) {
            return false;
        }

        $target = $root.DIRECTORY_SEPARATOR.$candidate;

        if ($this->files->exists($target)) {
            return false;
        }

        if ($this->vaults->overlappingVault($target) !== null) {
            return false;
        }

        return true;
    }

    // =========================================================================
    // Restore (FR-10 to FR-13)
    // =========================================================================

    /**
     * @param  array<string, RestoreAction>  $actions  keyed by the vault uuid in the backup; missing = Skip
     *
     * @throws BackupException
     */
    public function restore(string $path, array $actions): RestoreResult
    {
        @set_time_limit(0);

        $archive = $this->assertArchivePath($path);

        [$manifest, $problems] = $this->validateArchive($archive, verifyHashes: false);

        if ($manifest === null) {
            throw BackupException::invalidBackup($problems);
        }

        try {
            $root = $this->paths->ensureRootReady();
        } catch (InvalidStorageRootException $e) {
            throw BackupException::storageUnavailable($e->getMessage());
        }

        foreach ($this->files->staleStagingDirectories($root, self::STALE_TEMP_SECONDS) as $staleDirectory) {
            $this->files->deleteStagingDirectory($staleDirectory);
        }

        $manifestVaultsByUuid = [];

        foreach ($manifest['contents'] as $vaultData) {
            $manifestVaultsByUuid[$vaultData['uuid']] = $vaultData;
        }

        foreach (array_keys($actions) as $key) {
            if (! isset($manifestVaultsByUuid[$key])) {
                throw BackupException::unknownVault();
            }
        }

        /** @var list<array<string, mixed>> $plan */
        $plan = [];
        /** @var list<string> $claimed */
        $claimed = [];
        /** @var list<string> $skippedNames */
        $skippedNames = [];
        $needBytes = 0;

        foreach ($manifest['contents'] as $vaultData) {
            $manifestUuid = $vaultData['uuid'];
            $action = $actions[$manifestUuid] ?? RestoreAction::Skip;

            if ($action === RestoreAction::Skip) {
                $skippedNames[] = $vaultData['name'];

                continue;
            }

            $alreadyRegistered = Vault::query()->where('uuid', $manifestUuid)->exists();

            if ($action === RestoreAction::Restore && $alreadyRegistered) {
                throw BackupException::vaultAlreadyRegistered($vaultData['name']);
            }

            $vaultUuid = $action === RestoreAction::Restore ? $manifestUuid : (string) Str::uuid7();

            $name = $this->resolveName($vaultData['name'], $root, $claimed);

            if ($name === null) {
                throw BackupException::noFreeName($vaultData['name']);
            }

            $claimed[] = mb_strtolower($name);

            $notesPlan = [];
            $reidentified = 0;

            foreach ($vaultData['notes'] as $note) {
                $noteUuid = $note['uuid'];

                if ($action === RestoreAction::Copy) {
                    $noteUuid = (string) Str::uuid7();
                } elseif (Note::query()->where('uuid', $noteUuid)->exists()) {
                    $noteUuid = (string) Str::uuid7();
                    $reidentified++;
                }

                $notesPlan[] = [
                    'uuid' => $noteUuid,
                    'relative_path' => $note['relative_path'],
                    'file_size' => $note['file_size'],
                    'file_hash' => $note['file_hash'],
                    'modified_at' => $note['modified_at'],
                    'created_at' => $note['created_at'],
                    'updated_at' => $note['updated_at'],
                    'entry_name' => self::VAULTS_PREFIX."{$vaultData['name']}/{$note['relative_path']}",
                ];

                $needBytes += $note['file_size'];
            }

            $filesPlan = [];

            foreach ($vaultData['files'] as $file) {
                $filesPlan[] = [
                    'relative_path' => $file['relative_path'],
                    'file_size' => $file['file_size'],
                    'file_hash' => $file['file_hash'],
                    'modified_at' => $file['modified_at'],
                    'entry_name' => self::VAULTS_PREFIX."{$vaultData['name']}/{$file['relative_path']}",
                ];

                $needBytes += $file['file_size'];
            }

            $plan[] = [
                'vault_uuid' => $vaultUuid,
                'name' => $name,
                'description' => $vaultData['description'],
                'created_at' => $vaultData['created_at'],
                'directories' => $vaultData['directories'],
                'archive_path' => $vaultData['archive_path'],
                'notes' => $notesPlan,
                'files' => $filesPlan,
                'copy' => $action === RestoreAction::Copy,
                'reidentified' => $reidentified,
            ];
        }

        if ($plan === []) {
            throw BackupException::nothingSelected();
        }

        $free = $this->files->freeSpace($root);

        if ($free !== null && $free < $needBytes + self::SPACE_MARGIN_BYTES) {
            throw BackupException::notEnoughSpace($needBytes, $free);
        }

        $staging = $root.DIRECTORY_SEPARATOR.FileStorageService::RESTORE_STAGING_PREFIX.Str::random(12);

        if (! $this->files->makeDirectory($staging)) {
            throw BackupException::extractFailed('');
        }

        try {
            $this->stagePlan($archive, $staging, $plan);
        } catch (\Throwable $e) {
            $this->files->deleteStagingDirectory($staging);

            if ($e instanceof BackupException) {
                throw $e;
            }

            report($e);

            throw BackupException::extractFailed('');
        }

        $moved = [];

        try {
            $created = $this->database->connection()->transaction(function () use ($plan, $staging, $root, &$moved): array {
                $result = [];

                foreach ($plan as $i => $item) {
                    $vaultModel = new Vault;
                    $vaultModel->forceFill([
                        'uuid' => $item['vault_uuid'],
                        'name' => $item['name'],
                        'description' => $item['description'],
                        'path' => $staging.DIRECTORY_SEPARATOR.'v'.$i,
                        'relative_path' => $item['name'],
                        'is_encrypted' => false,
                        'status' => VaultStatus::Active,
                        'created_at' => $item['created_at'] ?? now(),
                        'updated_at' => $item['created_at'] ?? now(),
                    ]);
                    $vaultModel->timestamps = false;
                    $vaultModel->save();

                    $noteRows = [];

                    foreach ($item['notes'] as $note) {
                        $attributes = $this->index->newNoteAttributes($note['relative_path'], $note['file_size'], $note['file_hash']);
                        $attributes['uuid'] = $note['uuid'];
                        $attributes['vault_id'] = $vaultModel->id;
                        $attributes['created_at'] = $note['created_at'] !== null ? Carbon::parse($note['created_at'])->format('Y-m-d H:i:s') : now()->format('Y-m-d H:i:s');
                        $attributes['updated_at'] = $note['updated_at'] !== null ? Carbon::parse($note['updated_at'])->format('Y-m-d H:i:s') : now()->format('Y-m-d H:i:s');
                        $noteRows[] = $attributes;
                    }

                    foreach (array_chunk($noteRows, 500) as $chunk) {
                        Note::query()->insert($chunk);
                    }

                    $target = $root.DIRECTORY_SEPARATOR.$item['name'];
                    $sourceStaging = $staging.DIRECTORY_SEPARATOR.'v'.$i;

                    if (! $this->moveWithRetry($sourceStaging, $target)) {
                        throw BackupException::moveFailed($target);
                    }

                    $moved[] = [$sourceStaging, $target];

                    $vaultModel->update(['path' => $this->files->canonical($target)]);

                    $result[] = [
                        'uuid' => $vaultModel->uuid,
                        'name' => $vaultModel->name,
                        'path' => $vaultModel->path,
                        'notes' => count($item['notes']),
                        'copy' => $item['copy'],
                        'reidentified' => $item['reidentified'],
                    ];
                }

                return $result;
            });
        } catch (\Throwable $e) {
            $left = [];

            foreach (array_reverse($moved) as [$from, $to]) {
                if (! $this->files->renameDirectory($to, $from)) {
                    $left[] = $to;
                }
            }

            $this->files->deleteStagingDirectory($staging);

            if (! $e instanceof BackupException) {
                report($e);
            }

            if ($left !== []) {
                throw BackupException::restoreRollbackFailed($left);
            }

            throw $e instanceof BackupException ? $e : BackupException::restoreFailed();
        }

        if (! $this->files->deleteEmptyDirectory($staging)) {
            $this->files->deleteStagingDirectory($staging);
        }

        $warnings = [];
        $totalReidentified = 0;

        foreach ($created as $c) {
            $vaultModel = Vault::query()->where('uuid', $c['uuid'])->first();

            if ($vaultModel !== null) {
                try {
                    $result = $this->index->reconcile($vaultModel, IndexMode::Full);

                    if ($result->hasChanges()) {
                        $warnings[] = "\u{201c}{$vaultModel->name}\u{201d} had differences after restoring and was re-indexed.";
                    }
                } catch (NoteOperationException $e) {
                    $warnings[] = $e->getMessage();
                }
            }

            $totalReidentified += $c['reidentified'];
        }

        if ($totalReidentified > 0) {
            $warnings[] = "{$totalReidentified} note(s) got new identities because they already exist in another vault.";
        }

        // $created is never empty here: $plan is non-empty (checked above),
        // and every planned vault either succeeds or the transaction throws.
        if ($this->vaults->current() === null) {
            $firstVault = Vault::query()->where('uuid', $created[0]['uuid'])->first();

            if ($firstVault !== null) {
                $this->vaults->open($firstVault);
            }
        }

        $restored = array_map(fn (array $c): array => [
            'uuid' => $c['uuid'],
            'name' => $c['name'],
            'path' => $c['path'],
            'notes' => $c['notes'],
            'copy' => $c['copy'],
        ], $created);

        return new RestoreResult($restored, $skippedNames, $warnings);
    }

    /**
     * Extracts every planned vault into its own numbered subfolder of
     * $staging, verifying each file's size and hash as it is written.
     *
     * @param  list<array<string, mixed>>  $plan
     *
     * @throws BackupException
     */
    private function stagePlan(string $archive, string $staging, array $plan): void
    {
        foreach ($plan as $i => $item) {
            $vaultStaging = $staging.DIRECTORY_SEPARATOR.'v'.$i;

            if (! $this->files->makeDirectories($vaultStaging)) {
                throw BackupException::extractFailed($item['archive_path']);
            }

            $directories = $item['directories'];
            sort($directories, SORT_STRING);

            foreach ($directories as $directory) {
                if (! $this->files->makeDirectories($this->files->joinRelative($vaultStaging, $directory))) {
                    throw BackupException::extractFailed($item['archive_path'].'/'.$directory);
                }
            }

            /** @var array<string, array<string, mixed>> $entriesByName */
            $entriesByName = [];

            foreach ([...$item['notes'], ...$item['files']] as $entry) {
                $entriesByName[$entry['entry_name']] = $entry;
            }

            $archivePath = $item['archive_path'];
            $seen = [];

            $this->archives->eachEntryStream($archive, array_keys($entriesByName), function (string $entryName, mixed $stream) use (&$seen, $entriesByName, $vaultStaging, $archivePath): void {
                $seen[$entryName] = true;
                $expected = $entriesByName[$entryName];

                if ($stream === null) {
                    throw BackupException::damaged($entryName);
                }

                $relative = mb_substr($entryName, mb_strlen($archivePath) + 1);
                $absolute = $this->files->joinRelative($vaultStaging, $relative);

                $written = $this->files->createFileFromStream($absolute, $stream, $expected['file_size']);

                if ($written !== $expected['file_size'] || $this->hashes->hashFile($absolute) !== $expected['file_hash']) {
                    throw BackupException::damaged($entryName);
                }

                if ($expected['modified_at'] !== null) {
                    $this->files->setModifiedTime($absolute, $expected['modified_at']);
                }
            });

            if (count($seen) !== count($entriesByName)) {
                throw BackupException::damaged($archivePath);
            }
        }
    }

    private function moveWithRetry(string $from, string $to): bool
    {
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            if ($this->files->renameDirectory($from, $to)) {
                return true;
            }

            if ($attempt < 3) {
                usleep(200_000);
            }
        }

        return false;
    }
}
