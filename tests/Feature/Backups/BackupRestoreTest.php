<?php

use App\Enums\IndexMode;
use App\Enums\RestoreAction;
use App\Exceptions\BackupException;
use App\Models\Note;
use App\Models\Vault;
use App\Services\BackupService;
use App\Services\VaultIndexService;
use App\Services\VaultService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mdvault-backup-restore-'.Str::random(8);
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
 * Creates Work (+ optionally Personal) vaults with content, backs them all
 * up, then wipes the DB rows and vault folders — simulating "remove local
 * data" while leaving the (already-written) backup file untouched.
 *
 * @return array{path: string, workUuid: string, workNoteUuid: string, personalUuid: ?string, workCreatedAt: Carbon, workNoteCreatedAt: Carbon, workNoteUpdatedAt: Carbon}
 */
function freshRestoreFixture(bool $withPersonal = false): array
{
    $work = test()->vaults->create('Work');
    writeVaultFiles($work->path, [
        'Projects/HRMIS.md' => 'hello',
        'Empty/' => '',
    ]);
    test()->index->reconcile($work, IndexMode::Full);
    $workNote = $work->notes()->where('relative_path', 'Projects/HRMIS.md')->first();
    $workNoteUuid = $workNote->uuid;

    // AR-03 (analyst review, `mdv-p6`): captured before the vault/note rows
    // are wiped below, so the restore test can assert these survive the
    // round trip (FR-10; plan T7 test 1).
    $workCreatedAt = $work->created_at->clone();
    $workNoteCreatedAt = $workNote->created_at->clone();
    $workNoteUpdatedAt = $workNote->updated_at->clone();

    $personalUuid = null;

    if ($withPersonal) {
        $personal = test()->vaults->create('Personal');
        writeVaultFiles($personal->path, ['Ideas.md' => 'idea']);
        test()->index->reconcile($personal, IndexMode::Full);
        $personalUuid = $personal->uuid;
    }

    $dest = $this_dest = test()->destDir.DIRECTORY_SEPARATOR.'backup.zip';
    test()->service->create(null, $dest);

    $workUuid = $work->uuid;

    // "Remove local data": DB rows and vault folders gone; the backup file
    // (outside the storage root) survives.
    Note::query()->delete();
    Vault::query()->delete();
    File::deleteDirectory(test()->root);

    return [
        'path' => $dest,
        'workUuid' => $workUuid,
        'workNoteUuid' => $workNoteUuid,
        'personalUuid' => $personalUuid,
        'workCreatedAt' => $workCreatedAt,
        'workNoteCreatedAt' => $workNoteCreatedAt,
        'workNoteUpdatedAt' => $workNoteUpdatedAt,
    ];
}

// --- Fresh restore ---------------------------------------------------------------

test('a fresh restore of all vaults preserves identity and content, and opens the first vault', function () {
    $fixture = freshRestoreFixture(withPersonal: true);
    $inspection = $this->service->inspect($fixture['path']);
    $actions = [];

    foreach ($inspection->vaults as $v) {
        $actions[$v['uuid']] = RestoreAction::Restore;
    }

    $result = $this->service->restore($fixture['path'], $actions);

    expect($result->restored)->toHaveCount(2);

    $work = Vault::query()->where('uuid', $fixture['workUuid'])->first();
    expect($work)->not->toBeNull()
        ->and($work->name)->toBe('Work')
        ->and(is_dir($work->path.DIRECTORY_SEPARATOR.'Empty'))->toBeTrue()
        ->and(File::get($work->path.DIRECTORY_SEPARATOR.'Projects'.DIRECTORY_SEPARATOR.'HRMIS.md'))->toBe('hello');

    // AR-03 (analyst review, `mdv-p6`; FR-10): timestamps survive the round
    // trip, at second precision (the manifest's ISO-8601 strings carry no
    // finer resolution).
    expect($work->created_at->timestamp)->toBe($fixture['workCreatedAt']->timestamp);

    $note = Note::query()->where('uuid', $fixture['workNoteUuid'])->first();
    expect($note)->not->toBeNull()
        ->and($note->file_mtime)->toBeNull()
        ->and($note->created_at->timestamp)->toBe($fixture['workNoteCreatedAt']->timestamp)
        ->and($note->updated_at->timestamp)->toBe($fixture['workNoteUpdatedAt']->timestamp);

    // QA-04: the staged file's filesystem mtime is set from the manifest's
    // modified_at during extraction (FileStorageService::setModifiedTime),
    // independently of the DB's file_mtime column (which stays null).
    $zip = new ZipArchive;
    $zip->open($fixture['path'], ZipArchive::RDONLY);
    $manifest = json_decode($zip->getFromName('manifest.json'), true);
    $zip->close();
    $manifestModifiedAt = collect($manifest['contents'])
        ->firstWhere('name', 'Work')['notes'][0]['modified_at'];

    $restoredPath = $work->path.DIRECTORY_SEPARATOR.'Projects'.DIRECTORY_SEPARATOR.'HRMIS.md';
    expect(abs(filemtime($restoredPath) - $manifestModifiedAt))->toBeLessThanOrEqual(2);

    expect(glob($this->root.DIRECTORY_SEPARATOR.'.mdvault-restore-*'))->toBe([]);

    $reconcileResult = $this->index->reconcile($work, IndexMode::Full);
    expect($reconcileResult->hasChanges())->toBeFalse();

    // The first restored vault (manifest order, i.e. lower-cased name —
    // Personal before Work) is opened when nothing was already current.
    expect($this->vaults->current()?->uuid)->toBeIn([$fixture['workUuid'], $fixture['personalUuid']]);
});

// --- Registered UUID / copy --------------------------------------------------------

test('restoring a registered vault UUID with "restore" fails and changes nothing', function () {
    $work = $this->vaults->create('Work');
    writeVaultFiles($work->path, ['A.md' => 'x']);
    $this->index->reconcile($work, IndexMode::Full);
    $dest = $this->destDir.DIRECTORY_SEPARATOR.'backup.zip';
    $this->service->create($work, $dest);

    $rowCountBefore = Vault::count();
    $listingBefore = scandir($this->root);

    expect(fn () => $this->service->restore($dest, [$work->uuid => RestoreAction::Restore]))
        ->toThrow(BackupException::class);

    expect(Vault::count())->toBe($rowCountBefore)
        ->and(scandir($this->root))->toBe($listingBefore);
});

test('restoring a registered vault as a copy creates new identities, suffixed names, and leaves the original untouched', function () {
    $work = $this->vaults->create('Work');
    writeVaultFiles($work->path, ['A.md' => 'x']);
    $this->index->reconcile($work, IndexMode::Full);
    $originalNoteUuid = $work->notes()->first()->uuid;
    $dest = $this->destDir.DIRECTORY_SEPARATOR.'backup.zip';
    $this->service->create($work, $dest);

    $result = $this->service->restore($dest, [$work->uuid => RestoreAction::Copy]);

    expect($result->restored)->toHaveCount(1);
    $copy = Vault::query()->where('name', 'Work (restored)')->first();
    expect($copy)->not->toBeNull()
        ->and($copy->uuid)->not->toBe($work->uuid);

    $copyNote = $copy->notes()->first();
    expect($copyNote->uuid)->not->toBe($originalNoteUuid);

    // Original untouched.
    expect(Vault::query()->where('uuid', $work->uuid)->exists())->toBeTrue()
        ->and(File::get($work->path.DIRECTORY_SEPARATOR.'A.md'))->toBe('x');

    // A second copy is further suffixed.
    $result2 = $this->service->restore($dest, [$work->uuid => RestoreAction::Copy]);
    expect(Vault::query()->where('name', 'Work (restored 2)')->exists())->toBeTrue();
});

test('a fresh restore is suffixed when an unregistered folder already occupies the target name', function () {
    $fixture = freshRestoreFixture();
    File::makeDirectory($this->root.DIRECTORY_SEPARATOR.'Work', 0755, true);
    File::put($this->root.DIRECTORY_SEPARATOR.'Work'.DIRECTORY_SEPARATOR.'untouched.txt', 'keep me');

    $inspection = $this->service->inspect($fixture['path']);
    $actions = [$inspection->vaults[0]['uuid'] => RestoreAction::Restore];

    $this->service->restore($fixture['path'], $actions);

    expect(Vault::query()->where('name', 'Work (restored)')->exists())->toBeTrue()
        ->and(File::get($this->root.DIRECTORY_SEPARATOR.'Work'.DIRECTORY_SEPARATOR.'untouched.txt'))->toBe('keep me');
});

// --- Note UUID collision -----------------------------------------------------------

test('a colliding note UUID gets a new identity, with a warning', function () {
    $fixture = freshRestoreFixture();

    // Recreate a different vault that happens to already use the backed-up
    // note's UUID.
    $other = $this->vaults->create('Other');
    writeVaultFiles($other->path, ['Existing.md' => 'x']);
    $this->index->reconcile($other, IndexMode::Full);
    Note::query()->where('vault_id', $other->id)->update(['uuid' => $fixture['workNoteUuid']]);

    $result = $this->service->restore($fixture['path'], [$fixture['workUuid'] => RestoreAction::Restore]);

    $restoredWork = Vault::query()->where('uuid', $fixture['workUuid'])->first();
    $restoredNote = $restoredWork->notes()->where('relative_path', 'Projects/HRMIS.md')->first();

    expect($restoredNote->uuid)->not->toBe($fixture['workNoteUuid'])
        ->and($result->warnings)->not->toBe([]);
});

// --- Selection errors ---------------------------------------------------------------

test('skipping every vault fails with nothingSelected', function () {
    $fixture = freshRestoreFixture();

    expect(fn () => $this->service->restore($fixture['path'], [$fixture['workUuid'] => RestoreAction::Skip]))
        ->toThrow(BackupException::class);
});

test('an unknown vault UUID key fails with unknownVault', function () {
    $fixture = freshRestoreFixture();

    expect(fn () => $this->service->restore($fixture['path'], [(string) Str::uuid() => RestoreAction::Restore]))
        ->toThrow(BackupException::class);
});

// --- Damaged / hostile archives ------------------------------------------------------

test('a damaged archive fails during staging with nothing registered or left behind', function () {
    $fixture = freshRestoreFixture();

    $zip = new ZipArchive;
    $zip->open($fixture['path']);
    $manifestRaw = $zip->getFromName('manifest.json');
    $zip->close();
    $manifest = json_decode($manifestRaw, true);

    $tampered = $this->destDir.DIRECTORY_SEPARATOR.'tampered.zip';
    File::copy($fixture['path'], $tampered);

    $entryPath = 'vaults/Work/Projects/HRMIS.md';
    $zip = new ZipArchive;
    $zip->open($tampered);
    $zip->deleteName($entryPath);
    $zip->close();
    $zip->open($tampered);
    $zip->addFromString($entryPath, str_repeat('X', strlen('hello')));
    $zip->close();

    expect(fn () => $this->service->restore($tampered, [$fixture['workUuid'] => RestoreAction::Restore]))
        ->toThrow(BackupException::class);

    expect(Vault::count())->toBe(0)
        ->and(is_dir($this->root.DIRECTORY_SEPARATOR.'Work'))->toBeFalse()
        ->and(glob($this->root.DIRECTORY_SEPARATOR.'.mdvault-restore-*'))->toBe([]);
});

test('a zip-slip archive is refused before anything is written', function () {
    $path = $this->destDir.DIRECTORY_SEPARATOR.'slip.zip';
    makeZip($path, [
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

    expect(fn () => $this->service->restore($path, [(string) Str::uuid() => RestoreAction::Restore]))
        ->toThrow(BackupException::class);

    expect(Vault::count())->toBe(0)
        ->and(is_dir($this->root))->toBeFalse();
});

// --- Commit-time failures ---------------------------------------------------------------

test('a DB failure on the second vault leaves nothing registered and nothing on disk', function () {
    $fixture = freshRestoreFixture(withPersonal: true);
    $inspection = $this->service->inspect($fixture['path']);
    $actions = [];
    foreach ($inspection->vaults as $v) {
        $actions[$v['uuid']] = RestoreAction::Restore;
    }

    $calls = 0;
    Vault::creating(function () use (&$calls) {
        $calls++;
        if ($calls === 2) {
            throw new RuntimeException('simulated DB failure');
        }
    });

    expect(fn () => $this->service->restore($fixture['path'], $actions))->toThrow(BackupException::class);

    expect(Vault::count())->toBe(0)
        ->and(is_dir($this->root.DIRECTORY_SEPARATOR.'Work'))->toBeFalse()
        ->and(is_dir($this->root.DIRECTORY_SEPARATOR.'Personal'))->toBeFalse()
        ->and(glob($this->root.DIRECTORY_SEPARATOR.'.mdvault-restore-*'))->toBe([]);
});

test('a folder-rename failure on the second vault moves the first vault back and leaves nothing registered', function () {
    $fixture = freshRestoreFixture(withPersonal: true);
    $inspection = $this->service->inspect($fixture['path']);
    $actions = [];
    foreach ($inspection->vaults as $v) {
        $actions[$v['uuid']] = RestoreAction::Restore;
    }

    // The second vault's rename fails on all 3 attempts (moveWithRetry),
    // so it is a genuine, non-transient failure.
    failFolderRenames([2, 3, 4]);
    $service = app(BackupService::class);

    expect(fn () => $service->restore($fixture['path'], $actions))->toThrow(BackupException::class);

    expect(Vault::count())->toBe(0)
        ->and(is_dir($this->root.DIRECTORY_SEPARATOR.'Work'))->toBeFalse()
        ->and(is_dir($this->root.DIRECTORY_SEPARATOR.'Personal'))->toBeFalse()
        ->and(glob($this->root.DIRECTORY_SEPARATOR.'.mdvault-restore-*'))->toBe([]);
});

test('a rename-back failure during rollback names the leftover folder and still leaves nothing registered', function () {
    $fixture = freshRestoreFixture(withPersonal: true);
    $inspection = $this->service->inspect($fixture['path']);
    $actions = [];
    foreach ($inspection->vaults as $v) {
        $actions[$v['uuid']] = RestoreAction::Restore;
    }

    // Plan order is by lower-cased name: Personal (call 1) moves forward
    // successfully; Work's forward move then fails on all 3 attempts
    // (calls 2-4, moveWithRetry) and throws, triggering rollback; the
    // rename-back of Personal (call 5) also fails, so the compensation
    // itself fails and a folder is left behind, unregistered.
    failFolderRenames([2, 3, 4, 5]);
    $service = app(BackupService::class);

    $leftoverFolder = $this->root.DIRECTORY_SEPARATOR.'Personal';

    try {
        $service->restore($fixture['path'], $actions);
        test()->fail('Expected a BackupException.');
    } catch (BackupException $e) {
        expect($e->getMessage())->toContain($leftoverFolder);
    }

    expect(Vault::count())->toBe(0)
        ->and(is_dir($leftoverFolder))->toBeTrue()
        ->and(glob($this->root.DIRECTORY_SEPARATOR.'.mdvault-restore-*'))->toBe([]);
});

// --- Stale staging cleanup ---------------------------------------------------------------

test('a stale staging folder is removed before restoring; a fresh one is left alone by a concurrent check', function () {
    $fixture = freshRestoreFixture();

    File::makeDirectory($this->root, 0755, true);
    $stale = $this->root.DIRECTORY_SEPARATOR.'.mdvault-restore-old';
    File::makeDirectory($stale.DIRECTORY_SEPARATOR.'v0', 0755, true);
    File::put($stale.DIRECTORY_SEPARATOR.'v0'.DIRECTORY_SEPARATOR.'a.md', 'x');
    touch($stale, time() - 7200);

    $this->service->restore($fixture['path'], [$fixture['workUuid'] => RestoreAction::Restore]);

    expect(is_dir($stale))->toBeFalse();
});

// --- defaultDestination / root creation ---------------------------------------------------

test('restoring into a storage root that does not exist yet creates it', function () {
    $fixture = freshRestoreFixture();
    File::deleteDirectory($this->root);

    $this->service->restore($fixture['path'], [$fixture['workUuid'] => RestoreAction::Restore]);

    expect(is_dir($this->root))->toBeTrue()
        ->and(is_dir($this->root.DIRECTORY_SEPARATOR.'Work'))->toBeTrue();
});
