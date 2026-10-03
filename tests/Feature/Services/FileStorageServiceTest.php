<?php

use App\Enums\FileReplaceResult;
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

test('createFile creates a file with its contents and refuses an existing one', function () {
    $path = $this->tmp.DIRECTORY_SEPARATOR.'a.md';
    $service = app(FileStorageService::class);

    expect($service->createFile($path, 'hello'))->toBeTrue()
        ->and(File::get($path))->toBe('hello');

    expect($service->createFile($path, 'overwritten'))->toBeFalse()
        ->and(File::get($path))->toBe('hello');
});

test('deleteNewEmptyFile removes a 0-byte file and refuses a non-empty one', function () {
    $service = app(FileStorageService::class);

    $empty = $this->tmp.DIRECTORY_SEPARATOR.'empty.md';
    File::put($empty, '');
    expect($service->deleteNewEmptyFile($empty))->toBeTrue()
        ->and(file_exists($empty))->toBeFalse();

    $full = $this->tmp.DIRECTORY_SEPARATOR.'full.md';
    File::put($full, 'content');
    expect($service->deleteNewEmptyFile($full))->toBeFalse()
        ->and(File::get($full))->toBe('content');
});

test('deleteNewFileWithContents deletes only when the bytes exactly match', function () {
    $service = app(FileStorageService::class);

    $exact = $this->tmp.DIRECTORY_SEPARATOR.'exact.md';
    File::put($exact, 'template content');
    expect($service->deleteNewFileWithContents($exact, 'template content'))->toBeTrue()
        ->and(file_exists($exact))->toBeFalse();

    $differentSize = $this->tmp.DIRECTORY_SEPARATOR.'different-size.md';
    File::put($differentSize, 'template content plus more');
    expect($service->deleteNewFileWithContents($differentSize, 'template content'))->toBeFalse()
        ->and(File::exists($differentSize))->toBeTrue();

    $differentBytes = $this->tmp.DIRECTORY_SEPARATOR.'different-bytes.md';
    File::put($differentBytes, 'template CONTENT!');
    expect($service->deleteNewFileWithContents($differentBytes, 'template content!'))->toBeFalse()
        ->and(File::exists($differentBytes))->toBeTrue();
});

test('deleteNewFileWithContents refuses a symlink even with matching bytes', function () {
    $real = $this->tmp.DIRECTORY_SEPARATOR.'real.md';
    File::put($real, 'content');
    $link = $this->tmp.DIRECTORY_SEPARATOR.'link.md';
    symlink($real, $link);

    expect(app(FileStorageService::class)->deleteNewFileWithContents($link, 'content'))->toBeFalse();
    expect(File::exists($real))->toBeTrue();
})->skipOnWindows();

test('renameFile moves a file with its content and the source is gone', function () {
    $from = $this->tmp.DIRECTORY_SEPARATOR.'a.md';
    $to = $this->tmp.DIRECTORY_SEPARATOR.'b.md';
    File::put($from, 'hi');

    expect(app(FileStorageService::class)->renameFile($from, $to))->toBeTrue()
        ->and(File::get($to))->toBe('hi')
        ->and(file_exists($from))->toBeFalse();
});

test('renameFile refuses an existing target file, leaving both intact', function () {
    $from = $this->tmp.DIRECTORY_SEPARATOR.'a.md';
    $to = $this->tmp.DIRECTORY_SEPARATOR.'b.md';
    File::put($from, 'hi');
    File::put($to, 'there');

    expect(app(FileStorageService::class)->renameFile($from, $to))->toBeFalse()
        ->and(File::get($from))->toBe('hi')
        ->and(File::get($to))->toBe('there');
});

test('renameFile: a case-only rename gives scandir containing the new case and no temp file', function () {
    $from = $this->tmp.DIRECTORY_SEPARATOR.'a.md';
    $to = $this->tmp.DIRECTORY_SEPARATOR.'A.md';
    File::put($from, 'hi');

    expect(app(FileStorageService::class)->renameFile($from, $to))->toBeTrue()
        ->and(scandir($this->tmp))->toContain('A.md')
        ->and(scandir($this->tmp))->not->toContain('a.md')
        ->and(glob($this->tmp.DIRECTORY_SEPARATOR.'.mdvault-rename-*'))->toBe([]);
});

test('renameFile returns false and the source is intact when the first move fails', function () {
    $from = $this->tmp.DIRECTORY_SEPARATOR.'a.md';
    $to = $this->tmp.DIRECTORY_SEPARATOR.'b.md';
    File::put($from, 'hi');
    failFileMoves([1]);

    expect(app(FileStorageService::class)->renameFile($from, $to))->toBeFalse()
        ->and(File::get($from))->toBe('hi')
        ->and(file_exists($to))->toBeFalse();
});

test('renameFile: a case-only rename whose second step fails is restored, with no temp file left', function () {
    $from = $this->tmp.DIRECTORY_SEPARATOR.'a.md';
    $to = $this->tmp.DIRECTORY_SEPARATOR.'A.md';
    File::put($from, 'hi');
    failFileMoves([2]);

    expect(app(FileStorageService::class)->renameFile($from, $to))->toBeFalse()
        ->and(scandir($this->tmp))->toContain('a.md')
        ->and(File::get($from))->toBe('hi')
        ->and(glob($this->tmp.DIRECTORY_SEPARATOR.'.mdvault-rename-*'))->toBe([]);
})->onlyOnWindows();

test('isSameFile is true for an upper-case variant', function () {
    $target = $this->tmp.DIRECTORY_SEPARATOR.'a.md';
    File::put($target, 'hi');

    expect(app(FileStorageService::class)->isSameFile($target, strtoupper($target)))->toBeTrue();
})->onlyOnWindows();

test('joinRelative joins with the native separator', function () {
    expect(app(FileStorageService::class)->joinRelative($this->tmp, 'Projects/HRMIS.md'))
        ->toBe($this->tmp.DIRECTORY_SEPARATOR.'Projects'.DIRECTORY_SEPARATOR.'HRMIS.md');

    expect(app(FileStorageService::class)->joinRelative($this->tmp, ''))->toBe($this->tmp);
});

test('scan walks nested files, lists an empty directory, uses forward slashes and sorts', function () {
    writeVaultFiles($this->tmp, [
        'Readme.md' => 'root',
        'Projects/HRMIS.md' => 'a',
        'Projects/Sub/Deep.md' => 'b',
        'Archive/' => '',
    ]);

    $seen = [];
    $result = app(FileStorageService::class)->scan($this->tmp, function (string $relative, string $name, bool $isDir) use (&$seen): bool {
        $seen[] = $relative;

        return true;
    });

    expect($result['files'])->toHaveCount(3)
        ->and(array_column($result['files'], 'path'))->toBe(['Projects/HRMIS.md', 'Projects/Sub/Deep.md', 'Readme.md'])
        ->and($result['directories'])->toContain('Archive')
        ->and($result['unreadable'])->toBe([]);
});

test('scan prunes a rejected directory and never visits its children', function () {
    writeVaultFiles($this->tmp, [
        'node_modules/e.md' => 'x',
        'Projects/HRMIS.md' => 'a',
    ]);

    $seen = [];
    app(FileStorageService::class)->scan($this->tmp, function (string $relative, string $name, bool $isDir) use (&$seen): bool {
        $seen[] = $relative;

        return $name !== 'node_modules';
    });

    expect($seen)->not->toContain('node_modules/e.md');
});

test('scan skips a symlinked directory and a symlinked file', function () {
    $real = $this->tmp.DIRECTORY_SEPARATOR.'RealDir';
    File::makeDirectory($real);
    File::put($real.DIRECTORY_SEPARATOR.'inside.md', 'x');
    File::put($this->tmp.DIRECTORY_SEPARATOR.'real.md', 'y');

    symlink($real, $this->tmp.DIRECTORY_SEPARATOR.'LinkedDir');
    symlink($this->tmp.DIRECTORY_SEPARATOR.'real.md', $this->tmp.DIRECTORY_SEPARATOR.'linked.md');

    $result = app(FileStorageService::class)->scan($this->tmp, fn (): bool => true);

    expect($result['directories'])->not->toContain('LinkedDir')
        ->and(array_column($result['files'], 'path'))->not->toContain('linked.md');
})->skipOnWindows();

test('scan of a missing root gives unreadable === [""]', function () {
    $result = app(FileStorageService::class)->scan($this->tmp.DIRECTORY_SEPARATOR.'missing', fn (): bool => true);

    expect($result['unreadable'])->toBe(['']);
});

test('scan reports each file\'s mtime', function () {
    writeVaultFiles($this->tmp, ['a.md' => 'x']);
    $path = $this->tmp.DIRECTORY_SEPARATOR.'a.md';
    touch($path, 1_700_000_000);

    $result = app(FileStorageService::class)->scan($this->tmp, fn (): bool => true);

    expect($result['files'][0]['mtime'])->toBe(filemtime($path));
});

test('modifiedTime returns null for a missing path', function () {
    expect(app(FileStorageService::class)->modifiedTime($this->tmp.DIRECTORY_SEPARATOR.'missing.md'))->toBeNull();
});

test('read of a missing file gives null; size of a missing file gives null', function () {
    $service = app(FileStorageService::class);
    $missing = $this->tmp.DIRECTORY_SEPARATOR.'missing.md';

    expect($service->read($missing))->toBeNull()
        ->and($service->size($missing))->toBeNull();
});

test('replaceFile replaces the content and returns Replaced, leaving no temp file', function () {
    $target = $this->tmp.DIRECTORY_SEPARATOR.'note.md';
    File::put($target, 'old');

    $result = app(FileStorageService::class)->replaceFile($target, 'new content');

    expect($result)->toBe(FileReplaceResult::Replaced)
        ->and(File::get($target))->toBe('new content')
        ->and(glob($this->tmp.DIRECTORY_SEPARATOR.'.mdvault-save-*'))->toBe([]);
});

test('replaceFile aborts with GuardFailed when beforeReplace returns false, leaving the target intact', function () {
    $target = $this->tmp.DIRECTORY_SEPARATOR.'note.md';
    File::put($target, 'old');

    $result = app(FileStorageService::class)->replaceFile($target, 'new content', fn (): bool => false);

    expect($result)->toBe(FileReplaceResult::GuardFailed)
        ->and(File::get($target))->toBe('old')
        ->and(glob($this->tmp.DIRECTORY_SEPARATOR.'.mdvault-save-*'))->toBe([]);
});

test('replaceFile gives ReplaceFailed after 3 failed rename attempts, leaving the target intact', function () {
    $target = $this->tmp.DIRECTORY_SEPARATOR.'note.md';
    File::put($target, 'old');
    failFileMoves([1, 2, 3]);

    $result = app(FileStorageService::class)->replaceFile($target, 'new content');

    expect($result)->toBe(FileReplaceResult::ReplaceFailed)
        ->and(File::get($target))->toBe('old')
        ->and(glob($this->tmp.DIRECTORY_SEPARATOR.'.mdvault-save-*'))->toBe([]);
});

test('replaceFile succeeds after one failed rename attempt (retry)', function () {
    $target = $this->tmp.DIRECTORY_SEPARATOR.'note.md';
    File::put($target, 'old');
    failFileMoves([1]);

    $result = app(FileStorageService::class)->replaceFile($target, 'new content');

    expect($result)->toBe(FileReplaceResult::Replaced)
        ->and(File::get($target))->toBe('new content');
});

test('replaceFile gives WriteFailed on a short write, leaving the target intact', function () {
    $target = $this->tmp.DIRECTORY_SEPARATOR.'note.md';
    File::put($target, 'old');
    fakeFilePuts(truncateTo: 3);

    $result = app(FileStorageService::class)->replaceFile($target, 'new content');

    expect($result)->toBe(FileReplaceResult::WriteFailed)
        ->and(File::get($target))->toBe('old')
        ->and(glob($this->tmp.DIRECTORY_SEPARATOR.'.mdvault-save-*'))->toBe([]);
});

test('replaceFile gives TargetInvalid for a missing target and creates nothing', function () {
    $target = $this->tmp.DIRECTORY_SEPARATOR.'missing.md';

    $result = app(FileStorageService::class)->replaceFile($target, 'new content');

    expect($result)->toBe(FileReplaceResult::TargetInvalid)
        ->and(File::exists($target))->toBeFalse()
        ->and(glob($this->tmp.DIRECTORY_SEPARATOR.'.mdvault-save-*'))->toBe([]);
});

test('replaceFile gives TargetInvalid for a symlinked target', function () {
    $real = $this->tmp.DIRECTORY_SEPARATOR.'real.md';
    File::put($real, 'old');
    $link = $this->tmp.DIRECTORY_SEPARATOR.'link.md';
    symlink($real, $link);

    $result = app(FileStorageService::class)->replaceFile($link, 'new content');

    expect($result)->toBe(FileReplaceResult::TargetInvalid);
})->skipOnWindows();

test('replaceFile preserves the target permission bits', function () {
    $target = $this->tmp.DIRECTORY_SEPARATOR.'note.md';
    File::put($target, 'old');
    chmod($target, 0640);

    app(FileStorageService::class)->replaceFile($target, 'new content');

    expect(fileperms($target) & 0777)->toBe(0640);
})->skipOnWindows();

test('isWritableFile is true for a normal file and false for a read-only one', function () {
    $target = $this->tmp.DIRECTORY_SEPARATOR.'note.md';
    File::put($target, 'x');
    $service = app(FileStorageService::class);

    try {
        expect($service->isWritableFile($target))->toBeTrue();

        chmod($target, 0444);

        expect($service->isWritableFile($target))->toBeFalse();
    } finally {
        @chmod($target, 0644);
    }
});

// --- createFileFromStream (T2) --------------------------------------------

test('createFileFromStream writes exact bytes from the stream', function () {
    $target = $this->tmp.DIRECTORY_SEPARATOR.'restored.md';
    $stream = fopen('php://memory', 'r+');
    fwrite($stream, 'hello world');
    rewind($stream);

    $written = app(FileStorageService::class)->createFileFromStream($target, $stream, 1024);
    fclose($stream);

    expect($written)->toBe(11)
        ->and(File::get($target))->toBe('hello world');
});

test('createFileFromStream returns null and removes the file when the cap is exceeded', function () {
    $target = $this->tmp.DIRECTORY_SEPARATOR.'too-big.md';
    $stream = fopen('php://memory', 'r+');
    fwrite($stream, str_repeat('a', 20));
    rewind($stream);

    $written = app(FileStorageService::class)->createFileFromStream($target, $stream, 10);
    fclose($stream);

    expect($written)->toBeNull()
        ->and(file_exists($target))->toBeFalse();
});

test('createFileFromStream returns null and leaves an existing target unchanged', function () {
    $target = $this->tmp.DIRECTORY_SEPARATOR.'exists.md';
    File::put($target, 'original');
    $stream = fopen('php://memory', 'r+');
    fwrite($stream, 'new');
    rewind($stream);

    $written = app(FileStorageService::class)->createFileFromStream($target, $stream, 1024);
    fclose($stream);

    expect($written)->toBeNull()
        ->and(File::get($target))->toBe('original');
});

// --- discardTempFile / discardStaleTempFiles (T2) --------------------------

test('discardTempFile refuses a user file and accepts both temp prefixes', function () {
    $service = app(FileStorageService::class);

    $userFile = $this->tmp.DIRECTORY_SEPARATOR.'note.md';
    File::put($userFile, 'x');
    expect($service->discardTempFile($userFile))->toBeFalse();
    expect(file_exists($userFile))->toBeTrue();

    $save = $this->tmp.DIRECTORY_SEPARATOR.FileStorageService::SAVE_TEMP_PREFIX.'a';
    File::put($save, 'x');
    expect($service->discardTempFile($save))->toBeTrue();
    expect(file_exists($save))->toBeFalse();

    $backup = $this->tmp.DIRECTORY_SEPARATOR.FileStorageService::BACKUP_TEMP_PREFIX.'a.zip';
    File::put($backup, 'x');
    expect($service->discardTempFile($backup))->toBeTrue();
    expect(file_exists($backup))->toBeFalse();
});

test('discardStaleTempFiles removes only old prefixed files', function () {
    $service = app(FileStorageService::class);

    $old = $this->tmp.DIRECTORY_SEPARATOR.FileStorageService::BACKUP_TEMP_PREFIX.'old.zip';
    File::put($old, 'x');
    touch($old, time() - 7200);

    $fresh = $this->tmp.DIRECTORY_SEPARATOR.FileStorageService::BACKUP_TEMP_PREFIX.'fresh.zip';
    File::put($fresh, 'x');

    $unrelated = $this->tmp.DIRECTORY_SEPARATOR.'note.md';
    File::put($unrelated, 'x');
    touch($unrelated, time() - 7200);

    $count = $service->discardStaleTempFiles($this->tmp, FileStorageService::BACKUP_TEMP_PREFIX, 3600);

    expect($count)->toBe(1)
        ->and(file_exists($old))->toBeFalse()
        ->and(file_exists($fresh))->toBeTrue()
        ->and(file_exists($unrelated))->toBeTrue();
});

// --- deleteStagingDirectory / staleStagingDirectories (T2) -----------------

test('deleteStagingDirectory deletes a nested prefixed folder', function () {
    $staging = $this->tmp.DIRECTORY_SEPARATOR.FileStorageService::RESTORE_STAGING_PREFIX.'abc';
    File::makeDirectory($staging.DIRECTORY_SEPARATOR.'v0', 0755, true);
    File::put($staging.DIRECTORY_SEPARATOR.'v0'.DIRECTORY_SEPARATOR.'a.md', 'x');

    expect(app(FileStorageService::class)->deleteStagingDirectory($staging))->toBeTrue()
        ->and(is_dir($staging))->toBeFalse();
});

test('deleteStagingDirectory refuses a non-prefixed folder and a prefixed file', function () {
    $service = app(FileStorageService::class);

    $normal = $this->tmp.DIRECTORY_SEPARATOR.'Work';
    File::makeDirectory($normal);
    File::put($normal.DIRECTORY_SEPARATOR.'a.md', 'x');
    expect($service->deleteStagingDirectory($normal))->toBeFalse()
        ->and(is_dir($normal))->toBeTrue();

    $prefixedFile = $this->tmp.DIRECTORY_SEPARATOR.FileStorageService::RESTORE_STAGING_PREFIX.'file';
    File::put($prefixedFile, 'x');
    expect($service->deleteStagingDirectory($prefixedFile))->toBeFalse()
        ->and(file_exists($prefixedFile))->toBeTrue();
});

test('deleteStagingDirectory does not follow a symlinked staging folder', function () {
    $real = $this->tmp.DIRECTORY_SEPARATOR.'RealTarget';
    File::makeDirectory($real);
    File::put($real.DIRECTORY_SEPARATOR.'keep.md', 'x');

    $link = $this->tmp.DIRECTORY_SEPARATOR.FileStorageService::RESTORE_STAGING_PREFIX.'link';
    symlink($real, $link);

    expect(app(FileStorageService::class)->deleteStagingDirectory($link))->toBeFalse()
        ->and(is_dir($real))->toBeTrue()
        ->and(File::get($real.DIRECTORY_SEPARATOR.'keep.md'))->toBe('x');
})->skipOnWindows();

test('staleStagingDirectories lists only old prefixed directories', function () {
    $old = $this->tmp.DIRECTORY_SEPARATOR.FileStorageService::RESTORE_STAGING_PREFIX.'old';
    File::makeDirectory($old);
    touch($old, time() - 7200);

    $fresh = $this->tmp.DIRECTORY_SEPARATOR.FileStorageService::RESTORE_STAGING_PREFIX.'fresh';
    File::makeDirectory($fresh);

    $result = app(FileStorageService::class)->staleStagingDirectories($this->tmp, 3600);

    expect($result)->toBe([$old]);
});

// --- setModifiedTime / freeSpace (T2) ---------------------------------------

test('setModifiedTime sets the mtime', function () {
    $target = $this->tmp.DIRECTORY_SEPARATOR.'a.md';
    File::put($target, 'x');

    expect(app(FileStorageService::class)->setModifiedTime($target, 1_700_000_000))->toBeTrue()
        ->and(filemtime($target))->toBe(1_700_000_000);
});

test('freeSpace returns an int for the temp directory', function () {
    expect(app(FileStorageService::class)->freeSpace($this->tmp))->toBeInt();
});

test('discardTempFile (through replaceFile) never removes the target on any failure path', function () {
    $target = $this->tmp.DIRECTORY_SEPARATOR.'note.md';

    File::put($target, 'a');
    app(FileStorageService::class)->replaceFile($target, 'b', fn (): bool => false);
    expect(File::exists($target))->toBeTrue();

    File::put($target, 'a');
    failFileMoves([1, 2, 3]);
    app(FileStorageService::class)->replaceFile($target, 'b');
    expect(File::exists($target))->toBeTrue();

    File::put($target, 'a');
    fakeFilePuts(truncateTo: 0);
    app(FileStorageService::class)->replaceFile($target, 'b');
    expect(File::exists($target))->toBeTrue();
});

// --- conversion prefixes and inventory (Phase 7, T13) -----------------------

test('deleteStagingDirectory accepts the conversion prefixes and nothing else', function (string $prefix) {
    $folder = $this->tmp.DIRECTORY_SEPARATOR.$prefix.'abc';
    File::makeDirectory($folder.DIRECTORY_SEPARATOR.'nested', 0755, true);
    File::put($folder.DIRECTORY_SEPARATOR.'nested'.DIRECTORY_SEPARATOR.'a.mdenc', 'x');

    expect(app(FileStorageService::class)->deleteStagingDirectory($folder))->toBeTrue()
        ->and(is_dir($folder))->toBeFalse();
})->with([
    'encrypt' => FileStorageService::ENCRYPT_STAGING_PREFIX,
    'decrypt' => FileStorageService::DECRYPT_STAGING_PREFIX,
    'original' => FileStorageService::CONVERSION_ORIGINAL_PREFIX,
]);

test('deleteStagingDirectory still refuses a look-alike prefix', function () {
    $folder = $this->tmp.DIRECTORY_SEPARATOR.'.mdvault-encryptor-abc';
    File::makeDirectory($folder);

    expect(app(FileStorageService::class)->deleteStagingDirectory($folder))->toBeFalse()
        ->and(is_dir($folder))->toBeTrue();
});

test('inventory lists dot entries, nested files and directories without filtering', function () {
    File::makeDirectory($this->tmp.DIRECTORY_SEPARATOR.'.git', 0755, true);
    File::put($this->tmp.DIRECTORY_SEPARATOR.'.git'.DIRECTORY_SEPARATOR.'HEAD', 'ref');
    File::makeDirectory($this->tmp.DIRECTORY_SEPARATOR.'Work', 0755, true);
    File::put($this->tmp.DIRECTORY_SEPARATOR.'Work'.DIRECTORY_SEPARATOR.'a.md', 'x');
    File::put($this->tmp.DIRECTORY_SEPARATOR.'photo.png', 'png');

    $inventory = app(FileStorageService::class)->inventory($this->tmp);

    expect(array_column($inventory['files'], 'path'))->toBe(['.git/HEAD', 'Work/a.md', 'photo.png'])
        ->and($inventory['directories'])->toBe(['.git', 'Work'])
        ->and($inventory['symlinks'])->toBe([])
        ->and($inventory['unreadable'])->toBe([]);
});

test('inventory lists a symlink and does not follow it', function () {
    $real = $this->tmp.DIRECTORY_SEPARATOR.'RealTarget';
    File::makeDirectory($real);
    File::put($real.DIRECTORY_SEPARATOR.'inside.md', 'x');

    $root = $this->tmp.DIRECTORY_SEPARATOR.'Vault';
    File::makeDirectory($root);
    symlink($real, $root.DIRECTORY_SEPARATOR.'link');

    $inventory = app(FileStorageService::class)->inventory($root);

    expect($inventory['symlinks'])->toBe(['link'])
        ->and($inventory['files'])->toBe([])
        ->and($inventory['directories'])->toBe([]);
})->skipOnWindows();
