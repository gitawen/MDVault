<?php

use App\Exceptions\InvalidStorageRootException;
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

test('changeRoot creates a missing nested path and persists the canonical path', function () {
    $service = app(StoragePathService::class);
    $target = $this->tmp.DIRECTORY_SEPARATOR.'nested'.DIRECTORY_SEPARATOR.'child';

    $canonical = $service->changeRoot($target);

    expect(is_dir($target))->toBeTrue()
        ->and($canonical)->toBe(realpath($target))
        ->and($service->rootPath())->toBe($canonical)
        ->and(glob($target.DIRECTORY_SEPARATOR.'.mdvault-write-test-*'))->toBe([]);
});

test('changeRoot on an existing empty directory is persisted', function () {
    $target = $this->tmp.DIRECTORY_SEPARATOR.'existing';
    File::makeDirectory($target, 0755, true);

    $service = app(StoragePathService::class);
    $canonical = $service->changeRoot($target);

    expect($canonical)->toBe(realpath($target))
        ->and($service->rootPath())->toBe($canonical);
});

test('a trailing separator is trimmed before persisting', function () {
    $target = $this->tmp.DIRECTORY_SEPARATOR.'trailing';

    $service = app(StoragePathService::class);
    $canonical = $service->changeRoot($target.DIRECTORY_SEPARATOR);

    expect($canonical)->toBe(realpath($target));
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

test('an existing file is rejected', function () {
    $file = $this->tmp.DIRECTORY_SEPARATOR.'blocker.txt';
    File::makeDirectory($this->tmp, 0755, true);
    File::put($file, 'content');

    $service = app(StoragePathService::class);

    expect(fn () => $service->changeRoot($file))
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

test('an unwritable directory is rejected', function () {
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        $this->markTestSkipped('Cannot test unwritable directories as root.');
    }

    $target = $this->tmp.DIRECTORY_SEPARATOR.'readonly';
    File::makeDirectory($target, 0755, true);
    chmod($target, 0555);

    $service = app(StoragePathService::class);

    try {
        expect(fn () => $service->changeRoot($target))
            ->toThrow(InvalidStorageRootException::class, 'MDVault cannot write to that folder.');
    } finally {
        chmod($target, 0755);
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

    $service->changeRoot($rootA);
    File::put($rootA.DIRECTORY_SEPARATOR.'old.md', 'hello');

    $service->changeRoot($rootB);

    expect(File::get($rootA.DIRECTORY_SEPARATOR.'old.md'))->toBe('hello')
        ->and(File::allFiles($rootB))->toBe([]);
});

test('summary reports the shape and flags for a missing and an existing root', function () {
    $service = app(StoragePathService::class);

    $missing = $service->summary();

    expect($missing)->toHaveKeys(['root_path', 'default_path', 'is_default', 'exists', 'writable'])
        ->and($missing['exists'])->toBeFalse()
        ->and($missing['writable'])->toBeFalse()
        ->and($missing['is_default'])->toBeTrue();

    $existing = $this->tmp.DIRECTORY_SEPARATOR.'present';
    $service->changeRoot($existing);

    $summary = $service->summary();

    expect($summary['exists'])->toBeTrue()
        ->and($summary['writable'])->toBeTrue()
        ->and($summary['is_default'])->toBeFalse();
});
