<?php

use App\Services\FileStorageService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mdvault-fs-tests-'.Str::random(8);
    File::makeDirectory($this->tmp, 0755, true);
});

afterEach(function () {
    File::deleteDirectory($this->tmp);
});

test('isEmptyDirectory is true for an empty directory', function () {
    expect(app(FileStorageService::class)->isEmptyDirectory($this->tmp))->toBeTrue();
});

test('isEmptyDirectory is false for a directory containing only a hidden file', function () {
    File::put($this->tmp.DIRECTORY_SEPARATOR.'.x', '');

    expect(app(FileStorageService::class)->isEmptyDirectory($this->tmp))->toBeFalse();
});

test('isEmptyDirectory is false for a missing path', function () {
    expect(app(FileStorageService::class)->isEmptyDirectory($this->tmp.DIRECTORY_SEPARATOR.'nope'))->toBeFalse();
});

test('isEmptyDirectory is false for a file', function () {
    $file = $this->tmp.DIRECTORY_SEPARATOR.'a.txt';
    File::put($file, 'x');

    expect(app(FileStorageService::class)->isEmptyDirectory($file))->toBeFalse();
});

test('makeDirectory creates a directory', function () {
    $target = $this->tmp.DIRECTORY_SEPARATOR.'child';

    expect(app(FileStorageService::class)->makeDirectory($target))->toBeTrue()
        ->and(is_dir($target))->toBeTrue();
});

test('makeDirectory fails when the parent is missing', function () {
    $target = $this->tmp.DIRECTORY_SEPARATOR.'missing-parent'.DIRECTORY_SEPARATOR.'child';

    expect(app(FileStorageService::class)->makeDirectory($target))->toBeFalse();
});

test('isWritableDirectory returns true and leaves no probe file', function () {
    $service = app(FileStorageService::class);

    expect($service->isWritableDirectory($this->tmp))->toBeTrue()
        ->and(glob($this->tmp.DIRECTORY_SEPARATOR.'.mdvault-write-test-*'))->toBe([]);
});

test('deleteEmptyDirectory removes an empty directory', function () {
    $target = $this->tmp.DIRECTORY_SEPARATOR.'empty';
    File::makeDirectory($target);

    expect(app(FileStorageService::class)->deleteEmptyDirectory($target))->toBeTrue()
        ->and(is_dir($target))->toBeFalse();
});

test('deleteEmptyDirectory refuses a non-empty directory', function () {
    $target = $this->tmp.DIRECTORY_SEPARATOR.'nonempty';
    File::makeDirectory($target);
    File::put($target.DIRECTORY_SEPARATOR.'a.md', 'hello');

    expect(app(FileStorageService::class)->deleteEmptyDirectory($target))->toBeFalse()
        ->and(is_dir($target))->toBeTrue()
        ->and(File::get($target.DIRECTORY_SEPARATOR.'a.md'))->toBe('hello');
});

test('samePath ignores a trailing separator', function () {
    $a = $this->tmp;
    $b = $this->tmp.DIRECTORY_SEPARATOR;

    expect(app(FileStorageService::class)->samePath($a, $b))->toBeTrue();
});

test('samePath ignores letter case on Windows', function () {
    expect(app(FileStorageService::class)->samePath(strtoupper($this->tmp), strtolower($this->tmp)))->toBeTrue();
})->onlyOnWindows();

test('isSameOrInside detects a child, a same path and a non-matching sibling prefix', function () {
    $service = app(FileStorageService::class);

    $work = $this->tmp.DIRECTORY_SEPARATOR.'Work';
    $workshop = $this->tmp.DIRECTORY_SEPARATOR.'Workshop';
    $child = $work.DIRECTORY_SEPARATOR.'Sub';

    expect($service->isSameOrInside($child, $work))->toBeTrue()
        ->and($service->isSameOrInside($work, $work))->toBeTrue()
        ->and($service->isSameOrInside($workshop, $work))->toBeFalse();
});

test('relativeTo returns the forward-slash remainder', function () {
    $root = $this->tmp;
    $path = $root.DIRECTORY_SEPARATOR.'Team'.DIRECTORY_SEPARATOR.'Work';

    expect(app(FileStorageService::class)->relativeTo($path, $root))->toBe('Team/Work');
});

test('isFilesystemRoot dataset', function (string $path, bool $expected) {
    expect(app(FileStorageService::class)->isFilesystemRoot($path))->toBe($expected);
})->with([
    ['/', true],
    ['C:\\', true],
    ['C:', true],
    ['\\\\srv\\share', true],
    ['C:\\x', false],
]);

test('moveToTrash returns true and the directory is gone when the fake trash succeeds', function () {
    fakeTrash();
    $target = $this->tmp.DIRECTORY_SEPARATOR.'to-trash';
    File::makeDirectory($target);

    expect(app(FileStorageService::class)->moveToTrash($target))->toBeTrue()
        ->and(is_dir($target))->toBeFalse();
});

test('moveToTrash returns false and keeps the directory on a silent failure', function () {
    fakeTrash(deletes: false);
    $target = $this->tmp.DIRECTORY_SEPARATOR.'stays';
    File::makeDirectory($target);

    expect(app(FileStorageService::class)->moveToTrash($target))->toBeFalse()
        ->and(is_dir($target))->toBeTrue();
});

test('moveToTrash returns false and calls nothing when unavailable', function () {
    $fake = fakeTrash(available: false);
    $target = $this->tmp.DIRECTORY_SEPARATOR.'untouched';
    File::makeDirectory($target);

    expect(app(FileStorageService::class)->moveToTrash($target))->toBeFalse()
        ->and($fake->trashed)->toBe([])
        ->and(is_dir($target))->toBeTrue();
});

test('renameDirectory moves a directory with its content and the source is gone', function () {
    $from = $this->tmp.DIRECTORY_SEPARATOR.'Work';
    $to = $this->tmp.DIRECTORY_SEPARATOR.'Office';
    File::makeDirectory($from);
    File::put($from.DIRECTORY_SEPARATOR.'a.md', 'hi');

    expect(app(FileStorageService::class)->renameDirectory($from, $to))->toBeTrue()
        ->and(File::get($to.DIRECTORY_SEPARATOR.'a.md'))->toBe('hi')
        ->and(is_dir($from))->toBeFalse();
});

test('renameDirectory refuses an existing target and leaves both sides intact', function (Closure $makeTarget) {
    $from = $this->tmp.DIRECTORY_SEPARATOR.'Work';
    $to = $this->tmp.DIRECTORY_SEPARATOR.'Office';
    File::makeDirectory($from);
    File::put($from.DIRECTORY_SEPARATOR.'a.md', 'hi');
    $makeTarget($to);

    expect(app(FileStorageService::class)->renameDirectory($from, $to))->toBeFalse()
        ->and(File::get($from.DIRECTORY_SEPARATOR.'a.md'))->toBe('hi')
        ->and(file_exists($to))->toBeTrue();
})->with([
    'non-empty directory' => [function (string $to): void {
        File::makeDirectory($to);
        File::put($to.DIRECTORY_SEPARATOR.'x.md', 'x');
    }],
    'empty directory' => [function (string $to): void {
        File::makeDirectory($to);
    }],
    'a file' => [function (string $to): void {
        File::put($to, 'x');
    }],
]);

test('renameDirectory: a case-only rename succeeds through a hidden temp sibling and leaves no trace', function () {
    $from = $this->tmp.DIRECTORY_SEPARATOR.'Work';
    $to = $this->tmp.DIRECTORY_SEPARATOR.'work';
    File::makeDirectory($from);
    File::put($from.DIRECTORY_SEPARATOR.'a.md', 'hi');

    expect(app(FileStorageService::class)->renameDirectory($from, $to))->toBeTrue()
        ->and(scandir($this->tmp))->toContain('work')
        ->and(scandir($this->tmp))->not->toContain('Work')
        ->and(glob($this->tmp.DIRECTORY_SEPARATOR.'.mdvault-rename-*'))->toBe([]);
});

test('renameDirectory returns false and leaves the source intact when the move fails', function () {
    $from = $this->tmp.DIRECTORY_SEPARATOR.'Work';
    $to = $this->tmp.DIRECTORY_SEPARATOR.'Office';
    File::makeDirectory($from);
    File::put($from.DIRECTORY_SEPARATOR.'a.md', 'hi');
    failFolderRenames([1]);

    expect(app(FileStorageService::class)->renameDirectory($from, $to))->toBeFalse()
        ->and(is_dir($from))->toBeTrue()
        ->and(File::get($from.DIRECTORY_SEPARATOR.'a.md'))->toBe('hi')
        ->and(is_dir($to))->toBeFalse();
});

test('renameDirectory: a case-only rename whose second step fails is restored, with no temp folder left', function () {
    $from = $this->tmp.DIRECTORY_SEPARATOR.'Work';
    $to = $this->tmp.DIRECTORY_SEPARATOR.'work';
    File::makeDirectory($from);
    failFolderRenames([2]);

    expect(app(FileStorageService::class)->renameDirectory($from, $to))->toBeFalse()
        ->and(scandir($this->tmp))->toContain('Work')
        ->and(is_dir($from))->toBeTrue()
        ->and(glob($this->tmp.DIRECTORY_SEPARATOR.'.mdvault-rename-*'))->toBe([]);
});

test('isSameDirectory ignores a trailing separator', function () {
    $target = $this->tmp.DIRECTORY_SEPARATOR.'Work';
    File::makeDirectory($target);

    expect(app(FileStorageService::class)->isSameDirectory($target, $target.DIRECTORY_SEPARATOR))->toBeTrue();
});

test('isSameDirectory is false for two different directories', function () {
    $a = $this->tmp.DIRECTORY_SEPARATOR.'Work';
    $b = $this->tmp.DIRECTORY_SEPARATOR.'Office';
    File::makeDirectory($a);
    File::makeDirectory($b);

    expect(app(FileStorageService::class)->isSameDirectory($a, $b))->toBeFalse();
});

test('isSameDirectory is true for an upper-case variant', function () {
    $target = $this->tmp.DIRECTORY_SEPARATOR.'Work';
    File::makeDirectory($target);

    expect(app(FileStorageService::class)->isSameDirectory($target, strtoupper($target)))->toBeTrue();
})->onlyOnWindows();

test('siblingPath gives the parent directory plus the new name', function () {
    $path = $this->tmp.DIRECTORY_SEPARATOR.'Work';

    expect(app(FileStorageService::class)->siblingPath($path, 'Office'))->toBe($this->tmp.DIRECTORY_SEPARATOR.'Office');
});
