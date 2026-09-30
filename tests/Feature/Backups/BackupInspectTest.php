<?php

use App\Enums\IndexMode;
use App\Enums\RestoreAction;
use App\Exceptions\BackupException;
use App\Models\Vault;
use App\Services\BackupService;
use App\Services\VaultIndexService;
use App\Services\VaultService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mdvault-backup-inspect-'.Str::random(8);
    fakeDocumentsDirectory($this->tmp.DIRECTORY_SEPARATOR.'Documents');
    $this->root = $this->tmp.DIRECTORY_SEPARATOR.'Documents'.DIRECTORY_SEPARATOR.'MDVault';
    $this->destDir = $this->tmp.DIRECTORY_SEPARATOR.'Backups';
    File::makeDirectory($this->destDir, 0755, true);

    $this->vaults = app(VaultService::class);
    $this->index = app(VaultIndexService::class);
    $this->service = app(BackupService::class);
});

afterEach(function () {
    File::deleteDirectory($this->tmp);
});

/**
 * A valid backup ZIP, produced through the real service, plus the manifest
 * array decoded from it for building tampered variants.
 *
 * @return array{path: string, manifest: array<string, mixed>}
 */
function validBackupFixture(): array
{
    $vault = test()->vaults->create('Work');
    writeVaultFiles($vault->path, ['Projects/HRMIS.md' => 'content', 'Empty/' => '']);
    test()->index->reconcile($vault, IndexMode::Full);

    $dest = test()->destDir.DIRECTORY_SEPARATOR.'valid.zip';
    test()->service->create(null, $dest);

    $zip = new ZipArchive;
    $zip->open($dest, ZipArchive::RDONLY);
    $manifest = json_decode($zip->getFromName('manifest.json'), true);
    $zip->close();

    return ['path' => $dest, 'manifest' => $manifest];
}

// --- Valid ---------------------------------------------------------------------

test('inspect of a valid backup reports valid and per-vault state', function () {
    $fixture = validBackupFixture();

    $inspection = $this->service->inspect($fixture['path']);

    expect($inspection->valid)->toBeTrue()
        ->and($inspection->problems)->toBe([])
        ->and($inspection->backup['vault_count'])->toBe(1)
        ->and($inspection->vaults[0]['state'])->toBe('exists')
        ->and($inspection->vaults[0]['default_action'])->toBe('skip');
});

test('inspect reports state "new" and predicts the restore name once the vault is unregistered', function () {
    $fixture = validBackupFixture();
    Vault::query()->delete();
    File::deleteDirectory($this->root.DIRECTORY_SEPARATOR.'Work');

    $inspection = $this->service->inspect($fixture['path']);

    expect($inspection->vaults[0]['state'])->toBe('new')
        ->and($inspection->vaults[0]['restore_name'])->toBe('Work')
        ->and($inspection->vaults[0]['default_action'])->toBe('restore');
});

test('inspect predicts a suffixed restore name when an unregistered folder already occupies it', function () {
    $fixture = validBackupFixture();
    // Unregister the vault without touching its folder, which is exactly
    // an "unregistered folder already occupies the name" situation.
    Vault::query()->delete();

    $inspection = $this->service->inspect($fixture['path']);

    expect($inspection->vaults[0]['restore_name'])->toBe('Work (restored)');
});

test('inspect writes nothing to the storage root and creates no rows', function () {
    $fixture = validBackupFixture();
    $rowCountBefore = Vault::count();
    $listingBefore = is_dir($this->root) ? scandir($this->root) : null;

    $this->service->inspect($fixture['path']);

    expect(Vault::count())->toBe($rowCountBefore)
        ->and(is_dir($this->root) ? scandir($this->root) : null)->toBe($listingBefore);
});

test('inspect on a missing path throws with field path', function () {
    try {
        $this->service->inspect($this->tmp.DIRECTORY_SEPARATOR.'missing.zip');
        test()->fail('Expected a BackupException.');
    } catch (BackupException $e) {
        expect($e->field())->toBe('path');
    }
});

test('inspect on a non-zip-extension file throws with field path', function () {
    $txt = $this->tmp.DIRECTORY_SEPARATOR.'notes.txt';
    File::put($txt, 'hello');

    try {
        $this->service->inspect($txt);
        test()->fail('Expected a BackupException.');
    } catch (BackupException $e) {
        expect($e->field())->toBe('path');
    }
});

// --- Invalid archives ------------------------------------------------------------

function inspectInvalid(array $entries): void
{
    $path = test()->destDir.DIRECTORY_SEPARATOR.'invalid-'.Str::random(8).'.zip';
    makeZip($path, $entries);

    $inspection = test()->service->inspect($path);

    expect($inspection->valid)->toBeFalse()
        ->and($inspection->problems)->not->toBe([]);
}

test('a plain text file named .zip is invalid', function () {
    $path = $this->destDir.DIRECTORY_SEPARATOR.'text.zip';
    File::put($path, 'not a zip at all');

    $inspection = $this->service->inspect($path);

    expect($inspection->valid)->toBeFalse();
});

test('a zip with no manifest.json is invalid', function () {
    inspectInvalid(['vaults/Work/a.md' => 'x']);
});

test('a zip with invalid JSON in the manifest is invalid', function () {
    inspectInvalid(['manifest.json' => '{not json']);
});

test('a manifest with the wrong application is invalid', function () {
    $manifest = validBackupFixture()['manifest'];
    $manifest['application'] = 'NotMDVault';
    inspectInvalid(manifestOnlyEntries($manifest));
});

test('a manifest with a newer format_version is invalid', function () {
    $manifest = validBackupFixture()['manifest'];
    $manifest['format_version'] = 99;
    inspectInvalid(manifestOnlyEntries($manifest));
});

test('a manifest with a newer database_version is invalid', function () {
    $manifest = validBackupFixture()['manifest'];
    $manifest['database_version'] = 99;
    inspectInvalid(manifestOnlyEntries($manifest));
});

test('a manifest with the wrong hash_algorithm is invalid', function () {
    $manifest = validBackupFixture()['manifest'];
    $manifest['hash_algorithm'] = 'md5';
    inspectInvalid(manifestOnlyEntries($manifest));
});

test('a manifest whose note count does not match its contents is invalid', function () {
    $manifest = validBackupFixture()['manifest'];
    $manifest['notes'] = 999;
    inspectInvalid(manifestOnlyEntries($manifest));
});

test('a manifest with a duplicate vault UUID is invalid', function () {
    $fixture = validBackupFixture();
    $manifest = $fixture['manifest'];
    $manifest['contents'][] = $manifest['contents'][0];
    $manifest['vaults'] = 2;

    $path = $this->destDir.DIRECTORY_SEPARATOR.'dup-vault.zip';
    makeZip($path, tamperedEntriesFromRealBackup($fixture['path'], $manifest, extraVaultCopy: true));

    $inspection = $this->service->inspect($path);
    expect($inspection->valid)->toBeFalse();
});

test('a manifest with an invalid vault UUID is invalid', function () {
    $manifest = validBackupFixture()['manifest'];
    $manifest['contents'][0]['uuid'] = 'not-a-uuid';
    inspectInvalid(manifestOnlyEntries($manifest));
});

test('a manifest with an invalid note hash format is invalid', function () {
    $manifest = validBackupFixture()['manifest'];
    $manifest['contents'][0]['notes'][0]['file_hash'] = 'zz';
    inspectInvalid(manifestOnlyEntries($manifest));
});

test('a manifest with an invalid vault name is invalid', function () {
    $manifest = validBackupFixture()['manifest'];
    $manifest['contents'][0]['name'] = 'CON';
    inspectInvalid(manifestOnlyEntries($manifest));
});

test('a manifest whose archive_path does not match the vault name is invalid', function () {
    $manifest = validBackupFixture()['manifest'];
    $manifest['contents'][0]['archive_path'] = 'vaults/Wrong';
    inspectInvalid(manifestOnlyEntries($manifest));
});

test('a manifest with a note path not ending in .md is invalid', function () {
    $manifest = validBackupFixture()['manifest'];
    $manifest['contents'][0]['notes'][0]['relative_path'] = 'Projects/HRMIS.txt';
    inspectInvalid(manifestOnlyEntries($manifest));
});

test('a note whose parent directory is not declared is invalid', function () {
    $manifest = validBackupFixture()['manifest'];
    $manifest['contents'][0]['notes'][0]['relative_path'] = 'Undeclared/HRMIS.md';
    inspectInvalid(manifestOnlyEntries($manifest));
});

test('a zip-slip entry name is invalid', function () {
    inspectInvalid([
        'manifest.json' => json_encode([
            'application' => 'MDVault', 'format' => 'mdvault-backup', 'format_version' => 1, 'database_version' => 1,
            'app_version' => '1.0', 'created_at' => now()->toIso8601ZuluString(), 'scope' => 'all', 'hash_algorithm' => 'sha256',
            'vaults' => 1, 'notes' => 1, 'files' => 0, 'total_bytes' => 1,
            'contents' => [[
                'uuid' => (string) Str::uuid(), 'name' => 'Work', 'description' => null, 'is_encrypted' => false, 'encryption' => null,
                'archive_path' => 'vaults/Work', 'created_at' => now()->toIso8601ZuluString(), 'directories' => [],
                'notes' => [['uuid' => (string) Str::uuid(), 'relative_path' => '../../evil.md', 'file_size' => 1, 'file_hash' => str_repeat('a', 64), 'modified_at' => null, 'created_at' => null, 'updated_at' => null]],
                'files' => [],
            ]],
        ]),
        'vaults/Work/../../evil.md' => 'x',
    ]);
});

test('an entry with a symlink flag is invalid', function () {
    $path = $this->destDir.DIRECTORY_SEPARATOR.'symlink.zip';
    $fixture = validBackupFixture();
    File::copy($fixture['path'], $path);

    $zip = new ZipArchive;
    $zip->open($path);
    $zip->setExternalAttributesName('vaults/Work/Projects/HRMIS.md', ZipArchive::OPSYS_UNIX, 0120777 << 16);
    $zip->close();

    $inspection = $this->service->inspect($path);
    expect($inspection->valid)->toBeFalse();
});

test('a size mismatch is invalid', function () {
    $manifest = validBackupFixture()['manifest'];
    $manifest['contents'][0]['notes'][0]['file_size'] = 999999;
    $manifest['total_bytes'] = 999999;
    inspectInvalid(manifestOnlyEntries($manifest));
});

// --- QA round 1 (QA-03): the plan's remaining literal T6 dataset rows -----------

test('a note path starting with a slash is invalid', function () {
    $manifest = validBackupFixture()['manifest'];
    $manifest['contents'][0]['notes'][0]['relative_path'] = '/abs.md';
    inspectInvalid(manifestOnlyEntries($manifest));
});

test('a note path with a Windows drive letter is invalid', function () {
    $manifest = validBackupFixture()['manifest'];
    $manifest['contents'][0]['notes'][0]['relative_path'] = 'C:/x.md';
    inspectInvalid(manifestOnlyEntries($manifest));
});

test('a note path containing a backslash is invalid', function () {
    $manifest = validBackupFixture()['manifest'];
    $manifest['contents'][0]['notes'][0]['relative_path'] = 'Projects\\HRMIS.md';
    inspectInvalid(manifestOnlyEntries($manifest));
});

test('a note path with a dot-prefixed segment is invalid', function () {
    $manifest = validBackupFixture()['manifest'];
    $manifest['contents'][0]['notes'][0]['relative_path'] = '.hidden/x.md';
    inspectInvalid(manifestOnlyEntries($manifest));
});

test('an extra physical entry not declared in the manifest is invalid', function () {
    $manifest = validBackupFixture()['manifest'];
    $entries = manifestOnlyEntries($manifest);
    $entries['vaults/Work/Extra.md'] = 'not declared anywhere';

    $inspection = test()->service->inspect(makeZipFixture($entries));
    expect($inspection->valid)->toBeFalse();
});

test('a declared entry missing from the physical zip is invalid', function () {
    $manifest = validBackupFixture()['manifest'];
    $entries = manifestOnlyEntries($manifest);
    unset($entries['vaults/Work/Projects/HRMIS.md']);

    $inspection = test()->service->inspect(makeZipFixture($entries));
    expect($inspection->valid)->toBeFalse();
});

test('a manifest with a duplicate note UUID is invalid', function () {
    $manifest = validBackupFixture()['manifest'];
    $note = $manifest['contents'][0]['notes'][0];
    $duplicate = $note;
    $duplicate['relative_path'] = 'Projects/Second.md';
    // Same uuid as $note on purpose — the duplicate-note-UUID check.
    $manifest['contents'][0]['notes'][] = $duplicate;
    $manifest['notes'] = 2;
    $manifest['total_bytes'] += $note['file_size'];

    inspectInvalid(manifestOnlyEntries($manifest));
});

test('A.md and a.md collide case-insensitively on Windows and macOS', function () {
    if (! in_array(PHP_OS_FAMILY, ['Windows', 'Darwin'], true)) {
        test()->markTestSkipped('Case-insensitive duplicate-path detection only applies on Windows/Darwin.');
    }

    $manifest = validBackupFixture()['manifest'];
    $note = $manifest['contents'][0]['notes'][0];
    $upper = $note;
    $upper['relative_path'] = 'A.md';
    $lower = $note;
    $lower['relative_path'] = 'a.md';
    $lower['uuid'] = (string) Str::uuid();

    $manifest['contents'][0]['notes'] = [$upper, $lower];
    $manifest['notes'] = 2;
    $manifest['total_bytes'] = $upper['file_size'] + $lower['file_size'];

    inspectInvalid(manifestOnlyEntries($manifest));
});

// --- Analyst review AR-02: stage 5 required-key/type validation, normalized manifest ---

test('a note with a malformed (non-ISO-8601) created_at string is invalid', function () {
    $manifest = validBackupFixture()['manifest'];
    $manifest['contents'][0]['notes'][0]['created_at'] = 'not-a-date';
    inspectInvalid(manifestOnlyEntries($manifest));
});

test('a vault missing the created_at key entirely is invalid', function () {
    $manifest = validBackupFixture()['manifest'];
    unset($manifest['contents'][0]['created_at']);
    inspectInvalid(manifestOnlyEntries($manifest));
});

test('a note missing the modified_at key entirely is invalid', function () {
    $manifest = validBackupFixture()['manifest'];
    unset($manifest['contents'][0]['notes'][0]['modified_at']);
    inspectInvalid(manifestOnlyEntries($manifest));
});

test('a vault with a non-string description is invalid', function () {
    $manifest = validBackupFixture()['manifest'];
    $manifest['contents'][0]['description'] = 12345;
    inspectInvalid(manifestOnlyEntries($manifest));
});

test('restore refuses a backup with a vault missing its created_at key, with field path, not a 500', function () {
    $manifest = validBackupFixture()['manifest'];
    unset($manifest['contents'][0]['created_at']);

    $path = $this->destDir.DIRECTORY_SEPARATOR.'missing-vault-created-at.zip';
    makeZip($path, manifestOnlyEntries($manifest));

    $vaultUuid = $manifest['contents'][0]['uuid'];

    try {
        $this->service->restore($path, [$vaultUuid => RestoreAction::Restore]);
        test()->fail('Expected a BackupException.');
    } catch (BackupException $e) {
        expect($e->field())->toBe('path');
    }
});

// --- QA round 1 (QA-01): absolute per-file/total sanity caps ---------------------

test('a declared file size over the absolute per-file cap is invalid', function () {
    $manifest = validBackupFixture()['manifest'];
    $oversized = BackupService::MAX_DECLARED_FILE_BYTES + 1;
    $manifest['contents'][0]['notes'][0]['file_size'] = $oversized;
    $manifest['total_bytes'] = $oversized;

    // Physical content is capped to a tiny amount: the size cap is checked
    // (and rejects) long before Stage 8 would ever stream this entry.
    inspectInvalid(manifestEntriesWithCappedContent($manifest, 1024));
});

test('a declared total size over the absolute total cap is invalid', function () {
    $manifest = validBackupFixture()['manifest'];
    $note = $manifest['contents'][0]['notes'][0];
    $perFile = BackupService::MAX_DECLARED_FILE_BYTES;
    $count = 11; // 11 * 10 GiB = 110 GiB, over the 100 GiB total cap, while each entry stays within the per-file cap.

    $notes = [];

    for ($i = 0; $i < $count; $i++) {
        $entry = $note;
        $entry['relative_path'] = "Projects/N{$i}.md";
        $entry['uuid'] = (string) Str::uuid();
        $entry['file_size'] = $perFile;
        $notes[] = $entry;
    }

    $manifest['contents'][0]['notes'] = $notes;
    $manifest['notes'] = $count;
    $manifest['total_bytes'] = $perFile * $count;

    inspectInvalid(manifestEntriesWithCappedContent($manifest, 1024));
});

test('a hash mismatch (tampered content, same size) is invalid', function () {
    $fixture = validBackupFixture();
    $path = $this->destDir.DIRECTORY_SEPARATOR.'tampered.zip';
    File::copy($fixture['path'], $path);

    // Rewrite the note's content with different bytes of the same length.
    $entryPath = 'vaults/Work/Projects/HRMIS.md';
    $original = zipEntryContents($fixture['path'], $entryPath);
    $tampered = str_repeat('X', strlen($original));

    $zip = new ZipArchive;
    $zip->open($path);
    $zip->deleteName($entryPath);
    $zip->close();

    $zip->open($path);
    $zip->addFromString($entryPath, $tampered);
    $zip->close();

    $inspection = $this->service->inspect($path);
    expect($inspection->valid)->toBeFalse();
});

// --- QA round 2 (QA-01): compression-ratio gate -----------------------------------

test('a hostile entry whose compression ratio exceeds the cap is rejected before any hashing', function () {
    $fixture = hostileRatioArchiveFixture();

    $inspection = $this->service->inspect($fixture['path']);

    expect($inspection->valid)->toBeFalse()
        ->and(collect($inspection->problems)->contains(fn (string $p): bool => str_contains($p, 'compressed')))->toBeTrue();
});

test('a small highly compressible note under the 1 MiB ratio floor passes', function () {
    $vault = $this->vaults->create('Work');
    writeVaultFiles($vault->path, ['Big.md' => str_repeat('a', 512 * 1024)]);
    $this->index->reconcile($vault, IndexMode::Full);

    $dest = $this->destDir.DIRECTORY_SEPARATOR.'compressible.zip';
    $this->service->create(null, $dest);

    $inspection = $this->service->inspect($dest);

    expect($inspection->valid)->toBeTrue();
});

test('a normal attachment over 1 MiB with an ordinary compression ratio passes', function () {
    $vault = $this->vaults->create('Work');
    writeVaultFiles($vault->path, ['assets/photo.bin' => random_bytes(2 * 1024 * 1024)]);
    $this->index->reconcile($vault, IndexMode::Full);

    $dest = $this->destDir.DIRECTORY_SEPARATOR.'attachment.zip';
    $this->service->create(null, $dest);

    $inspection = $this->service->inspect($dest);

    expect($inspection->valid)->toBeTrue();
});

test('restore refuses a hostile compression-ratio archive and leaves no staging behind', function () {
    $fixture = hostileRatioArchiveFixture();
    $vaultUuid = $fixture['manifest']['contents'][0]['uuid'];
    $listingBefore = is_dir($this->root) ? scandir($this->root) : null;
    $rowCountBefore = Vault::count();

    try {
        $this->service->restore($fixture['path'], [$vaultUuid => RestoreAction::Restore]);
        test()->fail('Expected a BackupException.');
    } catch (BackupException $e) {
        expect($e->field())->toBe('path');
    }

    expect(Vault::count())->toBe($rowCountBefore)
        ->and(is_dir($this->root) ? scandir($this->root) : null)->toBe($listingBefore);
});

/**
 * Builds a backup whose single note declares an 8 MiB size backed by 8 MiB
 * of a single repeated byte — physically tiny once deflated, so its
 * uncompressed/compressed ratio is many times the 250:1 cap. Kept at 8 MiB
 * (not the 10 GiB per-file cap) so the fixture stays small and fast; that
 * comfortably clears the ratio threshold since deflate compresses a
 * constant byte run to a few hundred bytes.
 *
 * @return array{path: string, manifest: array<string, mixed>}
 */
function hostileRatioArchiveFixture(): array
{
    $fixture = validBackupFixture();
    $manifest = $fixture['manifest'];
    $hostileSize = 8 * 1024 * 1024;
    $originalSize = $manifest['contents'][0]['notes'][0]['file_size'];

    $manifest['contents'][0]['notes'][0]['file_size'] = $hostileSize;
    $manifest['total_bytes'] = $manifest['total_bytes'] - $originalSize + $hostileSize;

    $path = test()->destDir.DIRECTORY_SEPARATOR.'ratio-bomb-'.Str::random(8).'.zip';
    makeZip($path, manifestOnlyEntries($manifest));

    return ['path' => $path, 'manifest' => $manifest];
}

// --- QA round 3 (QA-05): unforgeable aggregate physical-size gate ----------------

/**
 * Patches the central directory `compressed_size` field of a single named
 * entry in an on-disk ZIP, exactly mirroring QA round 3's proof-of-concept
 * (`mdv-p6`, QA-05). The central directory file header (ZIP spec 4.3.12)
 * puts `compressed size` at byte offset +20 from its `PK\x01\x02` signature.
 *
 * Verified while building these tests: inflating this field beyond the
 * entry's true compressed size does NOT bypass detection through
 * `ArchiveService` — it always opens with `ZipArchive::CHECKCONS`, and
 * libzip's consistency check refuses to open an archive whose central
 * directory claims more compressed bytes for an entry than the file
 * physically contains, failing with "not a valid ZIP" before the manifest
 * is ever read. See `MAX_COMPRESSION_RATIO`'s docblock in `BackupService`
 * for the full explanation and the real bypass (sub-floor chunking, tested
 * below). This helper is kept because a forged-and-rejected archive is
 * still worth a regression test of its own.
 */
function forgeCentralDirectoryCompressedSize(string $path, string $entryName, int $forgedCompressedSize): void
{
    $bytes = file_get_contents($path);
    $signature = "PK\x01\x02";
    $offset = 0;

    while (($offset = strpos($bytes, $signature, $offset)) !== false) {
        $nameLength = unpack('v', substr($bytes, $offset + 28, 2))[1];
        $extraLength = unpack('v', substr($bytes, $offset + 30, 2))[1];
        $commentLength = unpack('v', substr($bytes, $offset + 32, 2))[1];
        $name = substr($bytes, $offset + 46, $nameLength);

        if ($name === $entryName) {
            $bytes = substr_replace($bytes, pack('V', $forgedCompressedSize), $offset + 20, 4);
            file_put_contents($path, $bytes);

            return;
        }

        $offset += 46 + $nameLength + $extraLength + $commentLength;
    }

    test()->fail("Central directory entry \"{$entryName}\" not found while forging compressed_size.");
}

/**
 * Builds on `hostileRatioArchiveFixture()`, then forges the central
 * directory's `compressed_size` for its note to 40,000 — giving a reported
 * ratio of 8,388,608 / 40,000 ≈ 209.7:1, comfortably under
 * `MAX_COMPRESSION_RATIO` (250:1), exactly as an attacker attempting to
 * evade the per-entry heuristic would.
 *
 * @return array{path: string, manifest: array<string, mixed>}
 */
function forgedRatioArchiveFixture(): array
{
    $fixture = hostileRatioArchiveFixture();
    $entryName = 'vaults/Work/'.$fixture['manifest']['contents'][0]['notes'][0]['relative_path'];

    forgeCentralDirectoryCompressedSize($fixture['path'], $entryName, 40_000);

    return $fixture;
}

test('a central-directory compressed_size forged above the true value is rejected before the manifest is even read', function () {
    $fixture = forgedRatioArchiveFixture();

    $inspection = $this->service->inspect($fixture['path']);

    expect($inspection->valid)->toBeFalse()
        ->and(collect($inspection->problems)->contains(fn (string $p): bool => str_contains($p, "isn't a valid ZIP file")))->toBeTrue();
});

test('restore refuses a forged central-directory archive and leaves no staging behind', function () {
    $fixture = forgedRatioArchiveFixture();
    $vaultUuid = $fixture['manifest']['contents'][0]['uuid'];
    $listingBefore = is_dir($this->root) ? scandir($this->root) : null;
    $rowCountBefore = Vault::count();

    try {
        $this->service->restore($fixture['path'], [$vaultUuid => RestoreAction::Restore]);
        test()->fail('Expected a BackupException.');
    } catch (BackupException $e) {
        expect($e->field())->toBe('path');
    }

    expect(Vault::count())->toBe($rowCountBefore)
        ->and(is_dir($this->root) ? scandir($this->root) : null)->toBe($listingBefore);
});

/**
 * The real, unforged bypass of the per-entry ratio heuristic (QA round 3,
 * `mdv-p6`, QA-05): it only evaluates entries whose uncompressed size
 * exceeds `COMPRESSION_RATIO_MIN_BYTES` (1 MiB), so splitting a large,
 * highly compressible payload into several entries each AT that floor
 * evades it completely — every byte of ZIP and manifest metadata here is
 * genuine, nothing is patched. 5 entries of exactly 1 MiB of a repeated
 * byte each: none individually trips the per-entry check, but their
 * declared total (5 MiB) is wildly out of proportion to the archive's real,
 * deflated physical size (a few KB) — which only the aggregate gate
 * (Stage 7.5) catches.
 *
 * @return array{path: string, manifest: array<string, mixed>}
 */
function subFloorChunkedArchiveFixture(): array
{
    $fixture = validBackupFixture();
    $manifest = $fixture['manifest'];
    $template = $manifest['contents'][0]['notes'][0];
    $chunkSize = BackupService::COMPRESSION_RATIO_MIN_BYTES;
    $chunkCount = 5;

    $notes = [];

    for ($i = 0; $i < $chunkCount; $i++) {
        $entry = $template;
        $entry['relative_path'] = "Chunks/N{$i}.md";
        $entry['uuid'] = (string) Str::uuid();
        $entry['file_size'] = $chunkSize;
        $notes[] = $entry;
    }

    $manifest['contents'][0]['directories'] = ['Chunks'];
    $manifest['contents'][0]['notes'] = $notes;
    $manifest['notes'] = $chunkCount;
    $manifest['total_bytes'] = $chunkSize * $chunkCount;

    $entries = ['manifest.json' => json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];
    $entries['vaults/Work/'] = null;
    $entries['vaults/Work/Chunks/'] = null;

    foreach ($notes as $entry) {
        $entries['vaults/Work/'.$entry['relative_path']] = str_repeat('a', $chunkSize);
    }

    $path = test()->destDir.DIRECTORY_SEPARATOR.'chunked-bomb-'.Str::random(8).'.zip';
    makeZip($path, $entries);

    return ['path' => $path, 'manifest' => $manifest];
}

/**
 * A `BackupService` whose aggregate-gate allowance is tightened from the
 * production 64 MiB down to 1 MiB, via the constructor seam added for QA
 * round 3 (`mdv-p6`, QA-05). This lets these tests exercise the aggregate
 * gate's rejection path with a ~5 MiB declared fixture (physically only a
 * few KB once deflated) instead of needing a 64 MiB+ declared total to
 * clear the production allowance. `MAX_COMPRESSION_RATIO` is untouched:
 * with the fixture's tiny physical size, `physicalBytes * 250` alone is
 * only a couple of MB, so even the full 64 MiB production allowance would
 * already be the dominant, easily-cleared term — tightening it is what
 * makes the gate's rejection observable without a much larger fixture.
 */
function tightenedAggregateGateService(): BackupService
{
    return app(BackupService::class, ['aggregateRatioAllowanceBytes' => 1024 * 1024]);
}

test('sub-floor chunking evades the per-entry ratio heuristic but is still rejected by the aggregate physical-size gate', function () {
    $fixture = subFloorChunkedArchiveFixture();

    $inspection = tightenedAggregateGateService()->inspect($fixture['path']);

    expect($inspection->valid)->toBeFalse()
        ->and(collect($inspection->problems)->contains(fn (string $p): bool => str_contains($p, 'plausibly contain')))->toBeTrue();
});

test('restore refuses a sub-floor-chunked archive under the tightened aggregate gate and leaves no staging behind', function () {
    $fixture = subFloorChunkedArchiveFixture();
    $vaultUuid = $fixture['manifest']['contents'][0]['uuid'];
    $listingBefore = is_dir($this->root) ? scandir($this->root) : null;
    $rowCountBefore = Vault::count();

    try {
        tightenedAggregateGateService()->restore($fixture['path'], [$vaultUuid => RestoreAction::Restore]);
        test()->fail('Expected a BackupException.');
    } catch (BackupException $e) {
        expect($e->field())->toBe('path');
    }

    expect(Vault::count())->toBe($rowCountBefore)
        ->and(is_dir($this->root) ? scandir($this->root) : null)->toBe($listingBefore);
});

test('a legitimate backup mixing an ordinary and a highly compressible file stays under the aggregate allowance and passes', function () {
    $vault = $this->vaults->create('Work');
    writeVaultFiles($vault->path, [
        'assets/photo.bin' => random_bytes(2 * 1024 * 1024),
        'Big.md' => str_repeat('a', 512 * 1024),
    ]);
    $this->index->reconcile($vault, IndexMode::Full);

    $dest = $this->destDir.DIRECTORY_SEPARATOR.'mixed-legit.zip';
    $this->service->create(null, $dest);

    $inspection = $this->service->inspect($dest);

    expect($inspection->valid)->toBeTrue();
});

/**
 * @param  array<string, mixed>  $manifest
 * @return array<string, string|null>
 */
function manifestOnlyEntries(array $manifest): array
{
    $entries = ['manifest.json' => json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];

    foreach ($manifest['contents'] as $vault) {
        $entries['vaults/'.$vault['name'].'/'] = null;

        foreach ($vault['directories'] ?? [] as $dir) {
            $entries['vaults/'.$vault['name'].'/'.$dir.'/'] = null;
        }

        foreach ($vault['notes'] as $note) {
            $entries['vaults/'.$vault['name'].'/'.$note['relative_path']] = str_repeat('a', $note['file_size']);
        }

        foreach ($vault['files'] as $file) {
            $entries['vaults/'.$vault['name'].'/'.$file['relative_path']] = str_repeat('a', $file['file_size']);
        }
    }

    return $entries;
}

/**
 * Like manifestOnlyEntries(), but writes at most $capBytes of physical
 * content per file regardless of the manifest's declared file_size — used
 * when the manifest declares a size too large to ever materialize in a
 * test (QA-01's absolute-cap tests).
 *
 * @param  array<string, mixed>  $manifest
 * @return array<string, string|null>
 */
function manifestEntriesWithCappedContent(array $manifest, int $capBytes): array
{
    $entries = ['manifest.json' => json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];

    foreach ($manifest['contents'] as $vault) {
        $entries['vaults/'.$vault['name'].'/'] = null;

        foreach ($vault['directories'] ?? [] as $dir) {
            $entries['vaults/'.$vault['name'].'/'.$dir.'/'] = null;
        }

        foreach ($vault['notes'] as $note) {
            $entries['vaults/'.$vault['name'].'/'.$note['relative_path']] = str_repeat('a', min($note['file_size'], $capBytes));
        }

        foreach ($vault['files'] as $file) {
            $entries['vaults/'.$vault['name'].'/'.$file['relative_path']] = str_repeat('a', min($file['file_size'], $capBytes));
        }
    }

    return $entries;
}

/**
 * Writes $entries to a fresh zip in the test's destination folder and
 * returns its path (for tests that need the raw inspection result rather
 * than just the valid/invalid summary that inspectInvalid() checks).
 *
 * @param  array<string, string|null>  $entries
 */
function makeZipFixture(array $entries): string
{
    $path = test()->destDir.DIRECTORY_SEPARATOR.'fixture-'.Str::random(8).'.zip';
    makeZip($path, $entries);

    return $path;
}

/**
 * Builds a full entry set (manifest + real file bytes copied from a real
 * backup) for a manifest with a duplicated vault entry.
 *
 * @param  array<string, mixed>  $manifest
 * @return array<string, string|null>
 */
function tamperedEntriesFromRealBackup(string $realBackupPath, array $manifest, bool $extraVaultCopy = false): array
{
    $entries = ['manifest.json' => json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];

    foreach ($manifest['contents'] as $vault) {
        foreach ($vault['notes'] as $note) {
            $entries['vaults/'.$vault['name'].'/'.$note['relative_path']] = zipEntryContents($realBackupPath, 'vaults/'.$vault['name'].'/'.$note['relative_path']);
        }
    }

    return $entries;
}
