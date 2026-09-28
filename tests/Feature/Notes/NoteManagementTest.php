<?php

use App\Models\Note;
use App\Services\NoteService;
use App\Services\VaultService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mdvault-notes-http-'.Str::random(8);
    fakeDocumentsDirectory($this->tmp.DIRECTORY_SEPARATOR.'Documents');
    $this->vault = app(VaultService::class)->create('Work');
    app(VaultService::class)->open($this->vault);
});

afterEach(function () {
    File::deleteDirectory($this->tmp);
});

test('creating a note at the vault root redirects to it and creates the file', function () {
    $response = $this->post(route('vaults.notes.store', $this->vault->uuid), ['name' => 'Meeting', 'folder' => '']);

    $note = Note::query()->sole();
    $response->assertRedirect(route('notes.show', $note->uuid));
    $response->assertInertiaFlash('toast.type', 'success');
    expect(is_file($this->vault->path.DIRECTORY_SEPARATOR.'Meeting.md'))->toBeTrue();
});

test('creating a note inside a folder places it there', function () {
    File::makeDirectory($this->vault->path.DIRECTORY_SEPARATOR.'Projects');

    $response = $this->post(route('vaults.notes.store', $this->vault->uuid), ['name' => 'Meeting', 'folder' => 'Projects']);

    $note = Note::query()->sole();
    $response->assertRedirect(route('notes.show', $note->uuid));
    expect($note->relative_path)->toBe('Projects/Meeting.md');
});

test('creating a note with an invalid name fails validation and creates nothing', function () {
    $this->post(route('vaults.notes.store', $this->vault->uuid), ['name' => 'CON', 'folder' => ''])
        ->assertSessionHasErrors('name');

    expect(Note::query()->count())->toBe(0);
});

test('creating a note in an invalid folder fails validation', function () {
    $this->post(route('vaults.notes.store', $this->vault->uuid), ['name' => 'Meeting', 'folder' => '../x'])
        ->assertSessionHasErrors('folder');
});

test('renaming a note through HTTP keeps the UUID and redirects to it', function () {
    $note = app(NoteService::class)->create($this->vault, null, 'a');

    $response = $this->patch(route('notes.update', $note->uuid), ['name' => 'Standup']);

    $response->assertRedirect(route('notes.show', $note->uuid));
    expect($note->fresh()->relative_path)->toBe('Standup.md');
    expect($note->fresh()->uuid)->toBe($note->uuid);
});

test('moving a note through HTTP relocates it, and moving to the top level clears the folder', function () {
    File::makeDirectory($this->vault->path.DIRECTORY_SEPARATOR.'Servers');
    $note = app(NoteService::class)->create($this->vault, null, 'a');

    $this->post(route('notes.move', $note->uuid), ['folder' => 'Servers'])
        ->assertRedirect(route('notes.show', $note->uuid));
    expect($note->fresh()->relative_path)->toBe('Servers/a.md');

    $this->post(route('notes.move', $note->uuid), ['folder' => ''])
        ->assertRedirect(route('notes.show', $note->uuid));
    expect($note->fresh()->relative_path)->toBe('a.md');
});

test('deleting a note without a trash fails validation, and with a fake trash it succeeds', function () {
    $note = app(NoteService::class)->create($this->vault, null, 'a');

    $this->delete(route('notes.destroy', $note->uuid))
        ->assertSessionHasErrors('note');
    expect(Note::query()->count())->toBe(1);

    fakeTrash();
    $response = $this->delete(route('notes.destroy', $note->uuid));

    $response->assertRedirect(route('workspace'));
    expect(Note::query()->count())->toBe(0);
    expect(file_exists($this->vault->path.DIRECTORY_SEPARATOR.'a.md'))->toBeFalse();
});

test('creating and deleting a folder through HTTP', function () {
    File::makeDirectory($this->vault->path.DIRECTORY_SEPARATOR.'Projects');
    File::put($this->vault->path.DIRECTORY_SEPARATOR.'Projects'.DIRECTORY_SEPARATOR.'a.md', 'x');

    $this->post(route('vaults.folders.store', $this->vault->uuid), ['name' => 'Archive', 'parent' => ''])
        ->assertRedirect();
    expect(is_dir($this->vault->path.DIRECTORY_SEPARATOR.'Archive'))->toBeTrue();

    $this->delete(route('vaults.folders.destroy', $this->vault->uuid), ['path' => 'Archive'])
        ->assertRedirect();
    expect(is_dir($this->vault->path.DIRECTORY_SEPARATOR.'Archive'))->toBeFalse();

    $this->delete(route('vaults.folders.destroy', $this->vault->uuid), ['path' => 'Projects'])
        ->assertSessionHasErrors('path');
});

test('the manual reindex route reports its counts, and a missing vault flashes an error', function () {
    $response = $this->from(route('workspace'))->post(route('vaults.reindex', $this->vault->uuid));

    $response->assertRedirect();
    $response->assertInertiaFlash('toast.message', 'The index is already up to date.');

    File::put($this->vault->path.DIRECTORY_SEPARATOR.'New.md', 'x');

    $this->from(route('workspace'))->post(route('vaults.reindex', $this->vault->uuid))
        ->assertInertiaFlash('toast.message', 'Index updated: 1 added, 0 updated, 0 moved or renamed, 0 removed.');

    File::deleteDirectory($this->vault->path);

    $this->from(route('workspace'))->post(route('vaults.reindex', $this->vault->uuid))
        ->assertInertiaFlash('toast.type', 'error');
});

test('opening a note shows its content read-only and no internal ids', function () {
    $note = app(NoteService::class)->create($this->vault, null, 'Meeting');
    File::put($this->vault->path.DIRECTORY_SEPARATOR.'Meeting.md', 'Hello');

    $response = $this->get(route('notes.show', $note->uuid));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Workspace')
        ->where('note.uuid', $note->uuid)
        ->where('note.content', 'Hello')
        ->where('note.state', 'ok')
        ->where('note.relative_path', 'Meeting.md')
        ->has('tree')
        ->where('folders.0', '')
        ->missing('note.id')
        ->missing('note.vault_id'));
});

test('opening a note of a vault that is not current redirects with an error toast', function () {
    $other = app(VaultService::class)->create('Other');
    $note = app(NoteService::class)->create($other, null, 'a');

    $response = $this->get(route('notes.show', $note->uuid));

    $response->assertRedirect(route('workspace'));
    $response->assertInertiaFlash('toast.type', 'error');
});

test('an unknown uuid and an integer id both 404', function () {
    $this->get(route('notes.show', Str::uuid()->toString()))->assertNotFound();
    $this->get('/notes/1')->assertNotFound();
});

test('script tags in note content are returned verbatim, never rendered', function () {
    $note = app(NoteService::class)->create($this->vault, null, 'a');
    File::put($this->vault->path.DIRECTORY_SEPARATOR.'a.md', '<script>alert(1)</script>');

    $response = $this->get(route('notes.show', $note->uuid));

    $response->assertInertia(fn ($page) => $page->where('note.content', '<script>alert(1)</script>'));
});
