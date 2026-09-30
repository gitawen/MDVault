<?php

use App\Exceptions\BackupException;
use App\Services\ArchiveService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mdvault-archive-'.Str::random(8);
    File::makeDirectory($this->tmp, 0755, true);
    $this->service = app(ArchiveService::class);
});

afterEach(function () {
    File::deleteDirectory($this->tmp);
});

test('write/entries/eachEntryStream round trip strings, an empty directory and non-ASCII file names', function () {
    $sourceDir = $this->tmp.DIRECTORY_SEPARATOR.'sources';
    File::makeDirectory($sourceDir);

    $unicodeName = 'Café/Ünïcode 日本.md';
    $unicodeSource = $sourceDir.DIRECTORY_SEPARATOR.'unicode.md';
    File::put($unicodeSource, "unicode content\r\n");

    $asciiSource = $sourceDir.DIRECTORY_SEPARATOR.'ascii.md';
    File::put($asciiSource, 'ascii content');

    $zipPath = $this->tmp.DIRECTORY_SEPARATOR.'test.zip';

    $this->service->write(
        $zipPath,
        strings: ['manifest.json' => '{"a":1}'],
        directories: ['vaults/Work/Empty/'],
        files: [
            ['name' => 'vaults/Work/'.$unicodeName, 'source' => $unicodeSource],
            ['name' => 'vaults/Work/ascii.md', 'source' => $asciiSource],
        ],
    );

    expect(file_exists($zipPath))->toBeTrue();

    $entries = $this->service->entries($zipPath);
    $names = array_column($entries, 'name');

    expect($names)->toContain('manifest.json')
        ->toContain('vaults/Work/Empty/')
        ->toContain('vaults/Work/'.$unicodeName)
        ->toContain('vaults/Work/ascii.md');

    $emptyDirEntry = collect($entries)->firstWhere('name', 'vaults/Work/Empty/');
    expect($emptyDirEntry['is_directory'])->toBeTrue();

    $seen = [];
    $this->service->eachEntryStream($zipPath, ['vaults/Work/'.$unicodeName, 'vaults/Work/ascii.md'], function (string $name, $stream) use (&$seen): void {
        $seen[$name] = stream_get_contents($stream);
    });

    expect($seen['vaults/Work/'.$unicodeName])->toBe("unicode content\r\n")
        ->and($seen['vaults/Work/ascii.md'])->toBe('ascii content');
});

test('write refuses an existing path and leaves it unchanged', function () {
    $zipPath = $this->tmp.DIRECTORY_SEPARATOR.'existing.zip';
    File::put($zipPath, 'not a zip');

    expect(fn () => $this->service->write($zipPath, ['manifest.json' => '{}'], [], []))
        ->toThrow(BackupException::class);

    expect(File::get($zipPath))->toBe('not a zip');
});

test('entries throws for a non-ZIP file', function () {
    $path = $this->tmp.DIRECTORY_SEPARATOR.'not-a-zip.zip';
    File::put($path, 'plain text, not a zip archive');

    expect(fn () => $this->service->entries($path))->toThrow(BackupException::class);
});

test('a symlink entry is reported as is_symlink', function () {
    $zipPath = $this->tmp.DIRECTORY_SEPARATOR.'symlink.zip';

    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE);
    $zip->addFromString('link.md', '/target/path');
    $zip->setExternalAttributesName('link.md', ZipArchive::OPSYS_UNIX, 0120777 << 16);
    $zip->close();

    $entries = $this->service->entries($zipPath);
    $entry = collect($entries)->firstWhere('name', 'link.md');

    expect($entry['is_symlink'])->toBeTrue();
});

test('an encrypted entry is reported as is_encrypted', function () {
    $zipPath = $this->tmp.DIRECTORY_SEPARATOR.'encrypted.zip';

    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE);
    $zip->addFromString('secret.md', 'top secret');

    if (! $zip->setEncryptionName('secret.md', ZipArchive::EM_AES_256, 'password')) {
        $zip->close();
        test()->markTestSkipped('Encryption is not supported by this libzip build.');
    }

    $zip->close();

    $entries = $this->service->entries($zipPath);
    $entry = collect($entries)->firstWhere('name', 'secret.md');

    expect($entry['is_encrypted'])->toBeTrue();
});

test('readEntry returns null when the entry exceeds the cap', function () {
    $zipPath = $this->tmp.DIRECTORY_SEPARATOR.'capped.zip';
    $this->service->write($zipPath, ['big.txt' => str_repeat('a', 100)], [], []);

    expect($this->service->readEntry($zipPath, 'big.txt', 10))->toBeNull()
        ->and($this->service->readEntry($zipPath, 'big.txt', 1000))->toBe(str_repeat('a', 100));
});

// --- Analyst review AR-01: close() failure (a source that becomes unreadable
// between addFile() and close(), since addFile() only reads its source
// lazily, when close() runs) must not be called twice and must surface as a
// BackupException, not a ValueError/ErrorException. ---

test('write throws BackupException and leaves no file when a source cannot be read at close time', function () {
    // A directory can never be read as file content: addFile() registers it
    // successfully (its source is only opened lazily at close()), so this
    // reliably reproduces close() failing, on Windows and otherwise,
    // without relying on timing between addFile() and close().
    $sourceDir = $this->tmp.DIRECTORY_SEPARATOR.'source-is-a-directory';
    File::makeDirectory($sourceDir);

    $zipPath = $this->tmp.DIRECTORY_SEPARATOR.'close-fails.zip';

    expect(fn () => $this->service->write(
        $zipPath,
        strings: ['manifest.json' => '{}'],
        directories: [],
        files: [['name' => 'entry.md', 'source' => $sourceDir]],
    ))->toThrow(BackupException::class);

    expect(file_exists($zipPath))->toBeFalse();
});
