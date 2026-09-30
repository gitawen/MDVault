<?php

use App\Enums\SettingKey;
use App\Enums\VaultStatus;
use App\Exceptions\VaultOperationException;
use App\Models\Vault;
use App\Services\SettingsService;
use App\Services\StoragePathService;
use App\Services\VaultService;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mdvault-vault-tests-'.Str::random(8);
    fakeDocumentsDirectory($this->tmp.DIRECTORY_SEPARATOR.'Documents');
    $this->root = $this->tmp.DIRECTORY_SEPARATOR.'Documents'.DIRECTORY_SEPARATOR.'MDVault';
    $this->service = app(VaultService::class);
});

afterEach(function () {
    File::deleteDirectory($this->tmp);
});

// --- Create ---------------------------------------------------------------

test('create: happy path creates the folder and the record', function () {
    $vault = $this->service->create('Work');

    expect(is_dir($this->root.DIRECTORY_SEPARATOR.'Work'))->toBeTrue()
        ->and(Vault::count())->toBe(1)
        ->and($vault->path)->toBe(realpath($this->root.DIRECTORY_SEPARATOR.'Work'))
        ->and($vault->relative_path)->toBe('Work')
        ->and($vault->status)->toBe(VaultStatus::Active)
        ->and(glob($vault->path.DIRECTORY_SEPARATOR.'.mdvault-write-test-*'))->toBe([]);
});

test('create: persists across a fresh scoped instance (restart)', function () {
    $vault = $this->service->create('Work');

    app()->forgetScopedInstances();

    $all = app(VaultService::class)->all();

    expect($all)->toHaveCount(1)
        ->and($all[0]->uuid)->toBe($vault->uuid);
});

test('create: invalid names are rejected and nothing is written', function (string $name) {
    try {
        $this->service->create($name);
        test()->fail('Expected a VaultOperationException.');
    } catch (VaultOperationException $e) {
        expect($e->field())->toBe('name');
    }

    expect(Vault::count())->toBe(0)
        ->and(is_dir($this->root))->toBeFalse();
})->with([
    'empty' => [''],
    'leading space' => [' Work'],
    'trailing dot' => ['Work.'],
    'forward slash' => ['a/b'],
    'back slash' => ['a\\b'],
    'reserved' => ['CON'],
    'reserved with extension' => ['nul.txt'],
    'invalid character' => ['x?'],
    'too long' => [str_repeat('a', 101)],
]);

test('create: a duplicate name is rejected case-insensitively', function () {
    $this->service->create('Work');

    try {
        $this->service->create('work');
        test()->fail('Expected a VaultOperationException.');
    } catch (VaultOperationException $e) {
        expect($e->field())->toBe('name');
    }

    expect(Vault::count())->toBe(1);
});

test('create: a non-empty existing target folder is refused', function () {
    File::makeDirectory($this->root.DIRECTORY_SEPARATOR.'Work', 0755, true);
    File::put($this->root.DIRECTORY_SEPARATOR.'Work'.DIRECTORY_SEPARATOR.'a.md', 'hi');

    expect(fn () => $this->service->create('Work'))->toThrow(VaultOperationException::class);

    expect(File::get($this->root.DIRECTORY_SEPARATOR.'Work'.DIRECTORY_SEPARATOR.'a.md'))->toBe('hi')
        ->and(Vault::count())->toBe(0);
});

test('create: an empty existing target folder is reused', function () {
    File::makeDirectory($this->root.DIRECTORY_SEPARATOR.'Work', 0755, true);

    $vault = $this->service->create('Work');

    expect($vault->path)->toBe(realpath($this->root.DIRECTORY_SEPARATOR.'Work'))
        ->and(Vault::count())->toBe(1);
});

test('create: a file at the target is refused', function () {
    File::makeDirectory($this->root, 0755, true);
    File::put($this->root.DIRECTORY_SEPARATOR.'Work', 'x');

    expect(fn () => $this->service->create('Work'))->toThrow(VaultOperationException::class);

    expect(Vault::count())->toBe(0);
});

test('create: an unusable storage root is reported', function () {
    File::makeDirectory($this->tmp, 0755, true);
    $blocker = $this->tmp.DIRECTORY_SEPARATOR.'blocker.txt';
    File::put($blocker, 'x');
    app(SettingsService::class)->set(SettingKey::StorageRootPath, $blocker);

    expect(fn () => $this->service->create('Work'))->toThrow(VaultOperationException::class);

    expect(Vault::count())->toBe(0);
});

test('create: a DB failure after mkdir compensates by removing the created folder', function () {
    // 'saving' fires unconditionally before the isDirty() short-circuit, so
    // this reliably simulates a DB failure on the second write (the
    // path-canonicalisation update) even when canonical() doesn't change
    // the string (e.g. no symlinks/case differences on this filesystem).
    Vault::saving(function (Vault $vault): void {
        if ($vault->exists) {
            throw new RuntimeException('db down');
        }
    });

    expect(fn () => $this->service->create('Work'))->toThrow(RuntimeException::class, 'db down');

    expect(Vault::count())->toBe(0)
        ->and(is_dir($this->root.DIRECTORY_SEPARATOR.'Work'))->toBeFalse();
});

test('create: a target turned into a file after insert fails to create and rolls back', function () {
    Vault::created(function (Vault $vault) {
        File::put($vault->path, 'x');
    });

    expect(fn () => $this->service->create('Work'))->toThrow(VaultOperationException::class);

    expect(Vault::count())->toBe(0)
        ->and(File::get($this->root.DIRECTORY_SEPARATOR.'Work'))->toBe('x');
});

test('create: overlapping a registered vault via the storage root is refused', function () {
    $outer = $this->tmp.DIRECTORY_SEPARATOR.'Outer';
    File::makeDirectory($outer, 0755, true);
    $this->service->register($outer, 'Outer');

    app(StoragePathService::class)->changeRoot($outer, 'Inner-Root');

    expect(fn () => $this->service->create('Inner'))->toThrow(VaultOperationException::class);
});

// --- Rename -----------------------------------------------------------------

test('rename: happy path renames the folder and updates path/relative_path', function () {
    $vault = $this->service->create('Work');
    File::put($vault->path.DIRECTORY_SEPARATOR.'a.md', 'hi');
    $uuid = $vault->uuid;

    $renamed = $this->service->rename($vault, 'Office', 'new description');

    expect(File::get($this->root.DIRECTORY_SEPARATOR.'Office'.DIRECTORY_SEPARATOR.'a.md'))->toBe('hi')
        ->and(is_dir($this->root.DIRECTORY_SEPARATOR.'Work'))->toBeFalse()
        ->and($renamed->path)->toBe(realpath($this->root.DIRECTORY_SEPARATOR.'Office'))
        ->and($renamed->relative_path)->toBe('Office')
        ->and($renamed->status)->toBe(VaultStatus::Active)
        ->and($renamed->description)->toBe('new description')
        ->and($renamed->uuid)->toBe($uuid);
});

test('rename: a duplicate name is rejected and nothing is touched', function () {
    $this->service->create('Work');
    $other = $this->service->create('Office');
    File::put($other->path.DIRECTORY_SEPARATOR.'a.md', 'hi');
    $original = $other->only(['name', 'description', 'path', 'relative_path']);
    $uuid = $other->uuid;

    expect(fn () => $this->service->rename($other, 'Work', null))->toThrow(VaultOperationException::class);

    expect($other->fresh()->only(['name', 'description', 'path', 'relative_path']))->toBe($original)
        ->and($other->fresh()->uuid)->toBe($uuid)
        ->and(is_dir($other->path))->toBeTrue()
        ->and(File::get($other->path.DIRECTORY_SEPARATOR.'a.md'))->toBe('hi');
});

test('rename: a letter-case-only change of its own name renames the folder through a temp sibling', function () {
    $vault = $this->service->create('Work');
    File::put($vault->path.DIRECTORY_SEPARATOR.'a.md', 'hi');
    $uuid = $vault->uuid;

    $renamed = $this->service->rename($vault, 'WORK', null);

    expect($renamed->name)->toBe('WORK')
        ->and($renamed->uuid)->toBe($uuid)
        ->and(scandir($this->root))->toContain('WORK')
        ->and(scandir($this->root))->not->toContain('Work')
        ->and(File::get($renamed->path.DIRECTORY_SEPARATOR.'a.md'))->toBe('hi')
        ->and(glob($this->root.DIRECTORY_SEPARATOR.'.mdvault-rename-*'))->toBe([]);
});

test('rename: a description-only edit never touches the filesystem, even when the folder is gone', function () {
    $vault = $this->service->create('Work');
    File::deleteDirectory($vault->path);
    $originalPath = $vault->path;
    $uuid = $vault->uuid;

    $renamed = $this->service->rename($vault, 'Work', 'new description');

    expect($renamed->path)->toBe($originalPath)
        ->and($renamed->description)->toBe('new description')
        ->and($renamed->uuid)->toBe($uuid)
        ->and(is_dir($originalPath))->toBeFalse();
});

test('rename: a missing folder plus a name change is refused', function () {
    $vault = $this->service->create('Work');
    File::deleteDirectory($vault->path);
    $original = $vault->only(['name', 'description', 'path', 'relative_path']);

    try {
        $this->service->rename($vault, 'Office', null);
        test()->fail('Expected a VaultOperationException.');
    } catch (VaultOperationException $e) {
        expect($e->field())->toBe('name');
    }

    expect($vault->fresh()->only(['name', 'description', 'path', 'relative_path']))->toBe($original);
});

test('rename: the target already exists and is refused, leaving both sides intact', function (Closure $makeTarget) {
    $vault = $this->service->create('Work');
    File::put($vault->path.DIRECTORY_SEPARATOR.'a.md', 'hi');
    $target = $this->root.DIRECTORY_SEPARATOR.'Office';
    $makeTarget($target);
    $original = $vault->only(['name', 'description', 'path', 'relative_path']);

    try {
        $this->service->rename($vault, 'Office', null);
        test()->fail('Expected a VaultOperationException.');
    } catch (VaultOperationException $e) {
        expect($e->field())->toBe('name');
    }

    expect($vault->fresh()->only(['name', 'description', 'path', 'relative_path']))->toBe($original)
        ->and(File::get($vault->path.DIRECTORY_SEPARATOR.'a.md'))->toBe('hi')
        ->and(file_exists($target))->toBeTrue();
})->with([
    'non-empty directory' => [function (string $target): void {
        File::makeDirectory($target, 0755, true);
        File::put($target.DIRECTORY_SEPARATOR.'x.md', 'x');
    }],
    'empty directory' => [function (string $target): void {
        File::makeDirectory($target, 0755, true);
    }],
    'a file' => [function (string $target): void {
        File::ensureDirectoryExists(dirname($target));
        File::put($target, 'x');
    }],
]);

test('rename: a different directory whose name differs only in case is refused', function () {
    $vault = $this->service->create('Work');
    File::makeDirectory($this->root.DIRECTORY_SEPARATOR.'work');

    expect(fn () => $this->service->rename($vault, 'work', null))->toThrow(VaultOperationException::class);
})->onlyOnLinux();

test('rename: the target path is recorded by a missing vault and is refused', function () {
    $vault = $this->service->create('Work');
    File::put($vault->path.DIRECTORY_SEPARATOR.'a.md', 'hi');
    Vault::factory()->missing()->create(['name' => 'Other', 'path' => $this->root.DIRECTORY_SEPARATOR.'Office']);

    expect(fn () => $this->service->rename($vault, 'Office', null))->toThrow(VaultOperationException::class);

    expect(is_dir($vault->path))->toBeTrue()
        ->and(File::get($vault->path.DIRECTORY_SEPARATOR.'a.md'))->toBe('hi');
});

test('rename: an unsafe folder is refused', function () {
    File::ensureDirectoryExists($this->tmp.DIRECTORY_SEPARATOR.'Documents');
    $vault = Vault::factory()->create(['path' => $this->tmp.DIRECTORY_SEPARATOR.'Documents']);

    try {
        $this->service->rename($vault, 'Office', null);
        test()->fail('Expected a VaultOperationException.');
    } catch (VaultOperationException $e) {
        expect($e->field())->toBe('name');
    }

    expect(is_dir($this->tmp.DIRECTORY_SEPARATOR.'Documents'))->toBeTrue();
});

test('rename: a lock simulated on the first move fails cleanly and changes nothing', function () {
    $vault = $this->service->create('Work');
    File::put($vault->path.DIRECTORY_SEPARATOR.'a.md', 'hi');
    $original = $vault->only(['name', 'description', 'path', 'relative_path']);
    failFolderRenames([1]);
    $service = app(VaultService::class);

    try {
        $service->rename($vault, 'Office', null);
        test()->fail('Expected a VaultOperationException.');
    } catch (VaultOperationException $e) {
        expect($e->field())->toBe('name')
            ->and($e->getMessage())->toContain('Close any programs using it');
    }

    expect($vault->fresh()->only(['name', 'description', 'path', 'relative_path']))->toBe($original)
        ->and(is_dir($vault->path))->toBeTrue()
        ->and(File::get($vault->path.DIRECTORY_SEPARATOR.'a.md'))->toBe('hi');
});

test('rename: a DB failure after the folder rename renames the folder back', function () {
    $vault = $this->service->create('Work');
    File::put($vault->path.DIRECTORY_SEPARATOR.'a.md', 'hi');
    $originalPath = $vault->path;
    Vault::saving(function (Vault $model): void {
        if ($model->exists) {
            throw new RuntimeException('db down');
        }
    });

    expect(fn () => $this->service->rename($vault, 'Office', null))->toThrow(RuntimeException::class, 'db down');

    expect(File::get($this->root.DIRECTORY_SEPARATOR.'Work'.DIRECTORY_SEPARATOR.'a.md'))->toBe('hi')
        ->and(is_dir($this->root.DIRECTORY_SEPARATOR.'Office'))->toBeFalse()
        ->and($vault->fresh()->name)->toBe('Work')
        ->and($vault->fresh()->path)->toBe($originalPath)
        ->and($vault->name)->toBe('Work');
});

test('rename: a rename-back failure after a DB failure reports and asks the user to rename back', function () {
    Exceptions::fake();
    $vault = $this->service->create('Work');
    File::put($vault->path.DIRECTORY_SEPARATOR.'a.md', 'hi');
    $originalPath = $vault->path;
    Vault::saving(function (Vault $model): void {
        if ($model->exists) {
            throw new RuntimeException('db down');
        }
    });
    failFolderRenames([2]);
    $service = app(VaultService::class);

    try {
        $service->rename($vault, 'Office', null);
        test()->fail('Expected a VaultOperationException.');
    } catch (VaultOperationException $e) {
        expect($e->field())->toBe('name');
    }

    // The 'saving' listener above only simulates the one intended failure;
    // remove it before the assertions below make their own model saves
    // (refreshStatus persists the now-Missing status).
    Vault::flushEventListeners();

    expect(File::get($this->root.DIRECTORY_SEPARATOR.'Office'.DIRECTORY_SEPARATOR.'a.md'))->toBe('hi')
        ->and($vault->fresh()->path)->toBe($originalPath)
        ->and($this->service->refreshStatus($vault->fresh())->status)->toBe(VaultStatus::Missing);

    Exceptions::assertReported(RuntimeException::class);
});

test('rename: a folder added outside the root is renamed in place and stays outside the root', function () {
    $existing = $this->tmp.DIRECTORY_SEPARATOR.'Existing';
    File::makeDirectory($existing, 0755, true);
    File::put($existing.DIRECTORY_SEPARATOR.'n.md', 'note');
    $vault = $this->service->register($existing, 'Work');

    $renamed = $this->service->rename($vault, 'Office', null);

    expect($renamed->path)->toBe(realpath($this->tmp.DIRECTORY_SEPARATOR.'Office'))
        ->and(File::get($renamed->path.DIRECTORY_SEPARATOR.'n.md'))->toBe('note')
        ->and($renamed->relative_path)->toBeNull();
});

test('rename: a folder added inside the root updates relative_path', function () {
    $existing = $this->root.DIRECTORY_SEPARATOR.'Old';
    File::makeDirectory($existing, 0755, true);
    $vault = $this->service->register($existing, 'Legacy');

    $renamed = $this->service->rename($vault, 'New', null);

    expect($renamed->path)->toBe(realpath($this->root.DIRECTORY_SEPARATOR.'New'))
        ->and($renamed->relative_path)->toBe('New');
});

test('rename: the basename already equals the new name leaves the folder untouched', function () {
    $preNamed = $this->tmp.DIRECTORY_SEPARATOR.'Office';
    File::makeDirectory($preNamed, 0755, true);
    $vault = $this->service->register($preNamed, 'Work');
    $originalPath = $vault->path;

    $renamed = $this->service->rename($vault, 'Office', null);

    expect($renamed->name)->toBe('Office')
        ->and($renamed->path)->toBe($originalPath)
        ->and(is_dir($preNamed))->toBeTrue();
});

test('rename: the current vault stays current and its path is re-read', function () {
    $vault = $this->service->create('Work');
    $this->service->open($vault);

    $this->service->rename($vault, 'Office', null);

    expect(app(SettingsService::class)->string(SettingKey::CurrentVault))->toBe($vault->uuid)
        ->and($this->service->current()->path)->toBe(realpath($this->root.DIRECTORY_SEPARATOR.'Office'));
});

test('rename: invalid names are rejected and the folder is unchanged', function (string $name) {
    $vault = $this->service->create('Work');

    try {
        $this->service->rename($vault, $name, null);
        test()->fail('Expected a VaultOperationException.');
    } catch (VaultOperationException $e) {
        expect($e->field())->toBe('name');
    }

    expect(is_dir($this->root.DIRECTORY_SEPARATOR.'Work'))->toBeTrue();
})->with([
    'reserved' => ['CON'],
    'slash' => ['a/b'],
    'trailing dot' => ['Work.'],
]);

// --- Remove -------------------------------------------------------------

test('remove: unregister deletes the record and keeps the folder', function () {
    $vault = $this->service->create('Work');
    File::put($vault->path.DIRECTORY_SEPARATOR.'a.md', 'hi');

    $this->service->remove($vault);

    expect(Vault::count())->toBe(0)
        ->and(is_dir($vault->path))->toBeTrue()
        ->and(File::get($vault->path.DIRECTORY_SEPARATOR.'a.md'))->toBe('hi');
});

test('remove: removing the current vault clears the setting', function () {
    $vault = $this->service->create('Work');
    $this->service->open($vault);

    $this->service->remove($vault);

    expect(app(SettingsService::class)->string(SettingKey::CurrentVault))->toBeNull();
});

test('remove: trash success deletes the folder and the record', function () {
    $fake = fakeTrash();
    $vault = $this->service->create('Work');
    $path = $vault->path;

    // Re-resolve: $this->service's FileStorageService already captured the
    // real Trash binding when it was built in beforeEach, before fakeTrash()
    // rebound the container here.
    app(VaultService::class)->remove($vault, true);

    expect(is_dir($path))->toBeFalse()
        ->and(Vault::count())->toBe(0)
        ->and($fake->trashed)->toBe([$path]);
});

test('remove: a silent trash failure keeps the record and the folder', function () {
    fakeTrash(deletes: false);
    $vault = $this->service->create('Work');

    try {
        app(VaultService::class)->remove($vault, true);
        test()->fail('Expected a VaultOperationException.');
    } catch (VaultOperationException $e) {
        expect($e->field())->toBe('move_to_trash');
    }

    expect(Vault::count())->toBe(1)
        ->and(is_dir($vault->path))->toBeTrue();
});

test('remove: trash unavailable refuses and changes nothing', function () {
    $fake = fakeTrash(available: false);
    $vault = $this->service->create('Work');

    expect(fn () => app(VaultService::class)->remove($vault, true))->toThrow(VaultOperationException::class);

    expect(Vault::count())->toBe(1)
        ->and(is_dir($vault->path))->toBeTrue()
        ->and($fake->trashed)->toBe([]);
});

test('remove: trash on a missing vault is refused and the record is kept', function () {
    fakeTrash();
    $vault = $this->service->create('Work');
    File::deleteDirectory($vault->path);

    expect(fn () => app(VaultService::class)->remove($vault, true))->toThrow(VaultOperationException::class);

    expect(Vault::count())->toBe(1);
});

test('remove: trashing an unsafe folder is refused', function () {
    $fake = fakeTrash();

    File::ensureDirectoryExists($this->root);
    $rootVault = Vault::factory()->create(['path' => $this->root]);

    expect(fn () => app(VaultService::class)->remove($rootVault, true))->toThrow(VaultOperationException::class);

    $documents = $this->tmp.DIRECTORY_SEPARATOR.'Documents';
    File::ensureDirectoryExists($documents);
    $docsVault = Vault::factory()->create(['path' => $documents]);

    expect(fn () => app(VaultService::class)->remove($docsVault, true))->toThrow(VaultOperationException::class);

    expect($fake->trashed)->toBe([]);
});

// --- Open, close, current ---------------------------------------------------

test('open, close, current: opening sets the setting and persists across a restart', function () {
    $vault = $this->service->create('Work');

    $this->service->open($vault);

    app()->forgetScopedInstances();

    expect(app(VaultService::class)->current()->uuid)->toBe($vault->uuid);
});

test('close clears the current vault', function () {
    $vault = $this->service->create('Work');
    $this->service->open($vault);

    $this->service->close();

    expect($this->service->current())->toBeNull();
});

test('open: a missing folder is refused and the setting is unchanged', function () {
    $vault = $this->service->create('Work');
    $other = $this->service->create('Office');
    $this->service->open($other);

    File::deleteDirectory($vault->path);

    expect(fn () => $this->service->open($vault))->toThrow(VaultOperationException::class);

    expect(app(SettingsService::class)->string(SettingKey::CurrentVault))->toBe($other->uuid)
        ->and($vault->fresh()->status)->toBe(VaultStatus::Missing);
});

test('current: a stale uuid is forgotten', function () {
    $vault = $this->service->create('Work');
    $this->service->open($vault);

    Vault::query()->delete();

    expect($this->service->current())->toBeNull()
        ->and(app(SettingsService::class)->has(SettingKey::CurrentVault))->toBeFalse();
});

// --- Status -------------------------------------------------------------

test('status: a deleted folder is reported missing and recovers when recreated', function () {
    $vault = $this->service->create('Work');

    File::deleteDirectory($vault->path);
    $all = $this->service->all();

    expect($all[0]->status)->toBe(VaultStatus::Missing)
        ->and($vault->fresh()->status)->toBe(VaultStatus::Missing);

    File::makeDirectory($vault->path, 0755, true);
    $all = $this->service->all();

    expect($all[0]->status)->toBe(VaultStatus::Active);
});

test('summaries: a missing table fails soft to an empty list', function () {
    Schema::drop('vaults');

    expect($this->service->summaries())->toBe([]);
});

test('present: has no id and the exact keys', function () {
    $vault = $this->service->create('Work');

    $presented = $this->service->present($vault);

    expect($presented)->not->toHaveKey('id')
        ->and(array_keys($presented))->toEqualCanonicalizing([
            'uuid', 'name', 'description', 'path', 'relative_path', 'status', 'is_current', 'is_encrypted',
        ]);
});

// --- Register (C3) ----------------------------------------------------------

test('register: a folder outside the root gets a null relative_path', function () {
    $existing = $this->tmp.DIRECTORY_SEPARATOR.'Existing';
    File::makeDirectory($existing, 0755, true);
    File::put($existing.DIRECTORY_SEPARATOR.'n.md', 'hi');

    $vault = $this->service->register($existing, 'Existing');

    expect($vault->relative_path)->toBeNull()
        ->and(File::get($existing.DIRECTORY_SEPARATOR.'n.md'))->toBe('hi')
        ->and(Vault::count())->toBe(1);
});

test('register: a folder inside the root gets a relative path', function () {
    $old = $this->root.DIRECTORY_SEPARATOR.'Old';
    File::makeDirectory($old, 0755, true);

    $vault = $this->service->register($old, 'Old');

    expect($vault->relative_path)->toBe('Old');
});

test('register: a relative path is rejected', function () {
    expect(fn () => $this->service->register('x/y', 'Name'))->toThrow(VaultOperationException::class);
    expect(Vault::count())->toBe(0);
});

test('register: a missing directory is rejected', function () {
    expect(fn () => $this->service->register($this->tmp.DIRECTORY_SEPARATOR.'nope', 'Name'))
        ->toThrow(VaultOperationException::class);
});

test('register: a file is rejected', function () {
    File::makeDirectory($this->tmp, 0755, true);
    $file = $this->tmp.DIRECTORY_SEPARATOR.'file.txt';
    File::put($file, 'x');

    expect(fn () => $this->service->register($file, 'Name'))->toThrow(VaultOperationException::class);
});

test('register: the storage root itself is rejected', function () {
    File::makeDirectory($this->root, 0755, true);

    expect(fn () => $this->service->register($this->root, 'Name'))->toThrow(VaultOperationException::class);
});

test('register: the parent of the storage root is rejected', function () {
    $documents = $this->tmp.DIRECTORY_SEPARATOR.'Documents';
    File::makeDirectory($documents, 0755, true);

    expect(fn () => $this->service->register($documents, 'Name'))->toThrow(VaultOperationException::class);
});

test('register: an already-registered path is rejected', function () {
    $existing = $this->tmp.DIRECTORY_SEPARATOR.'Existing';
    File::makeDirectory($existing, 0755, true);
    $this->service->register($existing, 'Existing');

    expect(fn () => $this->service->register($existing, 'Existing2'))->toThrow(VaultOperationException::class);
});

test('register: an already-registered path is rejected regardless of letter case', function () {
    $existing = $this->tmp.DIRECTORY_SEPARATOR.'Existing';
    File::makeDirectory($existing, 0755, true);
    $this->service->register($existing, 'Existing');

    expect(fn () => $this->service->register(strtoupper($existing), 'Existing2'))
        ->toThrow(VaultOperationException::class);
})->onlyOnWindows();

test('register: a path inside a registered vault is rejected', function () {
    $existing = $this->tmp.DIRECTORY_SEPARATOR.'Existing';
    $inside = $existing.DIRECTORY_SEPARATOR.'Inside';
    File::makeDirectory($inside, 0755, true);
    $this->service->register($existing, 'Existing');

    expect(fn () => $this->service->register($inside, 'Inside'))->toThrow(VaultOperationException::class);
});

test('register: an invalid name is rejected', function () {
    $existing = $this->tmp.DIRECTORY_SEPARATOR.'Existing';
    File::makeDirectory($existing, 0755, true);

    expect(fn () => $this->service->register($existing, 'CON'))->toThrow(VaultOperationException::class);

    expect(Vault::count())->toBe(0);
});

test('register: a duplicate name is rejected', function () {
    $this->service->create('Work');

    $existing = $this->tmp.DIRECTORY_SEPARATOR.'Existing';
    File::makeDirectory($existing, 0755, true);

    expect(fn () => $this->service->register($existing, 'Work'))->toThrow(VaultOperationException::class);
});

// --- nameAvailable / overlappingVault (T2) ----------------------------------

test('nameAvailable is case-insensitive', function () {
    $this->service->create('Work');

    expect($this->service->nameAvailable('Work'))->toBeFalse()
        ->and($this->service->nameAvailable('WORK'))->toBeFalse()
        ->and($this->service->nameAvailable('Personal'))->toBeTrue();
});

test('overlappingVault detects the same path, a path inside, a path containing, and returns null for unrelated', function () {
    $vault = $this->service->create('Work');

    $inside = $vault->path.DIRECTORY_SEPARATOR.'Sub';
    $containing = dirname($vault->path);
    $unrelated = $this->root.DIRECTORY_SEPARATOR.'Elsewhere';

    expect($this->service->overlappingVault($vault->path)?->uuid)->toBe($vault->uuid)
        ->and($this->service->overlappingVault($inside)?->uuid)->toBe($vault->uuid)
        ->and($this->service->overlappingVault($containing)?->uuid)->toBe($vault->uuid)
        ->and($this->service->overlappingVault($unrelated))->toBeNull();
});

// --- Root-change isolation (FR-16) -------------------------------------------

test('root-change isolation: an existing vault keeps working after the root changes', function () {
    $vaultA = $this->service->create('A');

    $r2 = $this->tmp.DIRECTORY_SEPARATOR.'R2';
    app(StoragePathService::class)->changeRoot($r2);

    expect($vaultA->fresh()->path)->toBe($vaultA->path);

    $opened = $this->service->open($vaultA);
    expect($opened->status)->toBe(VaultStatus::Active);

    $vaultB = $this->service->create('B');
    expect($vaultB->path)->toBe(realpath($r2.DIRECTORY_SEPARATOR.'MDVault'.DIRECTORY_SEPARATOR.'B'));
});
