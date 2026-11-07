<?php

use App\Enums\SettingKey;
use App\Models\Note;
use App\Models\Vault;
use App\Services\SettingsService;
use App\Services\VaultService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mdvault-batch-vault-'.Str::random(8);
    fakeDocumentsDirectory($this->tmp.DIRECTORY_SEPARATOR.'Documents');
});

afterEach(function () {
    File::deleteDirectory($this->tmp);
});

test('batch registering multiple valid directories succeeds, registers all vaults, indexes notes, sets first vault as current, and redirects to vaults.index', function () {
    $dir1 = $this->tmp.DIRECTORY_SEPARATOR.'WorkVault';
    $dir2 = $this->tmp.DIRECTORY_SEPARATOR.'PersonalVault';

    File::makeDirectory($dir1, 0755, true);
    File::makeDirectory($dir2, 0755, true);

    File::put($dir1.DIRECTORY_SEPARATOR.'work.md', '# Work');
    File::put($dir2.DIRECTORY_SEPARATOR.'personal.md', '# Personal');

    $response = $this->post(route('vaults.existing.batch'), [
        'paths' => [$dir1, $dir2],
    ]);

    $response->assertRedirect(route('vaults.index'));
    $response->assertInertiaFlash('toast.type', 'success');
    $response->assertInertiaFlash('toast.message', '2 vaults added. 2 note(s) indexed.');

    expect(Vault::count())->toBe(2);

    $workVault = Vault::query()->where('name', 'WorkVault')->firstOrFail();
    $personalVault = Vault::query()->where('name', 'PersonalVault')->firstOrFail();

    expect(app(SettingsService::class)->string(SettingKey::CurrentVault))->toBe($workVault->uuid)
        ->and(Note::count())->toBe(2);
});

test('batch registering single folder redirects to workspace', function () {
    $dir = $this->tmp.DIRECTORY_SEPARATOR.'SingleVault';
    File::makeDirectory($dir, 0755, true);
    File::put($dir.DIRECTORY_SEPARATOR.'note.md', '# Note');

    $response = $this->post(route('vaults.existing.batch'), [
        'paths' => [$dir],
    ]);

    $response->assertRedirect(route('workspace'));
    $response->assertInertiaFlash('toast.type', 'success');
    $response->assertInertiaFlash('toast.message', '1 vault added. 1 note(s) indexed.');

    $vault = Vault::query()->sole();

    expect(app(SettingsService::class)->string(SettingKey::CurrentVault))->toBe($vault->uuid)
        ->and(Note::count())->toBe(1);
});

test('batch registering with partial failure skips duplicate and registers valid folder with warning toast', function () {
    $dirExisting = $this->tmp.DIRECTORY_SEPARATOR.'ExistingVault';
    $dirNew = $this->tmp.DIRECTORY_SEPARATOR.'NewVault';

    File::makeDirectory($dirExisting, 0755, true);
    File::makeDirectory($dirNew, 0755, true);

    File::put($dirExisting.DIRECTORY_SEPARATOR.'e.md', '# Existing');
    File::put($dirNew.DIRECTORY_SEPARATOR.'n.md', '# New');

    app(VaultService::class)->register($dirExisting, 'ExistingVault');

    expect(Vault::count())->toBe(1);

    $response = $this->post(route('vaults.existing.batch'), [
        'paths' => [$dirExisting, $dirNew],
    ]);

    $response->assertRedirect(route('vaults.index'));
    $response->assertInertiaFlash('toast.type', 'warning');

    $message = session('inertia.flash_data')['toast']['message'];
    expect($message)->toContain('1 vault added')
        ->and($message)->toContain('1 skipped: ExistingVault');

    expect(Vault::count())->toBe(2);

    $newVault = Vault::query()->where('name', 'NewVault')->firstOrFail();
    expect(app(SettingsService::class)->string(SettingKey::CurrentVault))->toBe($newVault->uuid);
});

test('complete failure when all folders are invalid raises validation exception on paths', function () {
    $dir1 = $this->tmp.DIRECTORY_SEPARATOR.'FirstVault';
    $dir2 = $this->tmp.DIRECTORY_SEPARATOR.'SecondVault';

    File::makeDirectory($dir1, 0755, true);
    File::makeDirectory($dir2, 0755, true);

    app(VaultService::class)->register($dir1, 'FirstVault');
    app(VaultService::class)->register($dir2, 'SecondVault');

    expect(Vault::count())->toBe(2);

    $response = $this->post(route('vaults.existing.batch'), [
        'paths' => [$dir1, $dir2],
    ]);

    $response->assertSessionHasErrors('paths');
    expect(Vault::count())->toBe(2);
});

test('overlapping directories in same batch registers parent and skips child', function () {
    $parent = $this->tmp.DIRECTORY_SEPARATOR.'ParentDir';
    $child = $parent.DIRECTORY_SEPARATOR.'ChildDir';

    File::makeDirectory($child, 0755, true);
    File::put($parent.DIRECTORY_SEPARATOR.'p.md', '# Parent');
    File::put($child.DIRECTORY_SEPARATOR.'c.md', '# Child');

    $response = $this->post(route('vaults.existing.batch'), [
        'paths' => [$parent, $child],
    ]);

    $response->assertRedirect(route('vaults.index'));
    $response->assertInertiaFlash('toast.type', 'warning');

    $message = session('inertia.flash_data')['toast']['message'];
    expect($message)->toContain('1 vault added')
        ->and($message)->toContain('1 skipped: ChildDir');

    expect(Vault::count())->toBe(1)
        ->and(Vault::query()->sole()->name)->toBe('ParentDir');
});

test('payload validation rejects empty, duplicate, or missing paths', function () {
    $dir = $this->tmp.DIRECTORY_SEPARATOR.'Sample';
    File::makeDirectory($dir, 0755, true);

    $this->post(route('vaults.existing.batch'), [])
        ->assertSessionHasErrors('paths');

    $this->post(route('vaults.existing.batch'), ['paths' => []])
        ->assertSessionHasErrors('paths');

    $this->post(route('vaults.existing.batch'), ['paths' => [$dir, $dir]])
        ->assertSessionHasErrors();
});
