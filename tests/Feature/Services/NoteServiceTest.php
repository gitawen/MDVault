<?php

use App\Enums\IndexMode;
use App\Enums\NoteSaveMode;
use App\Enums\SettingKey;
use App\Exceptions\NoteSaveConflictException;
use App\Models\Note;
use App\Services\MarkdownService;
use App\Services\NoteService;
use App\Services\SettingsService;
use App\Services\VaultIndexService;
use App\Services\VaultService;
use App\Support\FrontmatterEdit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
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

test('create makes an empty file at the vault root and a matching record (template off)', function () {
    app(SettingsService::class)->set(SettingKey::EditorNewNoteTemplateEnabled, false);

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

// --- Revision 4: new-note frontmatter template ---------------------------

test('with the template on (the default), the new file equals the rendered template and the DB matches the disk', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-29 12:00:00'));

    $note = $this->service->create($this->vault, null, 'Meeting');
    $path = $this->vault->path.DIRECTORY_SEPARATOR.'Meeting.md';

    $expected = "---\ntitle: \"Meeting\"\ncreated: 2026-09-29\n# tags: []\n# aliases: []\n---\n\n";
    expect(File::get($path))->toBe($expected);
    expect($note->file_hash)->toBe(hash_file('sha256', $path));
    expect($note->file_size)->toBe(filesize($path));

    CarbonImmutable::setTestNow();
});

test('preview() of a newly created note gives the rendered frontmatter_yaml and an empty body', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-29 12:00:00'));

    $note = $this->service->create($this->vault, null, 'Meeting');
    $preview = $this->service->preview($note->fresh());

    expect($preview['frontmatter_yaml'])->toBe("title: \"Meeting\"\ncreated: 2026-09-29\n# tags: []\n# aliases: []");
    expect($preview['body'])->toBe('');

    CarbonImmutable::setTestNow();
});

test('the client timezone decides the local created date, even near midnight UTC', function () {
    // 23:00 UTC on the 28th is already 13:00 on the 29th in
    // Pacific/Kiritimati (UTC+14), the earliest timezone in the world.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-28 23:00:00', 'UTC'));

    $note = $this->service->create($this->vault, null, 'Meeting', 'Pacific/Kiritimati');
    $path = $this->vault->path.DIRECTORY_SEPARATOR.'Meeting.md';

    expect(File::get($path))->toContain('created: 2026-09-29');
    expect($note->file_hash)->toBe(hash_file('sha256', $path));

    CarbonImmutable::setTestNow();
});

test('a DB failure removes the new templated file only when its bytes are still exactly what was written', function () {
    $path = $this->vault->path.DIRECTORY_SEPARATOR.'Meeting.md';

    Note::creating(function () use ($path): void {
        // Something else appended to the file before the DB write failed.
        file_put_contents($path, 'external', FILE_APPEND);

        throw new RuntimeException('db down');
    });

    expect(fn () => $this->service->create($this->vault, null, 'Meeting'))->toThrow(RuntimeException::class, 'db down');

    Note::flushEventListeners();

    // The file survives because it no longer matches exactly what create()
    // wrote: the compensation only ever deletes an unchanged new file.
    expect(File::exists($path))->toBeTrue();
    expect(File::get($path))->toEndWith('external');
    expect(Note::query()->count())->toBe(0);
});

test('creating a note never touches an existing note file in the same vault', function () {
    $existing = $this->service->create($this->vault, null, 'Existing');
    $existingPath = $this->vault->path.DIRECTORY_SEPARATOR.'Existing.md';
    File::put($existingPath, 'Some existing content');
    $hashBefore = hash_file('sha256', $existingPath);
    $mtimeBefore = filemtime($existingPath);

    $this->service->create($this->vault, null, 'Meeting');

    expect(hash_file('sha256', $existingPath))->toBe($hashBefore);
    expect(filemtime($existingPath))->toBe($mtimeBefore);
    expect(File::get($existingPath))->toBe('Some existing content');
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

test('an external edit nulls a trusted file_mtime on preview (AR-03)', function () {
    $path = $this->vault->path.DIRECTORY_SEPARATOR.'a.md';
    $note = $this->service->create($this->vault, null, 'a');
    File::put($path, 'one');

    app(VaultIndexService::class)->reconcile($this->vault, IndexMode::Full);
    touch($path, time() - 100);
    app(VaultIndexService::class)->reconcile($this->vault, IndexMode::Full);
    expect($note->fresh()->file_mtime)->not->toBeNull();

    File::put($path, 'edited content');
    $this->service->preview($note->fresh());

    expect($note->fresh()->file_mtime)->toBeNull();
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

test('preview of an ok note has base_hash, body and frontmatter and is editable', function () {
    $note = $this->service->create($this->vault, null, 'a');
    File::put($this->vault->path.DIRECTORY_SEPARATOR.'a.md', "---\ntitle: X\n---\n\n# H\n");

    $result = $this->service->preview($note->fresh());

    expect($result['state'])->toBe('ok');
    expect($result['frontmatter'])->toBe("---\ntitle: X\n---\n\n");
    expect($result['body'])->toBe("# H\n");
    expect($result['base_hash'])->toBe(hash('sha256', "---\ntitle: X\n---\n\n# H\n"));
    expect($result['editable'])->toBeTrue();
    expect($result['read_only_reason'])->toBeNull();
});

test('preview of a missing, too_large or unreadable note is never editable', function () {
    $missing = $this->service->create($this->vault, null, 'missing-note');
    unlink($this->vault->path.DIRECTORY_SEPARATOR.'missing-note.md');
    $missingResult = $this->service->preview($missing->fresh());
    expect($missingResult['editable'])->toBeFalse();
    expect($missingResult['read_only_reason'])->toBeNull();

    $tooLarge = $this->service->create($this->vault, null, 'big-note');
    File::put($this->vault->path.DIRECTORY_SEPARATOR.'big-note.md', str_repeat('x', 1_048_577));
    $tooLargeResult = $this->service->preview($tooLarge->fresh());
    expect($tooLargeResult['editable'])->toBeFalse();
    expect($tooLargeResult['read_only_reason'])->toBe('too_large');
});

test('preview of an invalid UTF-8 note is not editable with reason invalid_utf8', function () {
    $note = $this->service->create($this->vault, null, 'a');
    File::put($this->vault->path.DIRECTORY_SEPARATOR.'a.md', "a\xFFb");

    $result = $this->service->preview($note->fresh());

    expect($result['editable'])->toBeFalse();
    expect($result['read_only_reason'])->toBe('invalid_utf8');
});

test('preview base_hash reflects a stale DB hash reconciled to the disk hash', function () {
    $note = $this->service->create($this->vault, null, 'a');
    File::put($this->vault->path.DIRECTORY_SEPARATOR.'a.md', 'edited content');

    $result = $this->service->preview($note->fresh());

    expect($result['base_hash'])->toBe(hash('sha256', 'edited content'));
});

// --- Save (ADR note-save-atomic-replace) --------------------------------

test('a Rich save composes the current frontmatter with the new body', function () {
    $note = $this->service->create($this->vault, null, 'a');
    $path = $this->vault->path.DIRECTORY_SEPARATOR.'a.md';
    File::put($path, "---\ntitle: X\n---\n\n# Old\n");
    $note = $note->fresh();
    $baseHash = hash('sha256', "---\ntitle: X\n---\n\n# Old\n");

    $result = $this->service->save($note, "# New\n", $baseHash, NoteSaveMode::Rich);

    expect(File::get($path))->toBe("---\ntitle: X\n---\n\n# New\n");
    expect($result->written)->toBeTrue();
    expect($result->fileHash)->toBe(hash_file('sha256', $path));
    expect($note->fresh()->file_hash)->toBe($result->fileHash);
    expect($note->fresh()->relative_path)->toBe('a.md');
    expect($note->fresh()->uuid)->toBe($note->uuid);
});

test('a Source save is verbatim', function () {
    $note = $this->service->create($this->vault, null, 'a');
    $path = $this->vault->path.DIRECTORY_SEPARATOR.'a.md';
    File::put($path, "---\ntitle: X\n---\n\nOld body\n");
    $note = $note->fresh();
    $baseHash = hash('sha256', "---\ntitle: X\n---\n\nOld body\n");

    $result = $this->service->save($note, "raw text\nverbatim\n", $baseHash, NoteSaveMode::Source);

    expect(File::get($path))->toBe("raw text\nverbatim\n");
    expect($result->written)->toBeTrue();
});

test('a Rich save on a CRLF+BOM file produces CRLF+BOM bytes', function () {
    $note = $this->service->create($this->vault, null, 'a');
    $path = $this->vault->path.DIRECTORY_SEPARATOR.'a.md';
    $original = MarkdownService::BOM."# Old\r\n";
    File::put($path, $original);
    $note = $note->fresh();
    $baseHash = hash('sha256', $original);

    $this->service->save($note, "# New\n", $baseHash, NoteSaveMode::Rich);

    $bytes = File::get($path);
    expect($bytes)->toStartWith(MarkdownService::BOM);
    expect(substr_count($bytes, "\n"))->toBe(substr_count($bytes, "\r\n"));
    expect($bytes)->toEndWith("\r\n");
});

test('saving unchanged content is a no-op that leaves the modification time untouched', function () {
    $note = $this->service->create($this->vault, null, 'a');
    $path = $this->vault->path.DIRECTORY_SEPARATOR.'a.md';
    File::put($path, "# Same\n");
    // Bring the registry in line with the disk first, so the save is a true
    // no-op; otherwise reconcile() rightly catches up the stale hash and the
    // updated_at check depends on whether a second boundary was crossed.
    app(VaultIndexService::class)->reconcile($this->vault, IndexMode::Full);
    $note = $note->fresh();
    $baseHash = hash('sha256', "# Same\n");
    $before = $note->updated_at;
    $this->travel(5)->seconds();

    $fake = failFileMoves([1, 2, 3]);
    $result = $this->service->save($note, "# Same\n", $baseHash, NoteSaveMode::Rich);

    expect($result->written)->toBeFalse();
    expect($fake->calls)->toBe(0);
    expect($note->fresh()->updated_at->equalTo($before))->toBeTrue();
});

test('a no-op save makes no database write at all (AR-03)', function () {
    // Unlike "saving unchanged content is a no-op…" above, this must start
    // with the DB's file_hash/file_size already matching the disk (a true
    // no-op end to end), or reconcile() would rightly still write once to
    // catch up a stale record — which is not what this test is asserting.
    $note = $this->service->create($this->vault, null, 'a');
    $preview = $this->service->preview($note->fresh());
    $note = $note->fresh();

    $writes = 0;
    DB::listen(function ($query) use (&$writes): void {
        if (preg_match('/^\s*(insert|update|delete)\b/i', $query->sql) === 1) {
            $writes++;
        }
    });

    $result = $this->service->save($note, $preview['content'], $preview['base_hash'], NoteSaveMode::Source);

    expect($result->written)->toBeFalse();
    expect($writes)->toBe(0);
});

test('a content-changing save nulls a trusted file_mtime (AR-03)', function () {
    $path = $this->vault->path.DIRECTORY_SEPARATOR.'a.md';
    $note = $this->service->create($this->vault, null, 'a');
    File::put($path, "# Same\n");

    app(VaultIndexService::class)->reconcile($this->vault, IndexMode::Full);
    touch($path, time() - 100);
    app(VaultIndexService::class)->reconcile($this->vault, IndexMode::Full);
    expect($note->fresh()->file_mtime)->not->toBeNull();

    $baseHash = hash('sha256', "# Same\n");
    $result = $this->service->save($note->fresh(), "# New\n", $baseHash, NoteSaveMode::Rich);

    expect($result->written)->toBeTrue();
    expect($note->fresh()->file_mtime)->toBeNull();
});

test('a stale base hash gives a changed conflict and leaves the external content intact', function () {
    $note = $this->service->create($this->vault, null, 'a');
    $path = $this->vault->path.DIRECTORY_SEPARATOR.'a.md';
    File::put($path, "# Original\n");
    $note = $note->fresh();
    $staleHash = hash('sha256', '# Something else entirely');

    File::put($path, "# Changed externally\n");

    try {
        $this->service->save($note, "# My edit\n", $staleHash, NoteSaveMode::Rich);
        test()->fail('Expected a NoteSaveConflictException.');
    } catch (NoteSaveConflictException $e) {
        expect($e->reason())->toBe('changed');
        expect($e->currentHash())->toBe(hash('sha256', "# Changed externally\n"));
    }

    expect(File::get($path))->toBe("# Changed externally\n");
});

test('saving a note whose file was deleted externally gives a missing conflict', function () {
    $note = $this->service->create($this->vault, null, 'a');
    $path = $this->vault->path.DIRECTORY_SEPARATOR.'a.md';
    File::put($path, "# Original\n");
    $note = $note->fresh();
    $baseHash = hash('sha256', "# Original\n");
    unlink($path);

    try {
        $this->service->save($note, "# My edit\n", $baseHash, NoteSaveMode::Rich);
        test()->fail('Expected a NoteSaveConflictException.');
    } catch (NoteSaveConflictException $e) {
        expect($e->reason())->toBe('missing');
    }

    expect(File::exists($path))->toBeFalse();
});

test('a locked rename gives a content-field error and leaves the file intact', function () {
    $note = $this->service->create($this->vault, null, 'a');
    $path = $this->vault->path.DIRECTORY_SEPARATOR.'a.md';
    File::put($path, "# Original\n");
    $note = $note->fresh();
    $baseHash = hash('sha256', "# Original\n");
    failFileMoves([1, 2, 3]);
    $service = app(NoteService::class);

    $field = noteOperationField(fn () => $service->save($note, "# New\n", $baseHash, NoteSaveMode::Rich));

    expect($field)->toBe('content');
    expect(File::get($path))->toBe("# Original\n");
});

test('a short write gives a content-field error and leaves the file intact', function () {
    $note = $this->service->create($this->vault, null, 'a');
    $path = $this->vault->path.DIRECTORY_SEPARATOR.'a.md';
    File::put($path, "# Original\n");
    $note = $note->fresh();
    $baseHash = hash('sha256', "# Original\n");
    fakeFilePuts(truncateTo: 1);
    $service = app(NoteService::class);

    $field = noteOperationField(fn () => $service->save($note, "# New\n", $baseHash, NoteSaveMode::Rich));

    expect($field)->toBe('content');
    expect(File::get($path))->toBe("# Original\n");
});

test('a read-only file is refused and left untouched', function () {
    $note = $this->service->create($this->vault, null, 'a');
    $path = $this->vault->path.DIRECTORY_SEPARATOR.'a.md';
    File::put($path, "# Original\n");
    $note = $note->fresh();
    $baseHash = hash('sha256', "# Original\n");
    chmod($path, 0444);

    try {
        $field = noteOperationField(fn () => $this->service->save($note, "# New\n", $baseHash, NoteSaveMode::Rich));
        expect($field)->toBe('content');
    } finally {
        @chmod($path, 0644);
    }

    expect(File::get($path))->toBe("# Original\n");
});

test('content over the edit limit is refused', function () {
    $note = $this->service->create($this->vault, null, 'a');
    $path = $this->vault->path.DIRECTORY_SEPARATOR.'a.md';
    File::put($path, "# Original\n");
    $note = $note->fresh();
    $baseHash = hash('sha256', "# Original\n");

    $field = noteOperationField(fn () => $this->service->save(
        $note,
        str_repeat('x', NoteService::EDIT_LIMIT + 1),
        $baseHash,
        NoteSaveMode::Source,
    ));

    expect($field)->toBe('content');
    expect(File::get($path))->toBe("# Original\n");
});

test('a current file over the edit limit is not editable', function () {
    $note = $this->service->create($this->vault, null, 'a');
    $path = $this->vault->path.DIRECTORY_SEPARATOR.'a.md';
    File::put($path, str_repeat('x', 1_048_577));
    $note = $note->fresh();
    $baseHash = hash('sha256', str_repeat('x', 1_048_577));

    $field = noteOperationField(fn () => $this->service->save($note, 'y', $baseHash, NoteSaveMode::Source));

    expect($field)->toBe('content');
});

test('a current file with invalid UTF-8 is not editable', function () {
    $note = $this->service->create($this->vault, null, 'a');
    $path = $this->vault->path.DIRECTORY_SEPARATOR.'a.md';
    File::put($path, "a\xFFb");
    $note = $note->fresh();
    $baseHash = hash('sha256', "a\xFFb");

    $field = noteOperationField(fn () => $this->service->save($note, 'y', $baseHash, NoteSaveMode::Source));

    expect($field)->toBe('content');
});

test('a write raced by an external writer at put-time gives a changed conflict', function () {
    $note = $this->service->create($this->vault, null, 'a');
    $path = $this->vault->path.DIRECTORY_SEPARATOR.'a.md';
    File::put($path, "# Original\n");
    $note = $note->fresh();
    $baseHash = hash('sha256', "# Original\n");

    fakeFilePuts(onPut: function () use ($path): void {
        file_put_contents($path, 'external');
    });
    $service = app(NoteService::class);

    try {
        $service->save($note, "# New\n", $baseHash, NoteSaveMode::Rich);
        test()->fail('Expected a NoteSaveConflictException.');
    } catch (NoteSaveConflictException $e) {
        expect($e->reason())->toBe('changed');
    }

    expect(File::get($path))->toBe('external');
});

test('a DB failure after a successful write still reports the save as written', function () {
    Exceptions::fake();
    $note = $this->service->create($this->vault, null, 'a');
    $path = $this->vault->path.DIRECTORY_SEPARATOR.'a.md';
    File::put($path, "# Original\n");
    $this->service->preview($note->fresh());
    $note = $note->fresh();
    $baseHash = hash('sha256', "# Original\n");

    Note::saving(function (Note $saving): void {
        if ($saving->exists) {
            throw new RuntimeException('DB is down');
        }
    });

    $result = $this->service->save($note, "# New\n", $baseHash, NoteSaveMode::Rich);

    expect($result->written)->toBeTrue();
    expect(File::get($path))->toBe("# New\n");
    Exceptions::assertReported(RuntimeException::class);

    Note::flushEventListeners();
    expect($note->fresh()->file_hash)->toBe(hash('sha256', "# Original\n"));

    $reconciled = $this->service->preview($note->fresh());
    expect($reconciled['base_hash'])->toBe(hash('sha256', "# New\n"));
    expect($note->fresh()->file_hash)->toBe(hash('sha256', "# New\n"));
});

test('saving into a missing vault fails with field vault', function () {
    $note = $this->service->create($this->vault, null, 'a');
    $note = $note->fresh();
    $baseHash = hash('sha256', '');
    File::deleteDirectory($this->vault->path);

    $field = noteOperationField(fn () => $this->service->save($note, 'x', $baseHash, NoteSaveMode::Rich));

    expect($field)->toBe('vault');
});

test('two chained saves each succeed using the previous result hash as the next base', function () {
    $note = $this->service->create($this->vault, null, 'a');
    $path = $this->vault->path.DIRECTORY_SEPARATOR.'a.md';
    File::put($path, "# Original\n");
    $note = $note->fresh();
    $baseHash = hash('sha256', "# Original\n");

    $first = $this->service->save($note, "# First\n", $baseHash, NoteSaveMode::Rich);
    expect($first->written)->toBeTrue();

    $second = $this->service->save($note->fresh(), "# Second\n", $first->fileHash, NoteSaveMode::Rich);
    expect($second->written)->toBeTrue();
    expect(File::get($path))->toBe("# Second\n");
});

// --- Revision 3: editable frontmatter (save) -----------------------------

test('a Rich save adds a frontmatter block via FrontmatterEdit', function () {
    $note = $this->service->create($this->vault, null, 'a');
    $path = $this->vault->path.DIRECTORY_SEPARATOR.'a.md';
    File::put($path, "# H\n");
    $note = $note->fresh();
    $baseHash = hash('sha256', "# H\n");

    $result = $this->service->save($note, "# H\n", $baseHash, NoteSaveMode::Rich, new FrontmatterEdit(true, 'a: 1'));

    expect(File::get($path))->toBe("---\na: 1\n---\n\n# H\n");
    expect($result->written)->toBeTrue();
    expect($result->fileHash)->toBe(hash_file('sha256', $path));
    expect($note->fresh()->file_hash)->toBe($result->fileHash);

    $preview = $this->service->preview($note->fresh());
    expect($preview['frontmatter_yaml'])->toBe('a: 1');
    expect($preview['body'])->toBe("# H\n");
});

test('a Rich save changes an existing frontmatter value', function () {
    $note = $this->service->create($this->vault, null, 'a');
    $path = $this->vault->path.DIRECTORY_SEPARATOR.'a.md';
    File::put($path, "---\ntitle: X\n---\n\n# H\n");
    $note = $note->fresh();
    $baseHash = hash('sha256', "---\ntitle: X\n---\n\n# H\n");

    $result = $this->service->save($note, "# H\n", $baseHash, NoteSaveMode::Rich, new FrontmatterEdit(true, 'title: Y'));

    expect(File::get($path))->toBe("---\ntitle: Y\n---\n\n# H\n");
    expect($result->written)->toBeTrue();

    $preview = $this->service->preview($note->fresh());
    expect($preview['frontmatter_yaml'])->toBe('title: Y');
    expect($preview['body'])->toBe("# H\n");
});

test('a Rich save removes the frontmatter block', function () {
    $note = $this->service->create($this->vault, null, 'a');
    $path = $this->vault->path.DIRECTORY_SEPARATOR.'a.md';
    File::put($path, "---\ntitle: X\n---\n\n# H\n");
    $note = $note->fresh();
    $baseHash = hash('sha256', "---\ntitle: X\n---\n\n# H\n");

    $result = $this->service->save($note, "# H\n", $baseHash, NoteSaveMode::Rich, new FrontmatterEdit(false, ''));

    expect(File::get($path))->toBe("# H\n");
    expect($result->written)->toBeTrue();

    $preview = $this->service->preview($note->fresh());
    expect($preview['frontmatter_yaml'])->toBeNull();
    expect($preview['body'])->toBe("# H\n");
});

test('a CRLF+BOM note with a frontmatter change is preserved', function () {
    $note = $this->service->create($this->vault, null, 'a');
    $path = $this->vault->path.DIRECTORY_SEPARATOR.'a.md';
    $original = MarkdownService::BOM."---\r\ntitle: X\r\n---\r\n\r\n# H\r\n";
    File::put($path, $original);
    $note = $note->fresh();
    $baseHash = hash('sha256', $original);

    $this->service->save($note, "# H\n", $baseHash, NoteSaveMode::Rich, new FrontmatterEdit(true, 'title: Y'));

    $bytes = File::get($path);
    expect($bytes)->toStartWith(MarkdownService::BOM);
    expect(substr_count($bytes, "\n"))->toBe(substr_count($bytes, "\r\n"));
    expect($bytes)->toBe(MarkdownService::BOM."---\r\ntitle: Y\r\n---\r\n\r\n# H\r\n");
});

test('an invalid --- line in the frontmatter edit throws field frontmatter and leaves the file and DB unchanged', function () {
    $note = $this->service->create($this->vault, null, 'a');
    $path = $this->vault->path.DIRECTORY_SEPARATOR.'a.md';
    File::put($path, "---\ntitle: X\n---\n\n# H\n");
    $note = $note->fresh();
    $baseHash = hash('sha256', "---\ntitle: X\n---\n\n# H\n");
    $hashBefore = $note->file_hash;

    $field = noteOperationField(fn () => $this->service->save(
        $note,
        "# H\n",
        $baseHash,
        NoteSaveMode::Rich,
        new FrontmatterEdit(true, "a\n---\nb"),
    ));

    expect($field)->toBe('frontmatter');
    expect(File::get($path))->toBe("---\ntitle: X\n---\n\n# H\n");
    expect($note->fresh()->file_hash)->toBe($hashBefore);
});

test('an unchanged frontmatter and body is a no-op that never attempts a replace', function () {
    $note = $this->service->create($this->vault, null, 'a');
    $path = $this->vault->path.DIRECTORY_SEPARATOR.'a.md';
    File::put($path, "---\ntitle: X\n---\n\n# H\n");
    $note = $note->fresh();
    $baseHash = hash('sha256', "---\ntitle: X\n---\n\n# H\n");

    $fake = failFileMoves([1, 2, 3]);
    $service = app(NoteService::class);

    $result = $service->save($note, "# H\n", $baseHash, NoteSaveMode::Rich, new FrontmatterEdit(true, 'title: X'));

    expect($result->written)->toBeFalse();
    expect($fake->calls)->toBe(0);
    expect(File::get($path))->toBe("---\ntitle: X\n---\n\n# H\n");
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
