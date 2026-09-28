<?php

use App\Exceptions\NoteOperationException;
use App\Models\Note;
use App\Services\VaultIndexService;
use App\Services\VaultService;
use App\Support\IndexResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mdvault-index-'.Str::random(8);
    fakeDocumentsDirectory($this->tmp.DIRECTORY_SEPARATOR.'Documents');
    $this->vault = app(VaultService::class)->create('Work');
    $this->service = app(VaultIndexService::class);
});

afterEach(function () {
    File::deleteDirectory($this->tmp);
});

test('initial index creates a note per indexable file with the correct metadata', function () {
    writeVaultFiles($this->vault->path, [
        'Readme.md' => 'root',
        'Projects/HRMIS.md' => '# HRMIS',
        'Projects/Sub/Deep.md' => 'deep',
        'B.MD' => 'b',
    ]);

    $result = $this->service->reindex($this->vault);

    expect($result->added)->toBe(4);
    expect(Note::query()->count())->toBe(4);

    $hrmis = Note::query()->where('relative_path', 'Projects/HRMIS.md')->first();
    expect($hrmis)->not->toBeNull();
    expect($hrmis->title)->toBe('HRMIS');
    expect($hrmis->filename)->toBe('HRMIS.md');
    expect($hrmis->extension)->toBe('md');
    expect($hrmis->mime_type)->toBe('text/markdown');
    $abs = $this->vault->path.DIRECTORY_SEPARATOR.'Projects'.DIRECTORY_SEPARATOR.'HRMIS.md';
    expect($hrmis->file_hash)->toBe(hash_file('sha256', $abs));
    expect($hrmis->file_size)->toBe(filesize($abs));

    $b = Note::query()->where('relative_path', 'B.MD')->first();
    expect($b->filename)->toBe('B.MD');
    expect($b->extension)->toBe('md');
});

test('ignore rules exclude dotfiles, node_modules and non-markdown files', function () {
    writeVaultFiles($this->vault->path, [
        'x.txt' => 'x',
        '.git/c.md' => 'c',
        '.obsidian/d.md' => 'd',
        'node_modules/e.md' => 'e',
        'Node_Modules/f.md' => 'f',
        '.hidden.md' => 'h',
        '.mdvault-rename-abc' => 'r',
    ]);

    $result = $this->service->reindex($this->vault);

    expect($result->added)->toBe(0);
    expect(Note::query()->count())->toBe(0);
});

test('a second reindex with no changes reports everything unchanged', function () {
    writeVaultFiles($this->vault->path, [
        'Readme.md' => 'root',
        'Projects/HRMIS.md' => '# HRMIS',
        'Projects/Sub/Deep.md' => 'deep',
        'B.MD' => 'b',
    ]);
    $this->service->reindex($this->vault);
    $updatedAt = Note::query()->pluck('updated_at', 'relative_path')->all();

    $result = $this->service->reindex($this->vault);

    expect($result->unchanged)->toBe(4);
    expect($result->hasChanges())->toBeFalse();
    expect(Note::query()->pluck('updated_at', 'relative_path')->all())->toEqual($updatedAt);
});

test('an external edit updates the hash, size and keeps the UUID', function () {
    writeVaultFiles($this->vault->path, ['a.md' => 'one']);
    $this->service->reindex($this->vault);
    $uuid = Note::query()->first()->uuid;

    file_put_contents($this->vault->path.DIRECTORY_SEPARATOR.'a.md', 'two-longer');

    $result = $this->service->reindex($this->vault);

    expect($result->updated)->toBe(1);
    $note = Note::query()->first();
    expect($note->uuid)->toBe($uuid);
    expect($note->file_hash)->toBe(hash('sha256', 'two-longer'));
    expect($note->file_size)->toBe(strlen('two-longer'));
});

test('an external delete removes the record', function () {
    writeVaultFiles($this->vault->path, ['a.md' => 'one']);
    $this->service->reindex($this->vault);

    unlink($this->vault->path.DIRECTORY_SEPARATOR.'a.md');

    $result = $this->service->reindex($this->vault);

    expect($result->removed)->toBe(1);
    expect(Note::query()->count())->toBe(0);
});

test('an external create adds a record', function () {
    $this->service->reindex($this->vault);

    writeVaultFiles($this->vault->path, ['new.md' => 'x']);

    $result = $this->service->reindex($this->vault);

    expect($result->added)->toBe(1);
    expect(Note::query()->count())->toBe(1);
});

test('an external rename and move with unchanged content keeps the UUID', function () {
    writeVaultFiles($this->vault->path, ['Projects/HRMIS.md' => 'content']);
    $this->service->reindex($this->vault);
    $uuid = Note::query()->first()->uuid;

    File::makeDirectory($this->vault->path.DIRECTORY_SEPARATOR.'Archive');
    rename(
        $this->vault->path.DIRECTORY_SEPARATOR.'Projects'.DIRECTORY_SEPARATOR.'HRMIS.md',
        $this->vault->path.DIRECTORY_SEPARATOR.'Archive'.DIRECTORY_SEPARATOR.'HRMIS-old.md',
    );

    $result = $this->service->reindex($this->vault);

    expect($result->moved)->toBe(1);
    $note = Note::query()->first();
    expect($note->uuid)->toBe($uuid);
    expect($note->relative_path)->toBe('Archive/HRMIS-old.md');
    expect($note->filename)->toBe('HRMIS-old.md');
    expect($note->title)->toBe('HRMIS-old');
});

test('a case-only external rename keeps the UUID', function () {
    writeVaultFiles($this->vault->path, ['Readme.md' => 'content']);
    $this->service->reindex($this->vault);
    $uuid = Note::query()->first()->uuid;

    $from = $this->vault->path.DIRECTORY_SEPARATOR.'Readme.md';
    $tmp = $this->vault->path.DIRECTORY_SEPARATOR.'.tmp-rename';
    rename($from, $tmp);
    rename($tmp, $this->vault->path.DIRECTORY_SEPARATOR.'README.md');

    $result = $this->service->reindex($this->vault);

    expect($result->moved)->toBe(1);
    $note = Note::query()->first();
    expect($note->uuid)->toBe($uuid);
    expect($note->relative_path)->toBe('README.md');
});

test('a rename plus an edit gives a new UUID', function () {
    writeVaultFiles($this->vault->path, ['a.md' => 'one']);
    $this->service->reindex($this->vault);
    $uuid = Note::query()->first()->uuid;

    rename($this->vault->path.DIRECTORY_SEPARATOR.'a.md', $this->vault->path.DIRECTORY_SEPARATOR.'b.md');
    file_put_contents($this->vault->path.DIRECTORY_SEPARATOR.'b.md', 'two');

    $result = $this->service->reindex($this->vault);

    expect($result->removed)->toBe(1);
    expect($result->added)->toBe(1);
    expect(Note::query()->first()->uuid)->not->toBe($uuid);
});

test('an ambiguous identical-content match is not paired', function () {
    writeVaultFiles($this->vault->path, ['a.md' => 'same', 'b.md' => 'same']);
    $this->service->reindex($this->vault);

    unlink($this->vault->path.DIRECTORY_SEPARATOR.'a.md');
    unlink($this->vault->path.DIRECTORY_SEPARATOR.'b.md');
    file_put_contents($this->vault->path.DIRECTORY_SEPARATOR.'c.md', 'same');

    $result = $this->service->reindex($this->vault);

    expect($result->removed)->toBe(2);
    expect($result->added)->toBe(1);
});

test('the registry can be fully rebuilt from disk', function () {
    writeVaultFiles($this->vault->path, [
        'Readme.md' => 'root',
        'Projects/HRMIS.md' => '# HRMIS',
        'Projects/Sub/Deep.md' => 'deep',
        'B.MD' => 'b',
    ]);
    $this->service->reindex($this->vault);

    Note::query()->delete();

    $result = $this->service->reindex($this->vault);

    expect($result->added)->toBe(4);
    expect(Note::query()->count())->toBe(4);
    expect(Note::query()->where('relative_path', 'Projects/HRMIS.md')->exists())->toBeTrue();
});

test('a missing vault folder throws with field vault and changes nothing', function () {
    writeVaultFiles($this->vault->path, ['a.md' => 'x']);
    $this->service->reindex($this->vault);
    $countBefore = Note::query()->count();

    File::deleteDirectory($this->vault->path);

    try {
        $this->service->reindex($this->vault);
        $this->fail('Expected a NoteOperationException.');
    } catch (NoteOperationException $e) {
        expect($e->field())->toBe('vault');
    }

    expect(Note::query()->count())->toBe($countBefore);
});

test('a symlinked folder is not indexed', function () {
    $real = $this->tmp.DIRECTORY_SEPARATOR.'RealNotes';
    File::makeDirectory($real);
    File::put($real.DIRECTORY_SEPARATOR.'inside.md', 'x');
    symlink($real, $this->vault->path.DIRECTORY_SEPARATOR.'Linked');

    $result = $this->service->reindex($this->vault);

    expect($result->added)->toBe(0);
})->skipOnWindows();

test('unicode filenames and spaces are indexed with the exact relative path', function () {
    writeVaultFiles($this->vault->path, ['Café notes/Été 2026.md' => 'x']);

    $this->service->reindex($this->vault);

    expect(Note::query()->where('relative_path', 'Café notes/Été 2026.md')->exists())->toBeTrue();
});

test('browse lists an empty folder, orders folders first and marks the open folder', function () {
    writeVaultFiles($this->vault->path, [
        'Readme.md' => 'root',
        'Projects/HRMIS.md' => '# HRMIS',
        'Projects/Sub/Deep.md' => 'deep',
        'B.MD' => 'b',
        'Archive/' => '',
    ]);
    $this->service->reindex($this->vault);

    $browsed = $this->service->browse($this->vault, 'Projects/HRMIS.md');

    expect($browsed['folders'])->toBe(['', 'Archive', 'Projects', 'Projects/Sub']);

    $topLevel = $browsed['tree'];
    expect($topLevel[0]['type'])->toBe('folder')->and($topLevel[0]['name'])->toBe('Archive');
    expect($topLevel[1]['type'])->toBe('folder')->and($topLevel[1]['name'])->toBe('Projects');
    expect($topLevel[1]['open'])->toBeTrue();
    expect($topLevel[2]['type'])->toBe('note')->and($topLevel[2]['title'])->toBe('B');
    expect($topLevel[3]['type'])->toBe('note')->and($topLevel[3]['title'])->toBe('Readme');

    foreach ($topLevel as $node) {
        expect($node)->not->toHaveKey('id');
    }
});

test('IndexResult summary text for no changes, changes and skipped items', function () {
    expect((new IndexResult(0, 0, 0, 0, 4, 0))->summary())->toBe('The index is already up to date.');
    expect((new IndexResult(1, 2, 3, 4, 0, 0))->summary())->toBe('Index updated: 1 added, 2 updated, 3 moved or renamed, 4 removed.');
    expect((new IndexResult(1, 0, 0, 0, 0, 2))->summary())
        ->toBe("Index updated: 1 added, 0 updated, 0 moved or renamed, 0 removed. 2 item(s) couldn't be read and were left as they were.");
});
