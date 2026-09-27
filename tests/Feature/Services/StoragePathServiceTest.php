<?php

use App\Enums\SettingKey;
use App\Exceptions\InvalidStorageRootException;
use App\Services\SettingsService;
use App\Services\StoragePathService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mdvault-tests-'.Str::random(8);
    fakeDocumentsDirectory($this->tmp.DIRECTORY_SEPARATOR.'Documents');
});

afterEach(function () {
    File::deleteDirectory($this->tmp);
});

test('the default root is the fake Documents folder plus MDVault, and is not created', function () {
    $service = app(StoragePathService::class);

    $expected = $this->tmp.DIRECTORY_SEPARATOR.'Documents'.DIRECTORY_SEPARATOR.'MDVault';

    expect($service->defaultRootPath())->toBe($expected)
        ->and(is_dir($expected))->toBeFalse()
        ->and($service->isUsingDefault())->toBeTrue();
});

test('the default root falls back to storage_path(app) when there is no documents directory', function () {
    fakeDocumentsDirectory(null);

    $service = app(StoragePathService::class);

    expect($service->defaultRootPath())->toBe(storage_path('app').DIRECTORY_SEPARATOR.'MDVault');
});

test('changeRoot creates a missing nested path and persists the canonical MDVault subfolder', function () {
    $service = app(StoragePathService::class);
    $target = $this->tmp.DIRECTORY_SEPARATOR.'nested'.DIRECTORY_SEPARATOR.'child';
    $expected = $target.DIRECTORY_SEPARATOR.'MDVault';

    $canonical = $service->changeRoot($target);

    expect(is_dir($expected))->toBeTrue()
        ->and($canonical)->toBe(realpath($expected))
        ->and($service->rootPath())->toBe($canonical)
        ->and(glob($expected.DIRECTORY_SEPARATOR.'.mdvault-write-test-*'))->toBe([]);
});

test('changeRoot on an existing empty directory creates and persists its MDVault subfolder', function () {
    $target = $this->tmp.DIRECTORY_SEPARATOR.'existing';
    File::makeDirectory($target, 0755, true);
    $expected = $target.DIRECTORY_SEPARATOR.'MDVault';

    $service = app(StoragePathService::class);
    $canonical = $service->changeRoot($target);

    expect($canonical)->toBe(realpath($expected))
        ->and($service->rootPath())->toBe($canonical);
});

test('a trailing separator is trimmed before appending the MDVault subfolder', function () {
    $target = $this->tmp.DIRECTORY_SEPARATOR.'trailing';
    $expected = $target.DIRECTORY_SEPARATOR.'MDVault';

    $service = app(StoragePathService::class);
    $canonical = $service->changeRoot($target.DIRECTORY_SEPARATOR);

    expect($canonical)->toBe(realpath($expected));
});

test('changeRoot appends an MDVault subfolder to a chosen directory', function () {
    $service = app(StoragePathService::class);
    $chosen = $this->tmp.DIRECTORY_SEPARATOR.'chosen';

    $canonical = $service->changeRoot($chosen);

    expect(is_dir($chosen.DIRECTORY_SEPARATOR.'MDVault'))->toBeTrue()
        ->and($canonical)->toBe(realpath($chosen.DIRECTORY_SEPARATOR.'MDVault'))
        ->and($service->rootPath())->toBe($canonical);
});

test('changeRoot does not double the MDVault suffix when the chosen folder is already named MDVault', function (string $folderName) {
    $service = app(StoragePathService::class);
    $chosen = $this->tmp.DIRECTORY_SEPARATOR.'Stuff'.DIRECTORY_SEPARATOR.$folderName;

    $canonical = $service->changeRoot($chosen);

    expect($canonical)->toBe(realpath($chosen))
        ->and(is_dir($chosen.DIRECTORY_SEPARATOR.'MDVault'))->toBeFalse();
})->with(['MDVault', 'mdvault', 'MdVault']);

test('changeRoot reuses an existing MDVault subfolder instead of recreating it', function () {
    $chosen = $this->tmp.DIRECTORY_SEPARATOR.'chosen';
    $existingSubfolder = $chosen.DIRECTORY_SEPARATOR.'MDVault';
    File::makeDirectory($existingSubfolder, 0755, true);
    File::put($existingSubfolder.DIRECTORY_SEPARATOR.'keep.md', 'hello');

    $service = app(StoragePathService::class);
    $canonical = $service->changeRoot($chosen);

    expect($canonical)->toBe(realpath($existingSubfolder))
        ->and(File::get($existingSubfolder.DIRECTORY_SEPARATOR.'keep.md'))->toBe('hello');
});

test('relative paths are rejected', function (string $path) {
    $service = app(StoragePathService::class);

    expect(fn () => $service->changeRoot($path))
        ->toThrow(InvalidStorageRootException::class, 'Enter a full (absolute) folder path.');
})->with(['relative/dir', './x', '..']);

test('a drive-relative windows path is rejected', function () {
    $service = app(StoragePathService::class);

    expect(fn () => $service->changeRoot('\\no-drive'))
        ->toThrow(InvalidStorageRootException::class, 'Enter a full (absolute) folder path.');
})->onlyOnWindows();

test('changeRoot throws when the MDVault subfolder already exists as a file', function () {
    $chosen = $this->tmp.DIRECTORY_SEPARATOR.'chosen';
    File::makeDirectory($chosen, 0755, true);
    File::put($chosen.DIRECTORY_SEPARATOR.'MDVault', 'content');

    $service = app(StoragePathService::class);

    expect(fn () => $service->changeRoot($chosen))
        ->toThrow(InvalidStorageRootException::class, 'That path points to a file, not a folder.');
});

test('a path under an existing file cannot be created', function () {
    $blocker = $this->tmp.DIRECTORY_SEPARATOR.'blocker';
    File::makeDirectory($this->tmp, 0755, true);
    File::put($blocker, 'content');

    $service = app(StoragePathService::class);

    expect(fn () => $service->changeRoot($blocker.DIRECTORY_SEPARATOR.'child'))
        ->toThrow(InvalidStorageRootException::class, 'The folder could not be created. Check the path and your permissions.');
});

test('an existing but unwritable MDVault subfolder is rejected', function () {
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        $this->markTestSkipped('Cannot test unwritable directories as root.');
    }

    $chosen = $this->tmp.DIRECTORY_SEPARATOR.'chosen';
    $subfolder = $chosen.DIRECTORY_SEPARATOR.'MDVault';
    File::makeDirectory($subfolder, 0755, true);
    chmod($subfolder, 0555);

    $service = app(StoragePathService::class);

    try {
        expect(fn () => $service->changeRoot($chosen))
            ->toThrow(InvalidStorageRootException::class, 'MDVault cannot write to that folder.');
    } finally {
        chmod($subfolder, 0755);
    }
})->skipOnWindows();

test('an empty or whitespace path is invalid', function (string $path) {
    $service = app(StoragePathService::class);

    expect(fn () => $service->changeRoot($path))
        ->toThrow(InvalidStorageRootException::class, 'The folder path is invalid.');
})->with(['', '   ']);

test('choosing the default path forgets the stored setting', function () {
    $service = app(StoragePathService::class);
    $default = $service->defaultRootPath();

    $service->changeRoot($default);

    expect($service->isUsingDefault())->toBeTrue();
});

test('resetToDefault forgets the setting without touching the custom directory', function () {
    $service = app(StoragePathService::class);
    $custom = $this->tmp.DIRECTORY_SEPARATOR.'custom';

    $service->changeRoot($custom);
    $service->resetToDefault();

    expect($service->isUsingDefault())->toBeTrue()
        ->and(is_dir($custom))->toBeTrue();
});

test('changing the root never moves existing data', function () {
    $service = app(StoragePathService::class);

    $rootA = $this->tmp.DIRECTORY_SEPARATOR.'rootA';
    $rootB = $this->tmp.DIRECTORY_SEPARATOR.'rootB';

    $effectiveA = $service->changeRoot($rootA);
    File::put($effectiveA.DIRECTORY_SEPARATOR.'old.md', 'hello');

    $effectiveB = $service->changeRoot($rootB);

    expect(File::get($effectiveA.DIRECTORY_SEPARATOR.'old.md'))->toBe('hello')
        ->and(File::allFiles($effectiveB))->toBe([]);
});

test('summary reports the shape and flags for a missing and an existing root', function () {
    $service = app(StoragePathService::class);

    $missing = $service->summary();

    expect($missing)->toHaveKeys(['root_path', 'default_path', 'is_default', 'exists', 'writable', 'location', 'folder_name'])
        ->and($missing['exists'])->toBeFalse()
        ->and($missing['writable'])->toBeFalse()
        ->and($missing['is_default'])->toBeTrue()
        ->and($missing['folder_name'])->toBe('MDVault');

    $existing = $this->tmp.DIRECTORY_SEPARATOR.'present';
    $service->changeRoot($existing);

    $summary = $service->summary();

    expect($summary['exists'])->toBeTrue()
        ->and($summary['writable'])->toBeTrue()
        ->and($summary['is_default'])->toBeFalse()
        ->and($summary['folder_name'])->toBe('MDVault')
        ->and($summary['location'])->toBe(dirname($summary['root_path']));
});

test('summary reports writable true via a write probe rather than is_writable', function () {
    $service = app(StoragePathService::class);
    $target = $this->tmp.DIRECTORY_SEPARATOR.'writable-check';

    $service->changeRoot($target);
    $summary = $service->summary();

    expect($summary['exists'])->toBeTrue()
        ->and($summary['writable'])->toBeTrue();
});

test('valid folder names pass validation', function (string $name) {
    $service = app(StoragePathService::class);

    expect(fn () => $service->assertValidFolderName($name))->not->toThrow(InvalidStorageRootException::class);
})->with([
    'simple' => ['MyVault'],
    'internal spaces' => ['My Notes Vault'],
    'unicode accents' => ['Café Notes'],
    'unicode cjk' => ['笔记本'],
]);

test('invalid folder names fail validation', function (string $name) {
    $service = app(StoragePathService::class);

    expect(fn () => $service->assertValidFolderName($name))
        ->toThrow(InvalidStorageRootException::class);
})->with([
    'empty' => [''],
    'dot dot' => ['..'],
    'forward slash' => ['a/b'],
    'back slash' => ['a\b'],
    'reserved con' => ['con'],
    'reserved with extension' => ['CON.txt'],
    'trailing dot' => ['name.'],
    'trailing space' => ['name '],
    'colon' => ['a:b'],
    'too long' => [str_repeat('a', 101)],
]);

test('changeRoot appends a custom folder name to a chosen location', function () {
    $service = app(StoragePathService::class);
    $chosen = $this->tmp.DIRECTORY_SEPARATOR.'chosen';

    $canonical = $service->changeRoot($chosen, 'MyVault');

    expect(is_dir($chosen.DIRECTORY_SEPARATOR.'MyVault'))->toBeTrue()
        ->and($canonical)->toBe(realpath($chosen.DIRECTORY_SEPARATOR.'MyVault'))
        ->and($service->folderName())->toBe('MyVault');
});

test('passing the default folder name forgets the folder-name setting', function () {
    $service = app(StoragePathService::class);

    $service->changeRoot($this->tmp.DIRECTORY_SEPARATOR.'chosen', 'CustomName');
    expect(app(SettingsService::class)->has(SettingKey::StorageFolderName))->toBeTrue();

    $service->changeRoot($this->tmp.DIRECTORY_SEPARATOR.'other', 'MDVault');

    expect(app(SettingsService::class)->has(SettingKey::StorageFolderName))->toBeFalse()
        ->and($service->folderName())->toBe('MDVault');
});

test('the default root reflects a custom folder name', function () {
    $service = app(StoragePathService::class);

    $service->changeRoot($this->tmp.DIRECTORY_SEPARATOR.'chosen', 'CustomName');

    expect($service->defaultRootPath())->toBe(
        $this->tmp.DIRECTORY_SEPARATOR.'Documents'.DIRECTORY_SEPARATOR.'CustomName'
    );
});

test('changeRoot does not double a custom folder name when the chosen folder already has it', function () {
    $service = app(StoragePathService::class);
    $chosen = $this->tmp.DIRECTORY_SEPARATOR.'Stuff'.DIRECTORY_SEPARATOR.'CustomName';

    $canonical = $service->changeRoot($chosen, 'CustomName');

    expect($canonical)->toBe(realpath($chosen))
        ->and(is_dir($chosen.DIRECTORY_SEPARATOR.'CustomName'))->toBeFalse();
});

test('an invalid folder name persists nothing and creates nothing', function () {
    $service = app(StoragePathService::class);
    $chosen = $this->tmp.DIRECTORY_SEPARATOR.'chosen';

    expect(fn () => $service->changeRoot($chosen, 'con'))
        ->toThrow(InvalidStorageRootException::class);

    expect(app(SettingsService::class)->has(SettingKey::StorageFolderName))->toBeFalse()
        ->and(app(SettingsService::class)->has(SettingKey::StorageRootPath))->toBeFalse()
        ->and(is_dir($chosen))->toBeFalse();
});

test('a valid custom name is not persisted when the location is invalid', function () {
    $service = app(StoragePathService::class);

    expect(fn () => $service->changeRoot('relative/dir', 'CustomName'))
        ->toThrow(InvalidStorageRootException::class);

    expect(app(SettingsService::class)->has(SettingKey::StorageFolderName))->toBeFalse();
});

test('ensureRootReady creates a missing default root and returns its realpath without writing settings', function () {
    $service = app(StoragePathService::class);
    $expected = $this->tmp.DIRECTORY_SEPARATOR.'Documents'.DIRECTORY_SEPARATOR.'MDVault';

    $result = $service->ensureRootReady();

    expect(is_dir($expected))->toBeTrue()
        ->and($result)->toBe(realpath($expected))
        ->and($service->isUsingDefault())->toBeTrue();
});

test('ensureRootReady returns an existing root unchanged', function () {
    $service = app(StoragePathService::class);
    $custom = $this->tmp.DIRECTORY_SEPARATOR.'custom';
    $service->changeRoot($custom);

    $result = $service->ensureRootReady();

    expect($result)->toBe(realpath($custom.DIRECTORY_SEPARATOR.'MDVault'));
});

test('ensureRootReady throws when a file sits at the root path', function () {
    $service = app(StoragePathService::class);
    $blocker = $this->tmp.DIRECTORY_SEPARATOR.'blocker.txt';
    File::makeDirectory($this->tmp, 0755, true);
    File::put($blocker, 'x');
    $service->changeRoot($this->tmp.DIRECTORY_SEPARATOR.'custom');

    // Force the effective root onto the file path directly via settings.
    app(SettingsService::class)->set(SettingKey::StorageRootPath, $blocker);

    expect(fn () => $service->ensureRootReady())
        ->toThrow(InvalidStorageRootException::class);
});

test('resetToDefault keeps the custom folder name', function () {
    $service = app(StoragePathService::class);
    $custom = $this->tmp.DIRECTORY_SEPARATOR.'custom';

    $service->changeRoot($custom, 'CustomName');
    $service->resetToDefault();

    expect($service->isUsingDefault())->toBeTrue()
        ->and($service->folderName())->toBe('CustomName')
        ->and($service->rootPath())->toBe($service->defaultRootPath());
});
