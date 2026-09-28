<?php

use App\Models\Note;
use App\Services\NoteService;
use App\Services\VaultIndexService;
use App\Services\VaultService;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mdvault-notesvc-'.Str::random(8);
    fakeDocumentsDirectory($this->tmp.DIRECTORY_SEPARATOR.'Documents');
    $this->vault = app(VaultService::class)->create('Work');
    $this->service = app(NoteService::class);
});

afterEach(function () {
    File::deleteDirectory($this->tmp);
});

// --- Create -----------------------------------------------------------

test('create makes an empty file at the vault root and a matching record', function () {
    $note = $this->service->create($this->vault, null, 'Meeting');

    $path = $this->vault->path.DIRECTORY_SEPARATOR.'Meeting.md';
    expect(is_file($path))->toBeTrue();
    expect(filesize($path))->toBe(0);
    expect($note->relative_path)->toBe('Meeting.md');
    expect($note->title)->toBe('Meeting');
    expect($note->file_hash)->toBe('e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855');
});

test('create in a pre-created folder places the note there', function () {
    File::makeDirectory($this->vault->path.DIRECTORY_SEPARATOR.'Projects');

    $note = $this->service->create($this->vault, 'Projects', 'Meeting');

    expect($note->relative_path)->toBe('Projects/Meeting.md');
    expect(is_file($this->vault->path.DIRECTORY_SEPARATOR.'Projects'.DIRECTORY_SEPARATOR.'Meeting.md'))->toBeTrue();
});

test('a typed .md extension is stripped so there is no double extension', function (string $typed) {
    $note = $this->service->create($this->vault, null, $typed);

    expect($note->filename)->toBe('Plan.md');
})->with(['Plan.md', 'Plan.MD']);

test('invalid note names are refused on field name and create nothing', function (string $name) {
    $field = noteOperationField(fn () => $this->service->create($this->vault, null, $name));

    expect($field)->toBe('name');
    expect(Note::query()->count())->toBe(0);
})->with([
    '' => [''],
    'CON' => ['CON'],
    'a/b' => ['a/b'],
    'x.' => ['x.'],
    'leading space' => [' x'],
    '.hidden' => ['.hidden'],
    '101 characters' => [str_repeat('a', 101)],
]);

test('an existing file of a different case is refused as targetExists', function () {
    File::put($this->vault->path.DIRECTORY_SEPARATOR.'meeting.md', 'existing');

    $field = noteOperationField(fn () => $this->service->create($this->vault, null, 'Meeting'));

    expect($field)->toBe('name');
    expect(File::get($this->vault->path.DIRECTORY_SEPARATOR.'meeting.md'))->toBe('existing');
});

test('a registry conflict of a different case is refused even when the disk has no clash', function () {
    File::put($this->vault->path.DIRECTORY_SEPARATOR.'MEETING.md', 'x');
    app(VaultIndexService::class)->reindex($this->vault->fresh());

    $field = noteOperationField(fn () => $this->service->create($this->vault, null, 'Meeting'));

    expect($field)->toBe('name');
})->skipOnWindows();

test('invalid folder inputs are refused on field folder', function (string $folder) {
    $field = noteOperationField(fn () => $this->service->create($this->vault, $folder, 'Meeting'));

    expect($field)->toBe('folder');
})->with([
    '../x', 'C:/x', '/abs', 'a\\b', 'a//b', 'Missing',
]);

test('a symlinked folder pointing outside the vault is refused on field folder', function () {
    $outside = $this->tmp.DIRECTORY_SEPARATOR.'Outside';
    File::makeDirectory($outside);
    symlink($outside, $this->vault->path.DIRECTORY_SEPARATOR.'Linked');

    $field = noteOperationField(fn () => $this->service->create($this->vault, 'Linked', 'Meeting'));

    expect($field)->toBe('folder');
    expect(File::exists($outside.DIRECTORY_SEPARATOR.'Meeting.md'))->toBeFalse();
})->skipOnWindows();

test('a DB failure during create leaves no file and no record', function () {
    Note::creating(function (): void {
        throw new RuntimeException('db down');
    });

    expect(fn () => $this->service->create($this->vault, null, 'Meeting'))->toThrow(RuntimeException::class, 'db down');

    Note::flushEventListeners();

    expect(File::exists($this->vault->path.DIRECTORY_SEPARATOR.'Meeting.md'))->toBeFalse();
    expect(Note::query()->count())->toBe(0);
});

test('create refuses when the vault folder is missing', function () {
    File::deleteDirectory($this->vault->path);

    $field = noteOperationField(fn () => $this->service->create($this->vault, null, 'Meeting'));

    expect($field)->toBe('vault');
});

// --- Rename -------------------------------------------------------------

test('rename moves the file, keeps content, and updates the record', function () {
    $note = $this->service->create($this->vault, null, 'a');
    File::put($this->vault->path.DIRECTORY_SEPARATOR.'a.md', 'hello');

    $renamed = $this->service->rename($note, 'b');

    expect(File::get($this->vault->path.DIRECTORY_SEPARATOR.'b.md'))->toBe('hello');
    expect(file_exists($this->vault->path.DIRECTORY_SEPARATOR.'a.md'))->toBeFalse();
    expect($renamed->relative_path)->toBe('b.md');
    expect($renamed->filename)->toBe('b.md');
    expect($renamed->title)->toBe('b');
});

test('a case-only rename works', function () {
    $note = $this->service->create($this->vault, null, 'a');

    $renamed = $this->service->rename($note, 'A');

    expect($renamed->relative_path)->toBe('A.md');
    expect(scandir($this->vault->path))->toContain('A.md');
});

test('renaming to an existing name is refused as targetExists', function () {
    $note = $this->service->create($this->vault, null, 'a');
    $this->service->create($this->vault, null, 'b');

    $field = noteOperationField(fn () => $this->service->rename($note, 'b'));

    expect($field)->toBe('name');
});

test('renaming a note whose file was deleted externally fails with noteFileMissing', function () {
    $note = $this->service->create($this->vault, null, 'a');
    unlink($this->vault->path.DIRECTORY_SEPARATOR.'a.md');

    $field = noteOperationField(fn () => $this->service->rename($note, 'b'));

    expect($field)->toBe('name');
});

test('a simulated lock on rename gives moveFailed and changes nothing', function () {
    $note = $this->service->create($this->vault, null, 'a');
    failFileMoves([1]);
    $service = app(NoteService::class);

    $field = noteOperationField(fn () => $service->rename($note->fresh(), 'b'));

    expect($field)->toBe('name');
    expect(file_exists($this->vault->path.DIRECTORY_SEPARATOR.'a.md'))->toBeTrue();
    expect(Note::query()->first()->relative_path)->toBe('a.md');
});

test('a DB failure after the file move renames the file back', function () {
    $note = $this->service->create($this->vault, null, 'a');
    Note::saving(function (Note $model): void {
        if ($model->exists) {
            throw new RuntimeException('db down');
        }
    });

    expect(fn () => $this->service->rename($note, 'b'))->toThrow(RuntimeException::class, 'db down');

    Note::flushEventListeners();

    expect(file_exists($this->vault->path.DIRECTORY_SEPARATOR.'a.md'))->toBeTrue();
    expect(file_exists($this->vault->path.DIRECTORY_SEPARATOR.'b.md'))->toBeFalse();
    expect($note->relative_path)->toBe('a.md');
    expect($note->fresh()->relative_path)->toBe('a.md');
});

test('a rollback failure after a DB failure reports and keeps the file at its new name', function () {
    Exceptions::fake();
    $note = $this->service->create($this->vault, null, 'a');
    Note::saving(function (Note $model): void {
        if ($model->exists) {
            throw new RuntimeException('db down');
        }
    });
    failFileMoves([2]);
    $service = app(NoteService::class);

    $field = noteOperationField(fn () => $service->rename($note->fresh(), 'b'));

    Note::flushEventListeners();

    expect($field)->toBe('name');
    expect(file_exists($this->vault->path.DIRECTORY_SEPARATOR.'b.md'))->toBeTrue();
    Exceptions::assertReported(RuntimeException::class);
});

test('renaming to the same name is a no-op with no filesystem call', function () {
    $note = $this->service->create($this->vault, null, 'a');
    $fake = failFileMoves([1]);
    $service = app(NoteService::class);

    $result = $service->rename($note->fresh(), 'a');

    expect($result->relative_path)->toBe('a.md');
    expect($fake->calls)->toBe(0);
});

// --- Move -----------------------------------------------------------------

test('move relocates a note between folders and keeps the UUID', function () {
    File::makeDirectory($this->vault->path.DIRECTORY_SEPARATOR.'Projects');
    File::makeDirectory($this->vault->path.DIRECTORY_SEPARATOR.'Servers');
    $note = $this->service->create($this->vault, 'Projects', 'HRMIS');
    $uuid = $note->uuid;

    $moved = $this->service->move($note, 'Servers');

    expect($moved->uuid)->toBe($uuid);
    expect($moved->relative_path)->toBe('Servers/HRMIS.md');
    expect($moved->filename)->toBe('HRMIS.md');
});

test('move to null goes to the vault root', function () {
    File::makeDirectory($this->vault->path.DIRECTORY_SEPARATOR.'Projects');
    $note = $this->service->create($this->vault, 'Projects', 'HRMIS');

    $moved = $this->service->move($note, null);

    expect($moved->relative_path)->toBe('HRMIS.md');
});

test('moving a note with an upper-case extension keeps its case', function () {
    File::makeDirectory($this->vault->path.DIRECTORY_SEPARATOR.'Servers');
    writeVaultFiles($this->vault->path, ['B.MD' => 'x']);
    app(VaultIndexService::class)->reindex($this->vault->fresh());
    $note = Note::query()->first();

    $moved = $this->service->move($note, 'Servers');

    expect($moved->filename)->toBe('B.MD');
});

test('moving to a folder that does not exist is refused on field folder', function () {
    $note = $this->service->create($this->vault, null, 'a');

    $field = noteOperationField(fn () => $this->service->move($note, 'Missing'));

    expect($field)->toBe('folder');
});

test('moving onto an existing file in the target folder is refused as targetExists', function () {
    File::makeDirectory($this->vault->path.DIRECTORY_SEPARATOR.'Servers');
    File::put($this->vault->path.DIRECTORY_SEPARATOR.'Servers'.DIRECTORY_SEPARATOR.'HRMIS.md', 'x');
    $note = $this->service->create($this->vault, null, 'HRMIS');

    $field = noteOperationField(fn () => $this->service->move($note, 'Servers'));

    expect($field)->toBe('folder');
});

// --- Delete -----------------------------------------------------------

test('delete moves the file to the fake trash and removes the record', function () {
    $fake = fakeTrash();
    $note = $this->service->create($this->vault, null, 'a');
    $service = app(NoteService::class);
    $path = $this->vault->path.DIRECTORY_SEPARATOR.'a.md';

    $result = $service->delete($note);

    expect($result)->toBeTrue();
    expect(file_exists($path))->toBeFalse();
    expect(Note::query()->count())->toBe(0);
    expect($fake->trashed)->toBe([$path]);
});

test('a silent trash failure keeps the record', function () {
    fakeTrash(deletes: false);
    $note = $this->service->create($this->vault, null, 'a');
    $service = app(NoteService::class);

    $field = noteOperationField(fn () => $service->delete($note));

    expect($field)->toBe('note');
    expect(Note::query()->count())->toBe(1);
});

test('an unavailable trash refuses and changes nothing', function () {
    fakeTrash(available: false);
    $note = $this->service->create($this->vault, null, 'a');
    $service = app(NoteService::class);

    $field = noteOperationField(fn () => $service->delete($note));

    expect($field)->toBe('note');
    expect(file_exists($this->vault->path.DIRECTORY_SEPARATOR.'a.md'))->toBeTrue();
    expect(Note::query()->count())->toBe(1);
});

test('deleting a note whose file is already gone just removes the record', function () {
    $note = $this->service->create($this->vault, null, 'a');
    unlink($this->vault->path.DIRECTORY_SEPARATOR.'a.md');

    $result = $this->service->delete($note);

    expect($result)->toBeFalse();
    expect(Note::query()->count())->toBe(0);
});

// --- Folders --------------------------------------------------------------

test('createFolder creates nested folders', function () {
    $this->service->createFolder($this->vault, null, 'Projects');
    $this->service->createFolder($this->vault, 'Projects', 'Sub');

    expect(is_dir($this->vault->path.DIRECTORY_SEPARATOR.'Projects'))->toBeTrue();
    expect(is_dir($this->vault->path.DIRECTORY_SEPARATOR.'Projects'.DIRECTORY_SEPARATOR.'Sub'))->toBeTrue();
});

test('createFolder refuses an existing entry', function () {
    $this->service->createFolder($this->vault, null, 'Projects');

    $field = noteOperationField(fn () => $this->service->createFolder($this->vault, null, 'Projects'));

    expect($field)->toBe('name');
});

test('createFolder refuses invalid names', function (string $name) {
    $field = noteOperationField(fn () => $this->service->createFolder($this->vault, null, $name));

    expect($field)->toBe('name');
})->with(['.git', 'node_modules', 'CON']);

test('deleteFolder removes an empty folder', function () {
    $this->service->createFolder($this->vault, null, 'Archive');

    $this->service->deleteFolder($this->vault, 'Archive');

    expect(is_dir($this->vault->path.DIRECTORY_SEPARATOR.'Archive'))->toBeFalse();
});

test('deleteFolder refuses a non-empty folder and leaves its contents intact', function (string $file) {
    $this->service->createFolder($this->vault, null, 'Archive');
    File::put($this->vault->path.DIRECTORY_SEPARATOR.'Archive'.DIRECTORY_SEPARATOR.$file, 'x');

    $field = noteOperationField(fn () => $this->service->deleteFolder($this->vault, 'Archive'));

    expect($field)->toBe('path');
    expect(File::get($this->vault->path.DIRECTORY_SEPARATOR.'Archive'.DIRECTORY_SEPARATOR.$file))->toBe('x');
})->with(['.DS_Store', 'a.md']);

test('deleteFolder refuses the vault root', function () {
    $field = noteOperationField(fn () => $this->service->deleteFolder($this->vault, ''));

    expect($field)->toBe('path');
});

test('deleting an empty folder removes stale records under it', function () {
    File::makeDirectory($this->vault->path.DIRECTORY_SEPARATOR.'Archive');
    $note = Note::factory()->create([
        'vault_id' => $this->vault->id,
        'relative_path' => 'Archive/Ghost.md',
        'filename' => 'Ghost.md',
        'title' => 'Ghost',
    ]);

    $this->service->deleteFolder($this->vault, 'Archive');

    expect(Note::query()->whereKey($note->id)->exists())->toBeFalse();
});

// --- Preview ----------------------------------------------------------

test('preview returns the exact content when ok', function () {
    $note = $this->service->create($this->vault, null, 'a');
    File::put($this->vault->path.DIRECTORY_SEPARATOR.'a.md', 'Hello world');
    $service = app(NoteService::class);

    $result = $service->preview($note->fresh());

    expect($result['state'])->toBe('ok');
    expect($result['content'])->toBe('Hello world');
});

test('an external edit updates the record hash and size on preview', function () {
    $note = $this->service->create($this->vault, null, 'a');
    File::put($this->vault->path.DIRECTORY_SEPARATOR.'a.md', 'edited content');
    $service = app(NoteService::class);

    $service->preview($note->fresh());

    $fresh = $note->fresh();
    expect($fresh->file_hash)->toBe(hash('sha256', 'edited content'));
    expect($fresh->file_size)->toBe(strlen('edited content'));
});

test('preview of a missing file gives the missing state', function () {
    $note = $this->service->create($this->vault, null, 'a');
    unlink($this->vault->path.DIRECTORY_SEPARATOR.'a.md');

    $result = $this->service->preview($note->fresh());

    expect($result['state'])->toBe('missing');
    expect($result['content'])->toBeNull();
});

test('a file over 1 MiB gives the too_large state with no content', function () {
    $note = $this->service->create($this->vault, null, 'a');
    File::put($this->vault->path.DIRECTORY_SEPARATOR.'a.md', str_repeat('x', 1_048_577));

    $result = $this->service->preview($note->fresh());

    expect($result['state'])->toBe('too_large');
    expect($result['content'])->toBeNull();
});

test('invalid UTF-8 is flagged and replaced so the content is valid UTF-8', function () {
    $note = $this->service->create($this->vault, null, 'a');
    File::put($this->vault->path.DIRECTORY_SEPARATOR.'a.md', "a\xFFb");

    $result = $this->service->preview($note->fresh());

    expect($result['is_valid_utf8'])->toBeFalse();
    expect(mb_check_encoding($result['content'], 'UTF-8'))->toBeTrue();
    expect(json_encode($result['content']))->not->toBeFalse();
});

test('present has the exact keys and no id or vault_id', function () {
    File::makeDirectory($this->vault->path.DIRECTORY_SEPARATOR.'Projects');
    $note = $this->service->create($this->vault, 'Projects', 'HRMIS');

    $presented = $this->service->present($note);

    expect($presented)->toHaveKeys(['uuid', 'title', 'filename', 'relative_path', 'folder', 'file_size', 'file_hash', 'updated_at'])
        ->not->toHaveKey('id')
        ->not->toHaveKey('vault_id');
    expect($presented['folder'])->toBe('Projects');
});

// --- Vault interplay (FR-20) --------------------------------------------

test('a note still previews ok after its vault is renamed', function () {
    $note = $this->service->create($this->vault, null, 'a');

    app(VaultService::class)->rename($this->vault, 'Office', null);

    $result = $this->service->preview($note->fresh());

    expect($result['state'])->toBe('ok');
});

test('removing a vault deletes its note records but leaves the files', function () {
    $note = $this->service->create($this->vault, null, 'a');
    $path = $this->vault->path.DIRECTORY_SEPARATOR.'a.md';

    app(VaultService::class)->remove($this->vault);

    expect(Note::query()->count())->toBe(0);
    expect(file_exists($path))->toBeTrue();
});
