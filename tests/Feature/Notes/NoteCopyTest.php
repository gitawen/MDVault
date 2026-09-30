<?php

use App\Enums\IndexMode;
use App\Enums\NoteSaveMode;
use App\Models\Note;
use App\Services\NoteService;
use App\Services\VaultIndexService;
use App\Services\VaultService;
use App\Support\FrontmatterEdit;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mdvault-notecopy-'.Str::random(8);
    fakeDocumentsDirectory($this->tmp.DIRECTORY_SEPARATOR.'Documents');
    $this->vault = app(VaultService::class)->create('Work');
    app(VaultService::class)->open($this->vault);
});

afterEach(function () {
    File::deleteDirectory($this->tmp);
});

test('a changed-conflict copy is written next to the original with the request body and frontmatter, and the original is untouched', function () {
    writeVaultFiles($this->vault->path, ['Projects/X.md' => "Original content\n"]);
    app(VaultIndexService::class)->reconcile($this->vault, IndexMode::Full);
    $originalUuid = Note::query()->first()->uuid;

    file_put_contents($this->vault->path.DIRECTORY_SEPARATOR.'Projects'.DIRECTORY_SEPARATOR.'X.md', "Edited externally\n");

    $response = $this->postJson(route('vaults.notes.copy', $this->vault->uuid), [
        'source_path' => 'Projects/X.md',
        'content' => 'My new body',
        'mode' => 'rich',
        'has_frontmatter' => true,
        'frontmatter' => 'title: mine',
    ]);

    $response->assertCreated();
    $response->assertJson(['relative_path' => 'Projects/X (my version).md']);
    $response->assertJsonMissing(['id']);
    $response->assertJsonMissing(['vault_id']);

    $copyAbs = $this->vault->path.DIRECTORY_SEPARATOR.'Projects'.DIRECTORY_SEPARATOR.'X (my version).md';
    expect(File::exists($copyAbs))->toBeTrue();
    $copied = File::get($copyAbs);
    expect($copied)->toContain('title: mine')->toContain('My new body');

    expect(File::get($this->vault->path.DIRECTORY_SEPARATOR.'Projects'.DIRECTORY_SEPARATOR.'X.md'))->toBe("Edited externally\n");
    expect(Note::query()->where('relative_path', 'Projects/X.md')->first()->uuid)->toBe($originalUuid);
});

test('a second copy for the same source lands at "(my version 2)"', function () {
    writeVaultFiles($this->vault->path, [
        'X.md' => 'original',
        'X (my version).md' => 'taken',
    ]);
    app(VaultIndexService::class)->reconcile($this->vault, IndexMode::Full);

    $response = $this->postJson(route('vaults.notes.copy', $this->vault->uuid), [
        'source_path' => 'X.md',
        'content' => 'mine',
        'mode' => 'source',
    ]);

    $response->assertCreated();
    $response->assertJson(['relative_path' => 'X (my version 2).md']);
});

test('a deleted source is recreated at the original path with a new UUID', function () {
    writeVaultFiles($this->vault->path, ['X.md' => 'original']);
    app(VaultIndexService::class)->reconcile($this->vault, IndexMode::Full);
    $oldUuid = Note::query()->first()->uuid;

    unlink($this->vault->path.DIRECTORY_SEPARATOR.'X.md');
    // As in the real flow: the external-change check (or a manual
    // Re-index) reconciles the deletion — removing the stale registry row
    // — before the "missing" banner offers "Save as a new note".
    app(VaultIndexService::class)->reconcile($this->vault, IndexMode::Full);
    expect(Note::query()->count())->toBe(0);

    $response = $this->postJson(route('vaults.notes.copy', $this->vault->uuid), [
        'source_path' => 'X.md',
        'content' => 'mine',
        'mode' => 'source',
    ]);

    $response->assertCreated();
    $response->assertJson(['relative_path' => 'X.md']);
    expect($response->json('uuid'))->not->toBe($oldUuid);
    expect(File::get($this->vault->path.DIRECTORY_SEPARATOR.'X.md'))->toBe('mine');
});

test('a deleted source with a stale, unreconciled registry row is still recreated at the original path with a new UUID (service level)', function () {
    writeVaultFiles($this->vault->path, ['X.md' => 'original']);
    app(VaultIndexService::class)->reconcile($this->vault, IndexMode::Full);
    $oldUuid = Note::query()->first()->uuid;

    unlink($this->vault->path.DIRECTORY_SEPARATOR.'X.md');
    // No reconcile here: the registry still holds the stale row for X.md,
    // exactly as it does after NoteService::save's 409 'missing' path (which
    // never touches the registry) or while app.check_external_changes is
    // off. Recreating at the original path must not throw a unique
    // constraint violation.

    $copy = app(NoteService::class)->createCopy($this->vault, 'X.md', 'mine', NoteSaveMode::Source, null);

    expect($copy->relative_path)->toBe('X.md');
    expect($copy->uuid)->not->toBe($oldUuid);
    expect(File::get($this->vault->path.DIRECTORY_SEPARATOR.'X.md'))->toBe('mine');
    expect(Note::query()->count())->toBe(1);
    expect(Note::query()->first()->uuid)->toBe($copy->uuid);
});

test('a deleted source with a stale, unreconciled registry row is still recreated at the original path with a new UUID (HTTP level)', function () {
    writeVaultFiles($this->vault->path, ['X.md' => 'original']);
    app(VaultIndexService::class)->reconcile($this->vault, IndexMode::Full);
    $oldUuid = Note::query()->first()->uuid;

    unlink($this->vault->path.DIRECTORY_SEPARATOR.'X.md');
    // Same scenario as above, exercised through the HTTP endpoint.

    $response = $this->postJson(route('vaults.notes.copy', $this->vault->uuid), [
        'source_path' => 'X.md',
        'content' => 'mine',
        'mode' => 'source',
    ]);

    $response->assertCreated();
    $response->assertJson(['relative_path' => 'X.md']);
    expect($response->json('uuid'))->not->toBe($oldUuid);
    expect(File::get($this->vault->path.DIRECTORY_SEPARATOR.'X.md'))->toBe('mine');
    expect(Note::query()->count())->toBe(1);
    expect(Note::query()->first()->uuid)->toBe($response->json('uuid'));
});

test('a different note\'s stale, unreconciled registry row at a "(my version)" candidate path is skipped, not crashed on', function () {
    // A previous copy of X.md ("X (my version).md") is its own, separate
    // note (a different UUID from X.md). That copy's file is later deleted
    // externally, and the deletion is never reconciled, so the registry
    // still holds a stale row for a *different* note at the exact path the
    // next copy attempt's first candidate would land on. Unlike the
    // source's own stale row at the original path, assertNoConflict's
    // existing case-insensitive dedup check (which only excepts the
    // source's own row) still catches this one and skips the candidate —
    // so this must never reach the insert and crash; it simply falls
    // through to the next free name.
    writeVaultFiles($this->vault->path, [
        'X.md' => 'original',
        'X (my version).md' => 'an earlier copy, deleted externally',
    ]);
    app(VaultIndexService::class)->reconcile($this->vault, IndexMode::Full);
    $earlierCopyUuid = Note::query()->where('relative_path', 'X (my version).md')->first()->uuid;

    unlink($this->vault->path.DIRECTORY_SEPARATOR.'X (my version).md');
    // No reconcile: the earlier copy's stale row is still there.

    $response = $this->postJson(route('vaults.notes.copy', $this->vault->uuid), [
        'source_path' => 'X.md',
        'content' => 'mine',
        'mode' => 'source',
    ]);

    $response->assertCreated();
    $response->assertJson(['relative_path' => 'X (my version 2).md']);
    expect($response->json('uuid'))->not->toBe($earlierCopyUuid);
    expect(File::get($this->vault->path.DIRECTORY_SEPARATOR.'X (my version 2).md'))->toBe('mine');
    // The unrelated stale row is left exactly as it was; a later reconcile
    // (not this call's concern) removes it.
    expect(Note::query()->where('relative_path', 'X (my version).md')->where('uuid', $earlierCopyUuid)->exists())->toBeTrue();
});

test('a DB failure recreating a deleted source leaves the stale row and its uuid intact (QA-03)', function () {
    writeVaultFiles($this->vault->path, ['X.md' => 'original']);
    app(VaultIndexService::class)->reconcile($this->vault, IndexMode::Full);
    $staleUuid = Note::query()->first()->uuid;

    unlink($this->vault->path.DIRECTORY_SEPARATOR.'X.md');
    // No reconcile: the registry still holds the stale row for X.md, so
    // registerNewFile's clearStaleRow delete and the new insert run inside
    // the same transaction as the failing create() call below.

    Note::creating(function (): void {
        throw new RuntimeException('db down');
    });

    expect(fn () => app(NoteService::class)->createCopy($this->vault, 'X.md', 'mine', NoteSaveMode::Source, null))
        ->toThrow(RuntimeException::class, 'db down');

    Note::flushEventListeners();

    expect(File::exists($this->vault->path.DIRECTORY_SEPARATOR.'X.md'))->toBeFalse();
    expect(Note::query()->count())->toBe(1);
    $stale = Note::query()->first();
    expect($stale->uuid)->toBe($staleUuid);
    expect($stale->relative_path)->toBe('X.md');
});

test('a deleted source folder falls back to the vault root', function () {
    writeVaultFiles($this->vault->path, ['Projects/X.md' => 'original']);
    app(VaultIndexService::class)->reconcile($this->vault, IndexMode::Full);

    File::deleteDirectory($this->vault->path.DIRECTORY_SEPARATOR.'Projects');

    $response = $this->postJson(route('vaults.notes.copy', $this->vault->uuid), [
        'source_path' => 'Projects/X.md',
        'content' => 'mine',
        'mode' => 'source',
    ]);

    $response->assertCreated();
    $response->assertJson(['relative_path' => 'X.md']);
    expect(File::exists($this->vault->path.DIRECTORY_SEPARATOR.'X.md'))->toBeTrue();
});

test('a CRLF and BOM original produces a CRLF and BOM copy', function () {
    $bytes = "\xEF\xBB\xBF"."Line1\r\nLine2\r\n";
    File::put($this->vault->path.DIRECTORY_SEPARATOR.'X.md', $bytes);
    app(VaultIndexService::class)->reconcile($this->vault, IndexMode::Full);

    $response = $this->postJson(route('vaults.notes.copy', $this->vault->uuid), [
        'source_path' => 'X.md',
        'content' => "new body\nsecond line",
        'mode' => 'source',
    ]);

    $response->assertCreated();
    $copied = File::get($this->vault->path.DIRECTORY_SEPARATOR.'X (my version).md');
    expect(str_starts_with($copied, "\xEF\xBB\xBF"))->toBeTrue();
    expect($copied)->toContain("\r\n");
});

test('source mode copies the content verbatim, with no Rich-mode composition', function () {
    writeVaultFiles($this->vault->path, ['X.md' => 'original']);
    app(VaultIndexService::class)->reconcile($this->vault, IndexMode::Full);

    $response = $this->postJson(route('vaults.notes.copy', $this->vault->uuid), [
        'source_path' => 'X.md',
        'content' => "line one\n\nline two",
        'mode' => 'source',
    ]);

    $response->assertCreated();
    expect(File::get($this->vault->path.DIRECTORY_SEPARATOR.'X (my version).md'))->toBe("line one\n\nline two");
});

test('an unsafe source_path fails validation on source_path', function (string $sourcePath) {
    $this->postJson(route('vaults.notes.copy', $this->vault->uuid), [
        'source_path' => $sourcePath,
        'content' => 'mine',
        'mode' => 'source',
    ])->assertJsonValidationErrors('source_path');
})->with([
    '../x.md', 'C:/x.md', 'a\\b.md', 'x.txt',
]);

test('content over 1 MiB fails validation on content', function () {
    $this->postJson(route('vaults.notes.copy', $this->vault->uuid), [
        'source_path' => 'X.md',
        'content' => str_repeat('a', 1_048_577),
        'mode' => 'source',
    ])->assertJsonValidationErrors('content');
});

test('exhausting every "(my version N)" candidate fails on content', function () {
    $files = ['X.md' => 'orig', 'X (my version).md' => '1'];

    for ($n = 2; $n <= 20; $n++) {
        $files["X (my version {$n}).md"] = (string) $n;
    }

    writeVaultFiles($this->vault->path, $files);
    app(VaultIndexService::class)->reconcile($this->vault, IndexMode::Full);

    $response = $this->postJson(route('vaults.notes.copy', $this->vault->uuid), [
        'source_path' => 'X.md',
        'content' => 'mine',
        'mode' => 'source',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('content');
});

test('a DB failure on insert removes the new file and leaves the original untouched', function () {
    writeVaultFiles($this->vault->path, ['X.md' => 'original']);
    app(VaultIndexService::class)->reconcile($this->vault, IndexMode::Full);

    Note::creating(function (): void {
        throw new RuntimeException('db down');
    });

    expect(fn () => app(NoteService::class)->createCopy($this->vault, 'X.md', 'mine', NoteSaveMode::Source, null))
        ->toThrow(RuntimeException::class, 'db down');

    Note::flushEventListeners();

    expect(File::exists($this->vault->path.DIRECTORY_SEPARATOR.'X (my version).md'))->toBeFalse();
    expect(File::get($this->vault->path.DIRECTORY_SEPARATOR.'X.md'))->toBe('original');
    expect(Note::query()->count())->toBe(1);
});

test('a rich copy with frontmatter works through the service directly', function () {
    writeVaultFiles($this->vault->path, ['X.md' => 'body text']);
    app(VaultIndexService::class)->reconcile($this->vault, IndexMode::Full);

    $copy = app(NoteService::class)->createCopy(
        $this->vault,
        'X.md',
        'new body',
        NoteSaveMode::Rich,
        new FrontmatterEdit(true, 'title: mine'),
    );

    expect($copy->relative_path)->toBe('X (my version).md');
    $contents = File::get($this->vault->path.DIRECTORY_SEPARATOR.'X (my version).md');
    expect($contents)->toContain('title: mine')->toContain('new body');
});
