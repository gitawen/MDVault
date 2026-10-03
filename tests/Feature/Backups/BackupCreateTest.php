<?php

use App\Enums\IndexMode;
use App\Exceptions\BackupException;
use App\Models\Backup;
use App\Models\Note;
use App\Models\Vault;
use App\Services\BackupService;
use App\Services\VaultIndexService;
use App\Services\VaultService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mdvault-backup-create-'.Str::random(8);
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
 * Creates the Work vault fixture used across most of these tests, with the
 * files reconciled into the registry.
 */
function makeWorkVaultFixture(): Vault
{
    $vault = test()->vaults->create('Work');

    writeVaultFiles($vault->path, [
        'Projects/HRMIS.md' => "\xEF\xBB\xBFTitle\r\n\r\nBody\r\n",
        'Café/Ünïcode.md' => "unicode content\n",
        'Root.md' => '',
        'assets/logo.png' => "\x89PNG\x0D\x0A\x1A\x0A binary bytes here",
        'Empty/' => '',
        '.git/HEAD' => 'ref: refs/heads/main',
        '.obsidian/app.json' => '{}',
        'node_modules/x.md' => 'should be ignored',
        '.mdvault-save-abc' => 'temp',
    ]);

    test()->index->reconcile($vault, IndexMode::Full);

    return $vault;
}

// --- All / entries / manifest -----------------------------------------------

test('backing up all vaults writes exactly the expected entries', function () {
    makeWorkVaultFixture();

    $dest = $this->destDir.DIRECTORY_SEPARATOR.'backup.zip';
    $this->service->create(null, $dest);

    $names = zipEntryNames($dest);

    expect($names)->toContain('manifest.json')
        ->toContain('vaults/Work/')
        ->toContain('vaults/Work/Empty/')
        ->toContain('vaults/Work/Projects/HRMIS.md')
        ->toContain('vaults/Work/Café/Ünïcode.md')
        ->toContain('vaults/Work/Root.md')
        ->toContain('vaults/Work/assets/logo.png')
        ->not->toContain('vaults/Work/.git/HEAD')
        ->not->toContain('vaults/Work/.obsidian/app.json')
        ->not->toContain('vaults/Work/node_modules/x.md')
        ->not->toContain('vaults/Work/.mdvault-save-abc');
});

test('the manifest keys, counts and per-note UUIDs are correct, with no absolute paths', function () {
    $vault = makeWorkVaultFixture();
    $note = $vault->notes()->where('relative_path', 'Projects/HRMIS.md')->first();

    $dest = $this->destDir.DIRECTORY_SEPARATOR.'backup.zip';
    $this->service->create(null, $dest);

    $manifest = json_decode(zipEntryContents($dest, 'manifest.json'), true);

    expect($manifest['application'])->toBe('MDVault')
        ->and($manifest['format'])->toBe('mdvault-backup')
        ->and($manifest['format_version'])->toBe(2)
        ->and($manifest['database_version'])->toBe(1)
        ->and($manifest['hash_algorithm'])->toBe('sha256')
        ->and($manifest['scope'])->toBe('all')
        ->and($manifest['vaults'])->toBe(1)
        ->and($manifest['notes'])->toBe(3);

    $vaultEntry = collect($manifest['contents'])->firstWhere('name', 'Work');
    expect($vaultEntry['uuid'])->toBe($vault->uuid)
        ->and($vaultEntry['archive_path'])->toBe('vaults/Work')
        ->and($vaultEntry['directories'])->toContain('Empty');

    $noteEntry = collect($vaultEntry['notes'])->firstWhere('relative_path', 'Projects/HRMIS.md');
    expect($noteEntry['uuid'])->toBe($note->uuid)
        ->and($noteEntry['file_hash'])->toBe(hash_file('sha256', $vault->path.DIRECTORY_SEPARATOR.'Projects'.DIRECTORY_SEPARATOR.'HRMIS.md'));

    expect($manifest)->not->toContainEqual($vault->path);
    $json = zipEntryContents($dest, 'manifest.json');
    expect($json)->not->toContain(str_replace('\\', '/', $vault->path));
});

test('the bytes of every entry equal the source, including CRLF+BOM and binary content', function () {
    $vault = makeWorkVaultFixture();

    $dest = $this->destDir.DIRECTORY_SEPARATOR.'backup.zip';
    $this->service->create(null, $dest);

    expect(zipEntryContents($dest, 'vaults/Work/Projects/HRMIS.md'))
        ->toBe(File::get($vault->path.DIRECTORY_SEPARATOR.'Projects'.DIRECTORY_SEPARATOR.'HRMIS.md'));

    expect(zipEntryContents($dest, 'vaults/Work/assets/logo.png'))
        ->toBe(File::get($vault->path.DIRECTORY_SEPARATOR.'assets'.DIRECTORY_SEPARATOR.'logo.png'));
});

test('a .md file created externally just before the backup is registered and included', function () {
    $vault = $this->vaults->create('Work');
    writeVaultFiles($vault->path, ['New.md' => 'fresh content']);

    $dest = $this->destDir.DIRECTORY_SEPARATOR.'backup.zip';
    $this->service->create(null, $dest);

    $note = Note::query()->where('relative_path', 'New.md')->first();
    expect($note)->not->toBeNull();

    $manifest = json_decode(zipEntryContents($dest, 'manifest.json'), true);
    $vaultEntry = collect($manifest['contents'])->firstWhere('name', 'Work');
    $noteEntry = collect($vaultEntry['notes'])->firstWhere('relative_path', 'New.md');

    expect($noteEntry['uuid'])->toBe($note->uuid);
});

// --- Single vault -------------------------------------------------------------

test('backing up a single vault includes only that vault', function () {
    makeWorkVaultFixture();
    $personal = $this->vaults->create('Personal');
    writeVaultFiles($personal->path, ['Ideas.md' => 'idea']);
    $this->index->reconcile($personal, IndexMode::Full);

    $dest = $this->destDir.DIRECTORY_SEPARATOR.'backup.zip';
    $result = $this->service->create($personal, $dest);

    expect($result->vaultCount)->toBe(1)
        ->and($this->service->suggestedFilename($personal))->toContain('Personal');

    $manifest = json_decode(zipEntryContents($dest, 'manifest.json'), true);
    expect($manifest['scope'])->toBe('vault')
        ->and(collect($manifest['contents'])->pluck('name')->all())->toBe(['Personal']);
});

// --- Missing vaults -------------------------------------------------------------

test('backing up all vaults skips a missing one and names it in the result', function () {
    makeWorkVaultFixture();
    $personal = $this->vaults->create('Personal');
    File::deleteDirectory($personal->path);

    $dest = $this->destDir.DIRECTORY_SEPARATOR.'backup.zip';
    $result = $this->service->create(null, $dest);

    expect($result->vaultCount)->toBe(1)
        ->and($result->skippedVaults)->toBe(['Personal']);
});

test('backing up a single missing vault fails and writes nothing', function () {
    $vault = $this->vaults->create('Work');
    File::deleteDirectory($vault->path);

    $dest = $this->destDir.DIRECTORY_SEPARATOR.'backup.zip';

    expect(fn () => $this->service->create($vault, $dest))->toThrow(BackupException::class);
    expect(File::exists($dest))->toBeFalse();
});

// --- Destination rules -------------------------------------------------------

test('a relative destination is rejected', function () {
    makeWorkVaultFixture();

    expect(fn () => $this->service->create(null, 'relative.zip'))->toThrow(BackupException::class);
});

test('an existing destination file is rejected and left unchanged', function () {
    makeWorkVaultFixture();
    $dest = $this->destDir.DIRECTORY_SEPARATOR.'existing.zip';
    File::put($dest, 'not a backup');

    expect(fn () => $this->service->create(null, $dest))->toThrow(BackupException::class);
    expect(File::get($dest))->toBe('not a backup');
});

test('a destination inside a vault is rejected', function () {
    $vault = makeWorkVaultFixture();
    $dest = $vault->path.DIRECTORY_SEPARATOR.'backup.zip';

    expect(fn () => $this->service->create(null, $dest))->toThrow(BackupException::class);
});

test('a destination with a missing parent folder is rejected', function () {
    makeWorkVaultFixture();
    $dest = $this->tmp.DIRECTORY_SEPARATOR.'missing-folder'.DIRECTORY_SEPARATOR.'backup.zip';

    expect(fn () => $this->service->create(null, $dest))->toThrow(BackupException::class);
});

test('.zip is appended when missing', function () {
    makeWorkVaultFixture();
    $dest = $this->destDir.DIRECTORY_SEPARATOR.'backup-no-ext';

    $result = $this->service->create(null, $dest);

    expect($result->path)->toBe($dest.'.zip')
        ->and(File::exists($dest.'.zip'))->toBeTrue();
});

test('a destination name starting with a dot is rejected', function () {
    makeWorkVaultFixture();
    $dest = $this->destDir.DIRECTORY_SEPARATOR.'.hidden.zip';

    expect(fn () => $this->service->create(null, $dest))->toThrow(BackupException::class);
});

// --- Publish / record failures ------------------------------------------------

test('a failed publish leaves no target, no temp file and no record', function () {
    makeWorkVaultFixture();
    failFileMoves([1]);
    $service = app(BackupService::class);

    $dest = $this->destDir.DIRECTORY_SEPARATOR.'backup.zip';

    expect(fn () => $service->create(null, $dest))->toThrow(BackupException::class);

    expect(File::exists($dest))->toBeFalse()
        ->and(glob($this->destDir.DIRECTORY_SEPARATOR.'.mdvault-backup-*'))->toBe([])
        ->and(Backup::count())->toBe(0);
});

test('a record-insert failure keeps the file and reports not recorded', function () {
    makeWorkVaultFixture();
    Backup::creating(fn () => throw new RuntimeException('boom'));

    $dest = $this->destDir.DIRECTORY_SEPARATOR.'backup.zip';
    $result = $this->service->create(null, $dest);

    expect($result->recorded)->toBeFalse()
        ->and(File::exists($dest))->toBeTrue()
        ->and(Backup::count())->toBe(0);
});

// --- Analyst review AR-01: create()'s own catch/cleanup around archives->write() ---

test('create throws BackupException and leaves no temp file when the archive write fails', function () {
    makeWorkVaultFixture();

    // Force a deterministic collision at ArchiveService::write()'s
    // exclusive-create step (Str::createRandomStringsUsing is Laravel's own
    // testing hook for Str::random()), so the archive write fails and
    // BackupService::create()'s own catch(\Throwable) contract (AR-01:
    // report() anything that isn't a BackupException, discard the temp
    // file, throw archiveWriteFailed) is exercised through a real create()
    // call. Which failure mode ArchiveService itself hits (open/add/close)
    // doesn't matter here — that distinction is covered directly by
    // ArchiveServiceTest.
    Str::createRandomStringsUsing(fn (int $length): string => str_repeat('x', $length));

    $collidingTemp = $this->destDir.DIRECTORY_SEPARATOR.'.mdvault-backup-'.str_repeat('x', 12).'.zip';
    File::put($collidingTemp, 'already here');

    $dest = $this->destDir.DIRECTORY_SEPARATOR.'backup.zip';

    try {
        expect(fn () => $this->service->create(null, $dest))->toThrow(BackupException::class);
    } finally {
        Str::createRandomStringsNormally();
    }

    // create()'s catch(\Throwable) discards whatever sits at $tmp once
    // archives->write() fails — including the pre-existing collider above,
    // which is exactly the "no .mdvault-backup-* file left behind" contract
    // this test exists to prove.
    expect(glob($this->destDir.DIRECTORY_SEPARATOR.'.mdvault-backup-*'))->toBe([])
        ->and(File::exists($dest))->toBeFalse()
        ->and(Backup::count())->toBe(0);
});

// --- Recording / recent() ------------------------------------------------------

test('a successful backup is recorded with correct counts and hash, and appears in recent()', function () {
    makeWorkVaultFixture();
    $dest = $this->destDir.DIRECTORY_SEPARATOR.'backup.zip';

    $result = $this->service->create(null, $dest);

    expect(Backup::count())->toBe(1);
    $row = Backup::first();
    expect($row->file_hash)->toBe(hash_file('sha256', $dest))
        ->and($row->vault_count)->toBe(1)
        ->and($row->note_count)->toBe(3);

    $recent = $this->service->recent();
    expect($recent)->toHaveCount(1)
        ->and($recent[0]['exists'])->toBeTrue();

    File::delete($dest);
    $recentAfterDelete = $this->service->recent();
    expect($recentAfterDelete[0]['exists'])->toBeFalse();

    expect($result->size)->toBe($row->file_size);
});

// --- Stale temp cleanup --------------------------------------------------------

test('a stale backup temp file is removed before writing, a fresh one is kept', function () {
    makeWorkVaultFixture();

    $old = $this->destDir.DIRECTORY_SEPARATOR.'.mdvault-backup-old.zip';
    File::put($old, 'x');
    touch($old, time() - 7200);

    $fresh = $this->destDir.DIRECTORY_SEPARATOR.'.mdvault-backup-fresh.zip';
    File::put($fresh, 'x');

    $dest = $this->destDir.DIRECTORY_SEPARATOR.'backup.zip';
    $this->service->create(null, $dest);

    expect(file_exists($old))->toBeFalse()
        ->and(file_exists($fresh))->toBeTrue();
});

// --- suggestedFilename / defaultDestination ------------------------------------

test('suggestedFilename includes the vault name for a single vault and not for all', function () {
    $vault = Vault::factory()->make(['name' => 'Work']);

    expect($this->service->suggestedFilename(null))->toStartWith('MDVault-Backup-')
        ->and($this->service->suggestedFilename($vault))->toContain('Work');
});

test('defaultDestination creates the default folder when asked and points into it', function () {
    // Documents itself always exists on a real desktop; this test's fake
    // documents path needs the same precondition made explicit.
    File::makeDirectory($this->tmp.DIRECTORY_SEPARATOR.'Documents', 0755, true);

    $dest = $this->service->defaultDestination(null, true);

    expect(is_dir($this->tmp.DIRECTORY_SEPARATOR.'Documents'.DIRECTORY_SEPARATOR.'MDVault Backups'))->toBeTrue()
        ->and($dest)->toStartWith($this->tmp.DIRECTORY_SEPARATOR.'Documents'.DIRECTORY_SEPARATOR.'MDVault Backups');
});
