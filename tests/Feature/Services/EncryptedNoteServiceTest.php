<?php

use App\Enums\IndexMode;
use App\Enums\NoteSaveMode;
use App\Enums\SettingKey;
use App\Exceptions\NoteOperationException;
use App\Exceptions\NoteSaveConflictException;
use App\Exceptions\VaultLockedException;
use App\Models\Note;
use App\Services\EncryptedNoteService;
use App\Services\EncryptionService;
use App\Services\ExternalChangeService;
use App\Services\NoteService;
use App\Services\SettingsService;
use App\Services\VaultIndexService;
use App\Services\VaultKeyService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

const CANARY = 'CANARY-CONTENT-7f3a';

beforeEach(function () {
    $this->tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mdvault-encnotes-'.Str::random(8);
    fakeDocumentsDirectory($this->tmp.DIRECTORY_SEPARATOR.'Documents');

    [$this->vault, $this->token] = encryptedVault('Secrets');
    $this->notes = app(NoteService::class);
    $this->keys = app(VaultKeyService::class);
    $this->header = $this->vault->uuid.':'.$this->token;

    // Saves $content into $note through the real save path.
    $this->write = function (Note $note, string $content): void {
        $preview = $this->notes->preview($note);
        $this->notes->save($note, $content, $preview['base_hash'], NoteSaveMode::Source);
    };
});

afterEach(function () {
    File::deleteDirectory($this->tmp);
});

test('a created note holds only ciphertext under an opaque name', function () {
    $folder = $this->notes->createFolder($this->vault, null, 'My Credentials');
    $note = $this->notes->create($this->vault, $folder, 'Bank Accounts');
    ($this->write)($note, CANARY);

    expect($folder)->toBe('My Credentials')
        ->and($note->relative_path)->toMatch('/^[0-9a-f]{32}\/[0-9a-f]{32}\.mdenc$/')
        ->and($note->is_encrypted)->toBeTrue()
        ->and($note->title)->not->toContain('Bank');

    assertNoNeedlesUnder($this->vault->path, ['Bank Accounts', 'My Credentials', CANARY, 'Bank', 'Credentials']);
    assertNoNeedlesInDatabase(['Bank Accounts', 'My Credentials', CANARY, 'Credentials']);

    $preview = $this->notes->preview($note);
    expect($preview['state'])->toBe('ok')
        ->and($preview['content'])->toBe(CANARY)
        ->and($preview['base_hash'])->toBe($note->fresh()->file_hash);
});

test('the tree, folders and signature show decrypted names', function () {
    $this->notes->createFolder($this->vault, null, 'Projects');
    $this->notes->createFolder($this->vault, 'Projects', 'HRMIS');
    $top = $this->notes->create($this->vault, null, 'Inbox');
    $deep = $this->notes->create($this->vault, 'Projects/HRMIS', 'Plan');

    $presented = $this->notes->present($deep);
    $tree = app(EncryptedNoteService::class)->browse($this->vault, $deep);

    expect($tree['folders'])->toBe(['', 'Projects', 'Projects/HRMIS'])
        ->and($tree['tree'][0]['type'])->toBe('folder')
        ->and($tree['tree'][0]['name'])->toBe('Projects')
        ->and($tree['tree'][0]['open'])->toBeTrue()
        ->and($tree['tree'][0]['children'][0]['path'])->toBe('Projects/HRMIS')
        ->and($tree['tree'][0]['children'][0]['children'][0]['title'])->toBe('Plan')
        ->and($tree['tree'][0]['children'][0]['children'][0]['path'])->toBe('Projects/HRMIS/Plan.md')
        ->and($tree['tree'][1]['uuid'])->toBe($top->uuid)
        ->and($tree['tree'][1]['title'])->toBe('Inbox')
        ->and($tree['signature'])->toBeString()
        ->and($presented['title'])->toBe('Plan')
        ->and($presented['filename'])->toBe('Plan.md')
        ->and($presented['relative_path'])->toBe('Projects/HRMIS/Plan.md')
        ->and($presented['folder'])->toBe('Projects/HRMIS');
});

test('the tree signature matches the one a key-less reconcile computes', function () {
    $this->notes->createFolder($this->vault, null, 'Projects');
    $this->notes->create($this->vault, 'Projects', 'Plan');

    $signature = app(EncryptedNoteService::class)->browse($this->vault)['signature'];
    $this->keys->forgetAll();

    expect(app(VaultIndexService::class)->reconcile($this->vault, IndexMode::Full)->treeSignature)->toBe($signature);
});

test('rename keeps the uuid, rewrites the ciphertext and changes the tree signature', function () {
    $note = $this->notes->create($this->vault, null, 'Old Name');
    ($this->write)($note, CANARY);
    $uuid = $note->uuid;
    $path = $note->relative_path;
    $hashBefore = $note->fresh()->file_hash;
    $signatureBefore = app(EncryptedNoteService::class)->browse($this->vault)['signature'];

    $renamed = $this->notes->rename($note->fresh(), 'Brand New Name');

    expect($renamed->uuid)->toBe($uuid)
        ->and($renamed->relative_path)->toBe($path)
        ->and($renamed->fresh()->file_hash)->not->toBe($hashBefore)
        ->and($this->notes->present($renamed->fresh())['title'])->toBe('Brand New Name')
        ->and($this->notes->preview($renamed->fresh())['content'])->toBe(CANARY)
        ->and(app(EncryptedNoteService::class)->browse($this->vault)['signature'])->not->toBe($signatureBefore);

    assertNoNeedlesUnder($this->vault->path, ['Old Name', 'Brand New Name', CANARY]);
    assertNoNeedlesInDatabase(['Old Name', 'Brand New Name']);
});

test('renaming to the same name changes nothing', function () {
    $note = $this->notes->create($this->vault, null, 'Same');
    $hash = $note->fresh()->file_hash;

    $this->notes->rename($note, 'Same.md');

    expect($note->fresh()->file_hash)->toBe($hash);
});

test('move keeps the uuid and content and only renames the file', function () {
    $this->notes->createFolder($this->vault, null, 'Archive');
    $note = $this->notes->create($this->vault, null, 'Moving');
    ($this->write)($note, CANARY);
    $uuid = $note->uuid;
    $hash = $note->fresh()->file_hash;

    $moved = $this->notes->move($note->fresh(), 'Archive');

    expect($moved->uuid)->toBe($uuid)
        ->and($moved->relative_path)->toMatch('/^[0-9a-f]{32}\/[0-9a-f]{32}\.mdenc$/')
        ->and($moved->fresh()->file_hash)->toBe($hash)
        ->and($this->notes->present($moved->fresh())['relative_path'])->toBe('Archive/Moving.md')
        ->and($this->notes->preview($moved->fresh())['content'])->toBe(CANARY);

    $back = $this->notes->move($moved->fresh(), null);
    expect($back->uuid)->toBe($uuid)
        ->and($this->notes->present($back->fresh())['relative_path'])->toBe('Moving.md');

    assertNoNeedlesUnder($this->vault->path, ['Archive', 'Moving', CANARY]);
});

test('delete moves the ciphertext to the trash and removes the record', function () {
    $trash = fakeTrash();
    // The scoped service captured the previous Trash when it was resolved.
    app()->forgetInstance(EncryptedNoteService::class);
    $notes = app(NoteService::class);
    $note = $notes->create($this->vault, null, 'Doomed');
    $absolute = $this->vault->path.DIRECTORY_SEPARATOR.$note->relative_path;

    expect($notes->delete($note))->toBeTrue()
        ->and(File::exists($absolute))->toBeFalse()
        ->and(Note::query()->where('uuid', $note->uuid)->exists())->toBeFalse()
        ->and($trash->trashed)->toHaveCount(1);
});

test('delete of an already missing file removes the record', function () {
    $note = $this->notes->create($this->vault, null, 'Gone');
    File::delete($this->vault->path.DIRECTORY_SEPARATOR.$note->relative_path);

    expect($this->notes->delete($note))->toBeFalse()
        ->and(Note::query()->where('uuid', $note->uuid)->exists())->toBeFalse();
});

test('folders are created with an encrypted name file and deleted when empty', function () {
    $path = $this->notes->createFolder($this->vault, null, 'Empty One');

    $entries = array_map('basename', File::directories($this->vault->path));
    expect($entries)->toHaveCount(1)
        ->and($entries[0])->toMatch('/^[0-9a-f]{32}$/')
        ->and(File::files($this->vault->path.DIRECTORY_SEPARATOR.$entries[0]))->toHaveCount(1);

    assertNoNeedlesUnder($this->vault->path, ['Empty One']);

    $this->notes->deleteFolder($this->vault, $path);

    expect(File::directories($this->vault->path))->toBe([]);
});

test('delete folder refuses a folder that holds a note or a sub-folder', function () {
    $this->notes->createFolder($this->vault, null, 'Full');
    $this->notes->createFolder($this->vault, 'Full', 'Inner');
    $this->notes->create($this->vault, 'Full/Inner', 'Note');

    expect(fn () => $this->notes->deleteFolder($this->vault, 'Full'))->toThrow(NoteOperationException::class);
    expect(fn () => $this->notes->deleteFolder($this->vault, 'Full/Inner'))->toThrow(NoteOperationException::class);
});

test('delete folder refuses a folder that holds another file and keeps its name file', function () {
    $this->notes->createFolder($this->vault, null, 'Other');
    $dir = File::directories($this->vault->path)[0];
    File::put($dir.DIRECTORY_SEPARATOR.'stray.txt', 'x');

    expect(fn () => $this->notes->deleteFolder($this->vault, 'Other'))->toThrow(NoteOperationException::class);
    expect(File::exists($dir.DIRECTORY_SEPARATOR.'folder.mdenc'))->toBeTrue();
});

test('the vault root cannot be deleted and unknown folders are refused', function () {
    expect(noteOperationField(fn () => $this->notes->deleteFolder($this->vault, '')))->toBe('path');
    expect(noteOperationField(fn () => $this->notes->deleteFolder($this->vault, 'Nope')))->toBe('path');
    expect(noteOperationField(fn () => $this->notes->create($this->vault, 'Nope', 'X')))->toBe('folder');
    expect(noteOperationField(fn () => $this->notes->createFolder($this->vault, 'Nope', 'X')))->toBe('parent');
    expect(noteOperationField(fn () => $this->notes->create($this->vault, '../x', 'X')))->toBe('folder');
});

test('names conflict case-insensitively', function () {
    $this->notes->createFolder($this->vault, null, 'Docs');
    $first = $this->notes->create($this->vault, null, 'Bank');
    $second = $this->notes->create($this->vault, null, 'Other');

    expect(noteOperationField(fn () => $this->notes->create($this->vault, null, 'bank')))->toBe('name');
    expect(noteOperationField(fn () => $this->notes->rename($second, 'BANK')))->toBe('name');
    expect(noteOperationField(fn () => $this->notes->createFolder($this->vault, null, 'DOCS')))->toBe('name');

    // The same name in another folder is fine, and moving into a clash is refused.
    $inDocs = $this->notes->create($this->vault, 'Docs', 'Bank');
    expect(noteOperationField(fn () => $this->notes->move($inDocs, null)))->toBe('folder');
    expect($first->uuid)->not->toBe($inDocs->uuid);
});

test('invalid names are refused', function () {
    foreach (['.hidden', 'a/b', 'node_modules', str_repeat("\u{1F600}", 70)] as $bad) {
        expect(fn () => $this->notes->create($this->vault, null, $bad))->toThrow(NoteOperationException::class);
    }
});

test('every operation needs the vault to be unlocked', function () {
    $note = $this->notes->create($this->vault, null, 'Locked Note');
    $this->notes->createFolder($this->vault, null, 'Locked Folder');

    $this->keys->forgetAll();
    $note = Note::query()->where('uuid', $note->uuid)->first();

    $calls = [
        'create' => fn () => $this->notes->create($this->vault, null, 'X'),
        'createCopy' => fn () => $this->notes->createCopy($this->vault, 'Locked Note.md', 'x', NoteSaveMode::Source, null),
        'rename' => fn () => $this->notes->rename($note, 'X'),
        'move' => fn () => $this->notes->move($note, null),
        'delete' => fn () => $this->notes->delete($note),
        'createFolder' => fn () => $this->notes->createFolder($this->vault, null, 'X'),
        'deleteFolder' => fn () => $this->notes->deleteFolder($this->vault, 'Locked Folder'),
        'preview' => fn () => $this->notes->preview($note),
        'save' => fn () => $this->notes->save($note, 'x', 'hash', NoteSaveMode::Source),
        'present' => fn () => $this->notes->present($note),
        'browse' => fn () => app(EncryptedNoteService::class)->browse($this->vault),
    ];

    foreach ($calls as $call) {
        expect($call)->toThrow(VaultLockedException::class);
    }
});

test('a save is guarded by the ciphertext hash', function () {
    $note = $this->notes->create($this->vault, null, 'Guarded');
    $preview = $this->notes->preview($note);

    $this->notes->save($note, 'first', $preview['base_hash'], NoteSaveMode::Source);

    // The base hash the client holds is now stale.
    expect(fn () => $this->notes->save($note->fresh(), 'second', $preview['base_hash'], NoteSaveMode::Source))
        ->toThrow(NoteSaveConflictException::class);

    // An external modification between load and save is a conflict, not an overwrite.
    $fresh = $this->notes->preview($note->fresh());
    $absolute = $this->vault->path.DIRECTORY_SEPARATOR.$note->relative_path;
    File::put($absolute, 'externally changed bytes');

    try {
        $this->notes->save($note->fresh(), 'mine', $fresh['base_hash'], NoteSaveMode::Source);
        $this->fail('Expected a conflict.');
    } catch (NoteSaveConflictException $e) {
        expect($e->reason())->toBe('changed')
            ->and($e->currentHash())->toBe(hash('sha256', 'externally changed bytes'))
            ->and($e->getMessage())->not->toContain('Guarded');
    }

    expect(File::get($absolute))->toBe('externally changed bytes');
});

test('a save of a deleted file is a missing conflict', function () {
    $note = $this->notes->create($this->vault, null, 'Vanishing');
    $preview = $this->notes->preview($note);
    File::delete($this->vault->path.DIRECTORY_SEPARATOR.$note->relative_path);

    try {
        $this->notes->save($note, 'x', $preview['base_hash'], NoteSaveMode::Source);
        $this->fail('Expected a conflict.');
    } catch (NoteSaveConflictException $e) {
        expect($e->reason())->toBe('missing');
    }
});

test('a no-op save writes nothing', function () {
    $note = $this->notes->create($this->vault, null, 'Quiet');
    ($this->write)($note, 'unchanged text');

    $absolute = $this->vault->path.DIRECTORY_SEPARATOR.$note->relative_path;
    $bytes = File::get($absolute);
    $preview = $this->notes->preview($note->fresh());

    $result = $this->notes->save($note->fresh(), 'unchanged text', $preview['base_hash'], NoteSaveMode::Source);

    expect($result->written)->toBeFalse()
        ->and($result->fileHash)->toBe(hash('sha256', $bytes))
        ->and(File::get($absolute))->toBe($bytes);
});

test('a real save stores new ciphertext and updates the registry hash', function () {
    $note = $this->notes->create($this->vault, null, 'Changing');
    $preview = $this->notes->preview($note);

    $result = $this->notes->save($note, 'new text '.CANARY, $preview['base_hash'], NoteSaveMode::Source);
    $absolute = $this->vault->path.DIRECTORY_SEPARATOR.$note->relative_path;

    expect($result->written)->toBeTrue()
        ->and($result->fileHash)->toBe(hash('sha256', File::get($absolute)))
        ->and($note->fresh()->file_hash)->toBe($result->fileHash)
        ->and($this->notes->preview($note->fresh())['content'])->toBe('new text '.CANARY);

    assertNoNeedlesUnder($this->vault->path, [CANARY, 'Changing']);
});

test('a BOM survives a save', function () {
    $note = $this->notes->create($this->vault, null, 'Windows');
    $absolute = $this->vault->path.DIRECTORY_SEPARATOR.$note->relative_path;
    $encryption = app(EncryptionService::class);
    $key = $this->keys->requireKey($this->vault);
    $fileId = basename($note->relative_path, '.mdenc');

    // Put BOM and CRLF content in the way an import would: by encrypting directly.
    File::put($absolute, $encryption->encryptNote($key, $fileId, 'Windows', "\xEF\xBB\xBFline one\r\nline two\r\n"));

    $this->notes->save($note->fresh(), "new one\nnew two", hash('sha256', File::get($absolute)), NoteSaveMode::Source);

    expect($encryption->decryptNote($key, $fileId, File::get($absolute))['content'])->toBe("\xEF\xBB\xBFnew one\r\nnew two");
});

test('a tampered note is unreadable and the tree still renders', function () {
    $this->notes->create($this->vault, null, 'Good');
    $bad = $this->notes->create($this->vault, null, 'Bad');
    $absolute = $this->vault->path.DIRECTORY_SEPARATOR.$bad->relative_path;
    $bytes = File::get($absolute);
    File::put($absolute, substr($bytes, 0, -1).chr(ord($bytes[strlen($bytes) - 1]) ^ 1));

    $bad = $bad->fresh();
    $preview = $this->notes->preview($bad);
    $titles = array_column(app(EncryptedNoteService::class)->browse($this->vault)['tree'], 'title');
    $unreadable = 'Unreadable note ('.substr(basename($bad->relative_path, '.mdenc'), 0, 8).')';

    expect($preview['state'])->toBe('unreadable')
        ->and($preview['content'])->toBeNull()
        ->and($titles)->toContain('Good')
        ->and($titles)->toContain($unreadable)
        ->and($this->notes->present($bad->fresh())['title'])->toBe($unreadable);

    expect(fn () => $this->notes->rename($bad->fresh(), 'Renamed'))->toThrow(NoteOperationException::class);
    expect(fn () => $this->notes->save($bad->fresh(), 'x', hash('sha256', File::get($absolute)), NoteSaveMode::Source))->toThrow(NoteOperationException::class);
});

test('a folder without a name file is shown as unnamed', function () {
    $folderId = bin2hex(random_bytes(16));
    File::makeDirectory($this->vault->path.DIRECTORY_SEPARATOR.$folderId);

    $tree = app(EncryptedNoteService::class)->browse($this->vault);
    $unnamed = 'Unnamed folder ('.substr($folderId, 0, 8).')';

    expect($tree['tree'][0]['name'])->toBe($unnamed)
        ->and($tree['folders'])->toBe(['', $unnamed]);
});

test('a name file copied from another folder is rejected', function () {
    $this->notes->createFolder($this->vault, null, 'One');
    $this->notes->createFolder($this->vault, null, 'Two');
    [$a, $b] = File::directories($this->vault->path);

    File::put($a.DIRECTORY_SEPARATOR.'folder.mdenc', File::get($b.DIRECTORY_SEPARATOR.'folder.mdenc'));

    $names = array_column(app(EncryptedNoteService::class)->browse($this->vault)['tree'], 'name');

    expect(collect($names)->filter(fn (string $n): bool => str_starts_with($n, 'Unnamed folder ('))->count())->toBe(1);
});

test('createCopy names a copy with (my version) and never overwrites', function () {
    $this->notes->createFolder($this->vault, null, 'Docs');
    $source = $this->notes->create($this->vault, 'Docs', 'Plan');
    ($this->write)($source, 'original');

    $copy = $this->notes->createCopy($this->vault, 'Docs/Plan.md', 'mine', NoteSaveMode::Source, null);
    $second = $this->notes->createCopy($this->vault, 'Docs/Plan.md', 'mine again', NoteSaveMode::Source, null);

    expect($this->notes->present($copy)['relative_path'])->toBe('Docs/Plan (my version).md')
        ->and($this->notes->present($second)['relative_path'])->toBe('Docs/Plan (my version 2).md')
        ->and($this->notes->preview($copy)['content'])->toBe('mine')
        ->and($this->notes->preview($source->fresh())['content'])->toBe('original');

    // The source is gone: the copy is recreated at its own place.
    File::delete($this->vault->path.DIRECTORY_SEPARATOR.$source->relative_path);
    $source->delete();
    $recreated = $this->notes->createCopy($this->vault, 'Docs/Plan.md', 'recreated', NoteSaveMode::Source, null);

    expect($this->notes->present($recreated)['relative_path'])->toBe('Docs/Plan.md');
});

test('createCopy puts a copy at the top level when the source folder is gone', function () {
    $copy = $this->notes->createCopy($this->vault, 'Gone/Plan.md', 'mine', NoteSaveMode::Source, null);

    expect($this->notes->present($copy)['relative_path'])->toBe('Plan.md');
});

test('a registry failure while creating removes the new file again', function () {
    DB::unprepared('create trigger fail_note_insert before insert on notes begin select raise(abort, "boom"); end');

    try {
        expect(fn () => $this->notes->create($this->vault, null, 'Failing'))->toThrow(QueryException::class);
    } finally {
        DB::unprepared('drop trigger fail_note_insert');
    }

    // Only the key file remains.
    expect(File::files($this->vault->path))->toHaveCount(1);
});

test('a failed move rolls the file back', function () {
    $this->notes->createFolder($this->vault, null, 'Target');
    $note = $this->notes->create($this->vault, null, 'Stays');
    $original = $note->relative_path;

    DB::unprepared('create trigger fail_note_update before update on notes begin select raise(abort, "boom"); end');

    try {
        expect(fn () => $this->notes->move($note, 'Target'))->toThrow(QueryException::class);
    } finally {
        DB::unprepared('drop trigger fail_note_update');
    }

    expect(File::exists($this->vault->path.DIRECTORY_SEPARATOR.$original))->toBeTrue()
        ->and($note->fresh()->relative_path)->toBe($original);
});

test('nothing in the logs ever contains a name or content', function () {
    $logs = captureLogs();

    $this->notes->createFolder($this->vault, null, 'My Credentials');
    $note = $this->notes->create($this->vault, 'My Credentials', 'Bank Accounts');
    ($this->write)($note, CANARY);
    $note = $this->notes->rename($note->fresh(), 'Bank Secrets');

    DB::unprepared('create trigger fail_note_update2 before update on notes begin select raise(abort, "boom"); end');

    try {
        $this->notes->move($note->fresh(), null);
    } catch (Throwable) {
        // Expected: the move is rolled back.
    } finally {
        DB::unprepared('drop trigger fail_note_update2');
    }

    try {
        $this->notes->create($this->vault, 'Nope Folder', 'Missing Parent');
    } catch (NoteOperationException $e) {
        expect($e->getMessage())->not->toContain('Nope Folder')->not->toContain('Missing Parent');
    }

    $joined = $logs->implode("\n");

    foreach (['My Credentials', 'Bank Accounts', 'Bank Secrets', CANARY, 'Nope Folder'] as $needle) {
        expect($joined)->not->toContain($needle);
    }
});

test('exception messages never contain names or paths', function () {
    $this->notes->createFolder($this->vault, null, 'Docs');
    $note = $this->notes->create($this->vault, 'Docs', 'Taken');
    $messages = [];

    foreach ([
        fn () => $this->notes->create($this->vault, 'Docs', 'Taken'),
        fn () => $this->notes->create($this->vault, 'Missing Folder', 'X'),
        fn () => $this->notes->deleteFolder($this->vault, 'Docs'),
        fn () => $this->notes->move($note, 'Missing Folder'),
    ] as $call) {
        try {
            $call();
        } catch (NoteOperationException $e) {
            $messages[] = $e->getMessage();
        }
    }

    expect($messages)->toHaveCount(4);

    foreach ($messages as $message) {
        expect($message)->not->toContain('Taken')->not->toContain('Docs')->not->toContain('Missing Folder')->not->toContain($note->relative_path);
    }
});

test('the open note state reports the logical path only while unlocked', function () {
    $this->notes->createFolder($this->vault, null, 'Docs');
    $note = $this->notes->create($this->vault, 'Docs', 'Open Me');
    app(SettingsService::class)->set(SettingKey::CurrentVault, $this->vault->uuid);

    $service = app(ExternalChangeService::class);

    $state = $service->check($this->vault, $note->uuid);
    expect($state['open_note']['relative_path'])->toBe('Docs/Open Me.md');

    $this->keys->forgetAll();
    $state = $service->check($this->vault, $note->uuid);

    expect($state['status'])->toBe('ok')
        ->and($state['open_note']['relative_path'])->toBeNull()
        ->and($state['open_note']['exists'])->toBeTrue();
});
