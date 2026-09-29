<?php

use App\Services\MarkdownService;
use App\Services\NoteService;
use App\Services\VaultService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mdvault-notecontent-http-'.Str::random(8);
    fakeDocumentsDirectory($this->tmp.DIRECTORY_SEPARATOR.'Documents');
    $this->vault = app(VaultService::class)->create('Work');
    app(VaultService::class)->open($this->vault);
});

afterEach(function () {
    File::deleteDirectory($this->tmp);
});

test('a save writes the file and returns saved with the expected keys and no internal ids', function () {
    $note = app(NoteService::class)->create($this->vault, null, 'a');
    $path = $this->vault->path.DIRECTORY_SEPARATOR.'a.md';
    File::put($path, '');
    $baseHash = hash('sha256', '');

    $response = $this->putJson(route('notes.content.update', $note->uuid), [
        'content' => "# New\n",
        'base_hash' => $baseHash,
        'mode' => 'rich',
    ]);

    $response->assertOk();
    $response->assertJson(['saved' => true]);
    $response->assertJsonStructure(['saved', 'file_hash', 'file_size', 'updated_at']);
    $response->assertJsonMissing(['id']);
    $response->assertJsonMissing(['vault_id']);
    expect(File::get($path))->toBe("# New\n");
});

test('saving unchanged content returns saved false', function () {
    $note = app(NoteService::class)->create($this->vault, null, 'a');
    $path = $this->vault->path.DIRECTORY_SEPARATOR.'a.md';
    File::put($path, "# Same\n");
    $baseHash = hash('sha256', "# Same\n");

    $response = $this->putJson(route('notes.content.update', $note->uuid), [
        'content' => "# Same\n",
        'base_hash' => $baseHash,
        'mode' => 'rich',
    ]);

    $response->assertOk();
    $response->assertJson(['saved' => false]);
});

test('an external edit before saving gives 409 changed with the current hash', function () {
    $note = app(NoteService::class)->create($this->vault, null, 'a');
    $path = $this->vault->path.DIRECTORY_SEPARATOR.'a.md';
    File::put($path, "# Original\n");
    $staleHash = hash('sha256', 'stale');
    File::put($path, "# Changed externally\n");

    $response = $this->putJson(route('notes.content.update', $note->uuid), [
        'content' => "# My edit\n",
        'base_hash' => $staleHash,
        'mode' => 'rich',
    ]);

    $response->assertStatus(409);
    $response->assertJson([
        'reason' => 'changed',
        'current_hash' => hash_file('sha256', $path),
    ]);
});

test('a file deleted externally gives 409 missing', function () {
    $note = app(NoteService::class)->create($this->vault, null, 'a');
    $path = $this->vault->path.DIRECTORY_SEPARATOR.'a.md';
    File::put($path, "# Original\n");
    $baseHash = hash('sha256', "# Original\n");
    unlink($path);

    $response = $this->putJson(route('notes.content.update', $note->uuid), [
        'content' => "# My edit\n",
        'base_hash' => $baseHash,
        'mode' => 'rich',
    ]);

    $response->assertStatus(409);
    $response->assertJson(['reason' => 'missing']);
});

test('validation rejects a malformed or missing base_hash, an invalid mode and oversized content', function (array $overrides, string $field) {
    $note = app(NoteService::class)->create($this->vault, null, 'a');
    File::put($this->vault->path.DIRECTORY_SEPARATOR.'a.md', '');

    $payload = array_merge([
        'content' => 'x',
        'base_hash' => hash('sha256', ''),
        'mode' => 'rich',
    ], $overrides);

    $response = $this->putJson(route('notes.content.update', $note->uuid), $payload);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors([$field]);
})->with([
    'missing base_hash' => [['base_hash' => null], 'base_hash'],
    'malformed base_hash' => [['base_hash' => 'not-a-hash'], 'base_hash'],
    'invalid mode' => [['mode' => 'html'], 'mode'],
    'oversized content' => [['content' => str_repeat('x', NoteService::EDIT_LIMIT + 1)], 'content'],
]);

test('empty and null content both save an empty body', function (mixed $content) {
    $note = app(NoteService::class)->create($this->vault, null, 'a');
    $path = $this->vault->path.DIRECTORY_SEPARATOR.'a.md';
    File::put($path, "# Old\n");
    $baseHash = hash('sha256', "# Old\n");

    $response = $this->putJson(route('notes.content.update', $note->uuid), [
        'content' => $content,
        'base_hash' => $baseHash,
        'mode' => 'rich',
    ]);

    $response->assertOk();
    expect(File::get($path))->toBe('');
})->with([
    'empty string' => [''],
    'null' => [null],
]);

test('a locked rename gives a 422 on errors.content', function () {
    $note = app(NoteService::class)->create($this->vault, null, 'a');
    $path = $this->vault->path.DIRECTORY_SEPARATOR.'a.md';
    File::put($path, "# Original\n");
    $baseHash = hash('sha256', "# Original\n");
    failFileMoves([1, 2, 3]);

    $response = $this->putJson(route('notes.content.update', $note->uuid), [
        'content' => "# New\n",
        'base_hash' => $baseHash,
        'mode' => 'rich',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['content']);
});

test('an unknown uuid 404s, a numeric id 404s and GET 405s', function () {
    $this->putJson(route('notes.content.update', Str::uuid()->toString()), [
        'content' => 'x',
        'base_hash' => hash('sha256', ''),
        'mode' => 'rich',
    ])->assertNotFound();

    $this->putJson('/notes/1/content', [
        'content' => 'x',
        'base_hash' => hash('sha256', ''),
        'mode' => 'rich',
    ])->assertNotFound();

    $note = app(NoteService::class)->create($this->vault, null, 'a');
    $this->get(route('notes.content.update', $note->uuid))->assertMethodNotAllowed();
});

test('notes.show exposes body, frontmatter, base_hash and editable, with no CR or BOM in content', function () {
    $note = app(NoteService::class)->create($this->vault, null, 'a');
    $path = $this->vault->path.DIRECTORY_SEPARATOR.'a.md';
    File::put($path, MarkdownService::BOM."---\r\ntitle: X\r\n---\r\n\r\n# H\r\n");

    $response = $this->get(route('notes.show', $note->uuid));

    $response->assertInertia(fn ($page) => $page
        ->where('note.frontmatter', "---\ntitle: X\n---\n\n")
        ->where('note.body', "# H\n")
        ->where('note.base_hash', hash_file('sha256', $path))
        ->where('note.editable', true)
        ->where('note.read_only_reason', null));

    $response->assertInertia(function ($page) {
        $content = $page->toArray()['props']['note']['content'];
        expect($content)->not->toContain("\r");
        expect($content)->not->toContain("\u{FEFF}");
    });
});

test('after an external edit, notes.show gives file_hash equal to base_hash equal to the disk hash', function () {
    $note = app(NoteService::class)->create($this->vault, null, 'a');
    $path = $this->vault->path.DIRECTORY_SEPARATOR.'a.md';
    File::put($path, "# Changed externally\n");

    $response = $this->get(route('notes.show', $note->uuid));

    $diskHash = hash_file('sha256', $path);
    $response->assertInertia(fn ($page) => $page
        ->where('note.file_hash', $diskHash)
        ->where('note.base_hash', $diskHash));
});

// --- Revision 3: editable frontmatter -------------------------------------

test('has_frontmatter together with mode source gives 422', function () {
    $note = app(NoteService::class)->create($this->vault, null, 'a');
    File::put($this->vault->path.DIRECTORY_SEPARATOR.'a.md', "# H\n");

    $response = $this->putJson(route('notes.content.update', $note->uuid), [
        'content' => "# H\n",
        'base_hash' => hash('sha256', "# H\n"),
        'mode' => 'source',
        'has_frontmatter' => true,
        'frontmatter' => 'a: 1',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['has_frontmatter']);
});

test('has_frontmatter true with an empty frontmatter string writes an empty block', function () {
    $note = app(NoteService::class)->create($this->vault, null, 'a');
    $path = $this->vault->path.DIRECTORY_SEPARATOR.'a.md';
    File::put($path, "# H\n");

    $response = $this->putJson(route('notes.content.update', $note->uuid), [
        'content' => "# H\n",
        'base_hash' => hash('sha256', "# H\n"),
        'mode' => 'rich',
        'has_frontmatter' => true,
        'frontmatter' => '',
    ]);

    $response->assertOk();
    expect(File::get($path))->toBe("---\n---\n\n# H\n");
});

test('an invalid frontmatter gives 422 on errors.frontmatter', function () {
    $note = app(NoteService::class)->create($this->vault, null, 'a');
    File::put($this->vault->path.DIRECTORY_SEPARATOR.'a.md', "# H\n");

    $response = $this->putJson(route('notes.content.update', $note->uuid), [
        'content' => "# H\n",
        'base_hash' => hash('sha256', "# H\n"),
        'mode' => 'rich',
        'has_frontmatter' => true,
        'frontmatter' => "a\n---\nb",
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['frontmatter']);
});

test('notes.show exposes note.frontmatter_yaml', function () {
    $note = app(NoteService::class)->create($this->vault, null, 'a');
    File::put($this->vault->path.DIRECTORY_SEPARATOR.'a.md', "---\ntitle: X\n---\n\n# H\n");

    $response = $this->get(route('notes.show', $note->uuid));

    $response->assertInertia(fn ($page) => $page->where('note.frontmatter_yaml', 'title: X'));
});
