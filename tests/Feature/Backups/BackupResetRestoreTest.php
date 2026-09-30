<?php

use App\Enums\IndexMode;
use App\Enums\RestoreAction;
use App\Exceptions\BackupException;
use App\Models\Note;
use App\Models\Vault;
use App\Services\BackupService;
use App\Services\StoragePathService;
use App\Services\VaultIndexService;
use App\Services\VaultService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mdvault-reset-restore-'.Str::random(8);
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
 * Creates Work (+ optionally Personal) vaults with content and backs them
 * all up, but — unlike `freshRestoreFixture()` in `BackupRestoreTest.php` —
 * only deletes the vault FOLDERS from disk. The `vaults`/`notes` rows are
 * left registered (Missing), simulating "the user deleted the folders by
 * hand" (requirements.md "Problem Statement").
 *
 * @return array{path: string, root: string, workUuid: string, workNoteUuid: string, workName: string, personalUuid: ?string}
 */
function missingVaultsFixture(bool $withPersonal = false): array
{
    $work = test()->vaults->create('Work');
    writeVaultFiles($work->path, ['Projects/HRMIS.md' => 'hello']);
    test()->index->reconcile($work, IndexMode::Full);
    $workNote = $work->notes()->where('relative_path', 'Projects/HRMIS.md')->first();

    $personalUuid = null;

    if ($withPersonal) {
        $personal = test()->vaults->create('Personal');
        writeVaultFiles($personal->path, ['Ideas.md' => 'idea']);
        test()->index->reconcile($personal, IndexMode::Full);
        $personalUuid = $personal->uuid;
    }

    $dest = test()->destDir.DIRECTORY_SEPARATOR.'backup.zip';
    test()->service->create(null, $dest);

    $workUuid = $work->uuid;
    $workName = $work->name;

    // The user deletes the vault folders by hand; the registry rows stay.
    File::deleteDirectory($work->path);

    if ($withPersonal) {
        File::deleteDirectory($personal->path);
    }

    return [
        'path' => $dest,
        'root' => test()->root,
        'workUuid' => $workUuid,
        'workNoteUuid' => $workNote->uuid,
        'workName' => $workName,
        'personalUuid' => $personalUuid,
    ];
}

test('deleted vault folders show as missing, and a backup of them is skipped by default until a reset', function () {
    $fixture = missingVaultsFixture();

    $all = $this->vaults->all();
    expect($all)->toHaveCount(1)
        ->and($all[0]->status->value)->toBe('missing');

    $inspection = $this->service->inspect($fixture['path']);
    $vaultInfo = $inspection->vaults[0];

    expect($vaultInfo['state'])->toBe('exists')
        ->and($vaultInfo['default_action'])->toBe(RestoreAction::Skip->value);
});

test('the user scenario end to end: reset, then a fresh restore recovers the original names, UUIDs and content', function () {
    $fixture = missingVaultsFixture(withPersonal: true);

    $this->vaults->resetRegistry();

    $inspection = $this->service->inspect($fixture['path']);

    foreach ($inspection->vaults as $vaultData) {
        expect($vaultData['state'])->toBe('new')
            ->and($vaultData['default_action'])->toBe(RestoreAction::Restore->value);

        if ($vaultData['uuid'] === $fixture['workUuid']) {
            expect($vaultData['restore_name'])->toBe($fixture['workName']);
        }
    }

    $actions = [];
    foreach ($inspection->vaults as $vaultData) {
        $actions[$vaultData['uuid']] = RestoreAction::Restore;
    }

    $result = $this->service->restore($fixture['path'], $actions);

    expect($result->restored)->toHaveCount(2);

    $work = Vault::query()->where('uuid', $fixture['workUuid'])->first();
    expect($work)->not->toBeNull()
        ->and($work->name)->toBe('Work')
        ->and($work->status->value)->toBe('active')
        ->and($work->path)->toBe(realpath($fixture['root'].DIRECTORY_SEPARATOR.'Work'))
        ->and(File::get($work->path.DIRECTORY_SEPARATOR.'Projects'.DIRECTORY_SEPARATOR.'HRMIS.md'))->toBe('hello')
        ->and(hash_file('sha256', $work->path.DIRECTORY_SEPARATOR.'Projects'.DIRECTORY_SEPARATOR.'HRMIS.md'))
        ->toBe(hash('sha256', 'hello'));

    $note = Note::query()->where('uuid', $fixture['workNoteUuid'])->first();
    expect($note)->not->toBeNull()
        ->and($note->vault->uuid)->toBe($fixture['workUuid']);
});

test('a custom storage root set before the reset is still used by the restore', function () {
    app(StoragePathService::class)->changeRoot($this->tmp.DIRECTORY_SEPARATOR.'custom-root', 'MyVault');
    $customRoot = app(StoragePathService::class)->rootPath();

    $work = $this->vaults->create('Work');
    writeVaultFiles($work->path, ['A.md' => 'hello']);
    $this->index->reconcile($work, IndexMode::Full);

    $dest = $this->destDir.DIRECTORY_SEPARATOR.'custom-root-backup.zip';
    $this->service->create(null, $dest);
    $workUuid = $work->uuid;
    File::deleteDirectory($work->path);

    $this->vaults->resetRegistry();

    $inspection = $this->service->inspect($dest);
    $result = $this->service->restore($dest, [$workUuid => RestoreAction::Restore]);

    expect($result->restored)->toHaveCount(1);

    $restored = Vault::query()->where('uuid', $workUuid)->first();
    expect($restored->path)->toBe(realpath($customRoot.DIRECTORY_SEPARATOR.'Work'));
});

test('without a reset, restore on a registered UUID is refused and copy gets a "(restored)" name', function () {
    $fixture = missingVaultsFixture();

    // No reset: the vault row is still registered (status Missing).
    expect(fn () => $this->service->restore($fixture['path'], [$fixture['workUuid'] => RestoreAction::Restore]))
        ->toThrow(BackupException::class);

    $result = $this->service->restore($fixture['path'], [$fixture['workUuid'] => RestoreAction::Copy]);

    expect($result->restored)->toHaveCount(1)
        ->and($result->restored[0]['name'])->toBe('Work (restored)');
});
