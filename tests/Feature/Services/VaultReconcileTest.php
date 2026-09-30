<?php

use App\Enums\IndexMode;
use App\Models\Note;
use App\Services\VaultIndexService;
use App\Services\VaultService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mdvault-reconcile-'.Str::random(8);
    fakeDocumentsDirectory($this->tmp.DIRECTORY_SEPARATOR.'Documents');
    $this->vault = app(VaultService::class)->create('Work');
    $this->service = app(VaultIndexService::class);
});

afterEach(function () {
    File::deleteDirectory($this->tmp);
});

test('external create is reported as a created change with a UUIDv7', function () {
    $this->service->reconcile($this->vault, IndexMode::Full);

    writeVaultFiles($this->vault->path, ['New.md' => 'hello']);

    $result = $this->service->reconcile($this->vault, IndexMode::Full);

    expect($result->added)->toBe(1);
    expect($result->changes)->toHaveCount(1);
    expect($result->changes[0]['type'])->toBe('created');
    expect($result->changes[0]['path'])->toBe('New.md');

    $note = Note::query()->first();
    expect($result->changes[0]['uuid'])->toBe($note->uuid);
    expect(Str::isUuid($note->uuid))->toBeTrue();
    expect(substr($note->uuid, 14, 1))->toBe('7');
});

test('external modify updates the hash and keeps the UUID', function () {
    writeVaultFiles($this->vault->path, ['a.md' => 'one']);
    $this->service->reconcile($this->vault, IndexMode::Full);
    $uuid = Note::query()->first()->uuid;

    file_put_contents($this->vault->path.DIRECTORY_SEPARATOR.'a.md', 'two-longer');

    $result = $this->service->reconcile($this->vault, IndexMode::Full);

    expect($result->updated)->toBe(1);
    $note = Note::query()->first();
    expect($note->uuid)->toBe($uuid);
    expect($note->file_hash)->toBe(hash_file('sha256', $this->vault->path.DIRECTORY_SEPARATOR.'a.md'));
});

test('external delete removes the record', function () {
    writeVaultFiles($this->vault->path, ['a.md' => 'one']);
    $this->service->reconcile($this->vault, IndexMode::Full);

    unlink($this->vault->path.DIRECTORY_SEPARATOR.'a.md');

    $result = $this->service->reconcile($this->vault, IndexMode::Full);

    expect($result->removed)->toBe(1);
    expect(Note::query()->count())->toBe(0);
});

test('external rename with unchanged content keeps the UUID', function () {
    writeVaultFiles($this->vault->path, ['Old.md' => 'content']);
    $this->service->reconcile($this->vault, IndexMode::Full);
    $uuid = Note::query()->first()->uuid;

    rename($this->vault->path.DIRECTORY_SEPARATOR.'Old.md', $this->vault->path.DIRECTORY_SEPARATOR.'New.md');

    $result = $this->service->reconcile($this->vault, IndexMode::Full);

    expect($result->moved)->toBe(1);
    $note = Note::query()->first();
    expect($note->uuid)->toBe($uuid);
    expect($note->relative_path)->toBe('New.md');
    expect($note->filename)->toBe('New.md');
    expect($note->title)->toBe('New');
});

test('external move to another folder with unchanged content keeps the UUID', function () {
    writeVaultFiles($this->vault->path, ['Projects/HRMIS.md' => 'content']);
    $this->service->reconcile($this->vault, IndexMode::Full);
    $uuid = Note::query()->first()->uuid;

    File::makeDirectory($this->vault->path.DIRECTORY_SEPARATOR.'Archive');
    rename(
        $this->vault->path.DIRECTORY_SEPARATOR.'Projects'.DIRECTORY_SEPARATOR.'HRMIS.md',
        $this->vault->path.DIRECTORY_SEPARATOR.'Archive'.DIRECTORY_SEPARATOR.'HRMIS.md',
    );

    $result = $this->service->reconcile($this->vault, IndexMode::Full);

    expect($result->moved)->toBe(1);
    $note = Note::query()->first();
    expect($note->uuid)->toBe($uuid);
    expect($note->relative_path)->toBe('Archive/HRMIS.md');
});

test('move plus edit with the same file name keeps the UUID and reports content_changed', function () {
    writeVaultFiles($this->vault->path, ['Projects/HRMIS.md' => 'content']);
    $this->service->reconcile($this->vault, IndexMode::Full);
    $uuid = Note::query()->first()->uuid;

    File::makeDirectory($this->vault->path.DIRECTORY_SEPARATOR.'Archive');
    rename(
        $this->vault->path.DIRECTORY_SEPARATOR.'Projects'.DIRECTORY_SEPARATOR.'HRMIS.md',
        $this->vault->path.DIRECTORY_SEPARATOR.'Archive'.DIRECTORY_SEPARATOR.'HRMIS.md',
    );
    file_put_contents($this->vault->path.DIRECTORY_SEPARATOR.'Archive'.DIRECTORY_SEPARATOR.'HRMIS.md', 'edited content');

    $result = $this->service->reconcile($this->vault, IndexMode::Full);

    expect($result->moved)->toBe(1);
    $note = Note::query()->first();
    expect($note->uuid)->toBe($uuid);
    expect($note->relative_path)->toBe('Archive/HRMIS.md');
    expect($note->file_hash)->toBe(hash('sha256', 'edited content'));

    $moveChange = collect($result->changes)->firstWhere('type', 'moved');
    expect($moveChange['content_changed'])->toBeTrue();
    expect($moveChange['from'])->toBe('Projects/HRMIS.md');
});

test('rename plus an edit with a different file name gives a new UUID', function () {
    writeVaultFiles($this->vault->path, ['a.md' => 'one']);
    $this->service->reconcile($this->vault, IndexMode::Full);
    $uuid = Note::query()->first()->uuid;

    rename($this->vault->path.DIRECTORY_SEPARATOR.'a.md', $this->vault->path.DIRECTORY_SEPARATOR.'b.md');
    file_put_contents($this->vault->path.DIRECTORY_SEPARATOR.'b.md', 'two');

    $result = $this->service->reconcile($this->vault, IndexMode::Full);

    expect($result->removed)->toBe(1);
    expect($result->added)->toBe(1);
    expect(Note::query()->first()->uuid)->not->toBe($uuid);
});

test('a folder rename keeps every UUID even with duplicate-hash files (basename step)', function () {
    writeVaultFiles($this->vault->path, [
        'Projects/A.md' => 'alpha',
        'Projects/B.md' => '',
        'Projects/C.md' => '',
    ]);
    $this->service->reconcile($this->vault, IndexMode::Full);
    $uuids = Note::query()->pluck('uuid', 'relative_path')->all();

    rename($this->vault->path.DIRECTORY_SEPARATOR.'Projects', $this->vault->path.DIRECTORY_SEPARATOR.'Work');

    $result = $this->service->reconcile($this->vault, IndexMode::Full);

    expect($result->moved)->toBe(3);
    expect($result->removed)->toBe(0);
    expect($result->added)->toBe(0);

    expect(Note::query()->where('relative_path', 'Work/A.md')->first()->uuid)->toBe($uuids['Projects/A.md']);
    expect(Note::query()->where('relative_path', 'Work/B.md')->first()->uuid)->toBe($uuids['Projects/B.md']);
    expect(Note::query()->where('relative_path', 'Work/C.md')->first()->uuid)->toBe($uuids['Projects/C.md']);
});

test('ambiguous same-name files across folders are not paired', function () {
    writeVaultFiles($this->vault->path, [
        'A/x.md' => 'one',
        'B/x.md' => 'two',
    ]);
    $this->service->reconcile($this->vault, IndexMode::Full);

    unlink($this->vault->path.DIRECTORY_SEPARATOR.'A'.DIRECTORY_SEPARATOR.'x.md');
    unlink($this->vault->path.DIRECTORY_SEPARATOR.'B'.DIRECTORY_SEPARATOR.'x.md');
    File::makeDirectory($this->vault->path.DIRECTORY_SEPARATOR.'C');
    file_put_contents($this->vault->path.DIRECTORY_SEPARATOR.'C'.DIRECTORY_SEPARATOR.'x.md', 'three');

    $result = $this->service->reconcile($this->vault, IndexMode::Full);

    expect($result->removed)->toBe(2);
    expect($result->added)->toBe(1);
    expect($result->moved)->toBe(0);
});

test('a case-only rename keeps the UUID (regression)', function () {
    writeVaultFiles($this->vault->path, ['Readme.md' => 'content']);
    $this->service->reconcile($this->vault, IndexMode::Full);
    $uuid = Note::query()->first()->uuid;

    $from = $this->vault->path.DIRECTORY_SEPARATOR.'Readme.md';
    $tmp = $this->vault->path.DIRECTORY_SEPARATOR.'.tmp-rename';
    rename($from, $tmp);
    rename($tmp, $this->vault->path.DIRECTORY_SEPARATOR.'README.md');

    $result = $this->service->reconcile($this->vault, IndexMode::Full);

    expect($result->moved)->toBe(1);
    expect(Note::query()->first()->uuid)->toBe($uuid);
});

test('quick mode skips hashing an unchanged file and detects a same-size edit only when verified or full', function () {
    $path = $this->vault->path.DIRECTORY_SEPARATOR.'a.md';
    writeVaultFiles($this->vault->path, ['a.md' => 'hello']);
    $this->service->reconcile($this->vault, IndexMode::Full);

    // Make the mtime trusted (older than the racy window).
    touch($path, time() - 100);
    $this->service->reconcile($this->vault, IndexMode::Full);
    $storedMtime = Note::query()->first()->file_mtime;
    expect($storedMtime)->toBe(time() - 100);

    // A same-size edit with the mtime restored to what's stored.
    file_put_contents($path, 'HELLO');
    touch($path, $storedMtime);

    $quick = $this->service->reconcile($this->vault, IndexMode::Quick);
    expect($quick->updated)->toBe(0);
    expect($quick->unchanged)->toBe(1);
    expect(Note::query()->first()->file_hash)->toBe(hash('sha256', 'hello'));

    $verified = $this->service->reconcile($this->vault, IndexMode::Quick, ['a.md']);
    expect($verified->updated)->toBe(1);
    expect(Note::query()->first()->file_hash)->toBe(hash('sha256', 'HELLO'));
});

test('a fresh full reindex detects a same-size mtime-restored edit without verification', function () {
    $path = $this->vault->path.DIRECTORY_SEPARATOR.'a.md';
    writeVaultFiles($this->vault->path, ['a.md' => 'hello']);
    $this->service->reconcile($this->vault, IndexMode::Full);
    touch($path, time() - 100);
    $this->service->reconcile($this->vault, IndexMode::Full);
    $storedMtime = Note::query()->first()->file_mtime;

    file_put_contents($path, 'HELLO');
    touch($path, $storedMtime);

    $result = $this->service->reindex($this->vault);

    expect($result->updated)->toBe(1);
});

test('the racy-mtime rule keeps a just-written file untrusted until it ages', function () {
    $path = $this->vault->path.DIRECTORY_SEPARATOR.'a.md';
    writeVaultFiles($this->vault->path, ['a.md' => 'one']);

    $this->service->reconcile($this->vault, IndexMode::Full);
    expect(Note::query()->first()->file_mtime)->toBeNull();

    $updatedAtBefore = Note::query()->first()->updated_at;

    touch($path, time() - 100);
    $result = $this->service->reconcile($this->vault, IndexMode::Full);

    $note = Note::query()->first();
    expect($note->file_mtime)->toBe(time() - 100);
    expect($result->touched)->toBe(1);
    expect($result->updated)->toBe(0);
    expect($result->hasChanges())->toBeFalse();
    expect($note->updated_at->equalTo($updatedAtBefore))->toBeTrue();
});

test('two consecutive quick reconciles with no changes make no write queries on the second', function () {
    writeVaultFiles($this->vault->path, ['a.md' => 'one', 'b.md' => 'two']);
    $this->service->reconcile($this->vault, IndexMode::Full);

    foreach (['a.md', 'b.md'] as $name) {
        touch($this->vault->path.DIRECTORY_SEPARATOR.$name, time() - 100);
    }
    $this->service->reconcile($this->vault, IndexMode::Full);

    // First quick pass establishes a clean baseline.
    $this->service->reconcile($this->vault, IndexMode::Quick);

    $writes = 0;
    $listener = function ($query) use (&$writes): void {
        if (preg_match('/^\s*(insert|update|delete)\b/i', $query->sql) === 1) {
            $writes++;
        }
    };
    DB::listen($listener);

    $result = $this->service->reconcile($this->vault, IndexMode::Quick);

    expect($writes)->toBe(0);
    expect($result->hasChanges())->toBeFalse();
});

test('apply aborts as stale when the registry changed since plan, and every other row is unchanged', function () {
    writeVaultFiles($this->vault->path, ['a.md' => 'one', 'b.md' => 'two']);
    $this->service->reconcile($this->vault, IndexMode::Full);
    $bHashBefore = Note::query()->where('relative_path', 'b.md')->first()->file_hash;

    file_put_contents($this->vault->path.DIRECTORY_SEPARATOR.'a.md', 'one-changed');
    $plan = $this->service->plan($this->vault, IndexMode::Full);

    Note::query()->where('relative_path', 'a.md')->first()->update(['file_hash' => str_repeat('0', 64)]);

    $result = $this->service->apply($this->vault, $plan);

    expect($result->stale)->toBeTrue();
    expect($result->hasChanges())->toBeFalse();
    expect(Note::query()->where('relative_path', 'a.md')->first()->file_hash)->toBe(str_repeat('0', 64));
    expect(Note::query()->where('relative_path', 'b.md')->first()->file_hash)->toBe($bHashBefore);
});

test('apply aborts as stale when a row was inserted since plan', function () {
    writeVaultFiles($this->vault->path, ['a.md' => 'one']);
    $this->service->reconcile($this->vault, IndexMode::Full);

    $plan = $this->service->plan($this->vault, IndexMode::Full);

    Note::factory()->for($this->vault)->create([
        'relative_path' => 'concurrent.md',
        'filename' => 'concurrent.md',
        'file_hash' => hash('sha256', 'x'),
        'file_size' => 1,
    ]);

    $result = $this->service->apply($this->vault, $plan);

    expect($result->stale)->toBeTrue();
});

test('an unreadable file keeps its record and is reported as skipped', function () {
    writeVaultFiles($this->vault->path, ['a.md' => 'one']);
    $this->service->reconcile($this->vault, IndexMode::Full);
    $uuid = Note::query()->first()->uuid;

    $path = $this->vault->path.DIRECTORY_SEPARATOR.'a.md';
    chmod($path, 0000);

    try {
        $result = $this->service->reconcile($this->vault, IndexMode::Full);

        expect($result->skipped)->toBe(1);
        expect(Note::query()->count())->toBe(1);
        expect(Note::query()->first()->uuid)->toBe($uuid);
    } finally {
        chmod($path, 0644);
    }
})->skipOnWindows();

test('orphan temp files older than the threshold are reported but never indexed or deleted', function () {
    File::ensureDirectoryExists($this->vault->path);
    $old = $this->vault->path.DIRECTORY_SEPARATOR.'.mdvault-save-abc123';
    $recent = $this->vault->path.DIRECTORY_SEPARATOR.'.mdvault-save-recent';
    File::put($old, 'stale edit');
    File::put($recent, 'in progress');
    touch($old, time() - 120);

    $result = $this->service->reconcile($this->vault, IndexMode::Full);

    expect($result->orphanTempFiles)->toBe(['.mdvault-save-abc123']);
    expect(Note::query()->count())->toBe(0);
    expect(File::exists($old))->toBeTrue();
    expect(File::exists($recent))->toBeTrue();
});

test('a missing vault root throws with field vault and changes nothing', function () {
    writeVaultFiles($this->vault->path, ['a.md' => 'x']);
    $this->service->reconcile($this->vault, IndexMode::Full);
    $countBefore = Note::query()->count();

    File::deleteDirectory($this->vault->path);

    expect(noteOperationField(fn () => $this->service->reconcile($this->vault, IndexMode::Full)))->toBe('vault');
    expect(noteOperationField(fn () => $this->service->plan($this->vault, IndexMode::Full)))->toBe('vault');

    expect(Note::query()->count())->toBe($countBefore);
});

test('the tree signature is stable when nothing changed and matches browse', function () {
    writeVaultFiles($this->vault->path, [
        'Readme.md' => 'root',
        'Projects/HRMIS.md' => 'content',
    ]);
    $result = $this->service->reconcile($this->vault, IndexMode::Full);

    $browsed = $this->service->browse($this->vault);
    expect($browsed['signature'])->toBe($result->treeSignature);

    $again = $this->service->reconcile($this->vault, IndexMode::Quick);
    expect($again->treeSignature)->toBe($result->treeSignature);
});

test('the tree signature changes after a folder is created or deleted, and after a rename', function () {
    writeVaultFiles($this->vault->path, ['Readme.md' => 'root']);
    $base = $this->service->reconcile($this->vault, IndexMode::Full);

    File::makeDirectory($this->vault->path.DIRECTORY_SEPARATOR.'Empty');
    $withFolder = $this->service->reconcile($this->vault, IndexMode::Full);
    expect($withFolder->treeSignature)->not->toBe($base->treeSignature);

    File::deleteDirectory($this->vault->path.DIRECTORY_SEPARATOR.'Empty');
    $folderGone = $this->service->reconcile($this->vault, IndexMode::Full);
    expect($folderGone->treeSignature)->toBe($base->treeSignature);

    rename(
        $this->vault->path.DIRECTORY_SEPARATOR.'Readme.md',
        $this->vault->path.DIRECTORY_SEPARATOR.'Renamed.md',
    );
    $renamed = $this->service->reconcile($this->vault, IndexMode::Full);
    expect($renamed->treeSignature)->not->toBe($base->treeSignature);
});

test('the tree signature does not change after a content-only modify', function () {
    writeVaultFiles($this->vault->path, ['Readme.md' => 'root']);
    $before = $this->service->reconcile($this->vault, IndexMode::Full);

    file_put_contents($this->vault->path.DIRECTORY_SEPARATOR.'Readme.md', 'a completely different body');
    $after = $this->service->reconcile($this->vault, IndexMode::Full);

    expect($after->treeSignature)->toBe($before->treeSignature);
});

test('ignored names are never indexed', function () {
    writeVaultFiles($this->vault->path, [
        '.git/x.md' => 'x',
        'node_modules/x.md' => 'x',
        '.mdvault-rename-abc' => 'x',
        '.mdvault-save-abc' => 'x',
    ]);

    $result = $this->service->reconcile($this->vault, IndexMode::Full);

    expect($result->added)->toBe(0);
    expect(Note::query()->count())->toBe(0);
});
