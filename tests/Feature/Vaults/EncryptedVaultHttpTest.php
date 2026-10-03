<?php

use App\Enums\NoteSaveMode;
use App\Enums\SettingKey;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Note;
use App\Models\Vault;
use App\Services\EncryptedNoteService;
use App\Services\NoteService;
use App\Services\SettingsService;
use App\Services\VaultEncryptionService;
use App\Services\VaultKeyService;
use App\Services\VaultService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

const HTTP_CANARY = 'CANARY-CONTENT-7f3a';

beforeEach(function () {
    $this->tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mdvault-enchttp-'.Str::random(8);
    fakeDocumentsDirectory($this->tmp.DIRECTORY_SEPARATOR.'Documents');

    [$this->vault, $this->token] = encryptedVault('Secrets', 'correct horse battery');
    app(VaultService::class)->open($this->vault);

    $this->notes = app(NoteService::class);
    $this->folder = $this->notes->createFolder($this->vault, null, 'My Credentials');
    $this->note = $this->notes->create($this->vault, $this->folder, 'Bank Accounts');
    $this->notes->save($this->note, HTTP_CANARY, $this->notes->preview($this->note)['base_hash'], NoteSaveMode::Source);

    $this->inertia = fn (): array => ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request())];
    $this->withToken = fn () => withVaultToken($this, $this->vault, $this->token);
});

afterEach(function () {
    File::deleteDirectory($this->tmp);
});

test('creating an encrypted vault returns only its uuid and token and opens it', function () {
    $response = $this->postJson(route('vaults.encrypted.store'), [
        'name' => 'Diary',
        'description' => 'Mine',
        'password' => 'another long password',
        'password_confirmation' => 'another long password',
        'acknowledge' => true,
    ]);

    $response->assertCreated();

    $vault = Vault::query()->where('name', 'Diary')->firstOrFail();

    expect(array_keys($response->json()))->toEqualCanonicalizing(['uuid', 'token'])
        ->and($response->json('uuid'))->toBe($vault->uuid)
        ->and($response->json('token'))->toMatch('/^[A-Za-z0-9_-]{43}$/')
        ->and($vault->is_encrypted)->toBeTrue()
        ->and(app(SettingsService::class)->string(SettingKey::CurrentVault))->toBe($vault->uuid);

    assertNoNeedlesInDatabase(['another long password', $response->json('token')]);
});

test('invalid encrypted vault input is a 422 and creates nothing', function (array $input, string $field) {
    $before = Vault::query()->count();

    $this->postJson(route('vaults.encrypted.store'), [
        'name' => 'Rejected',
        'password' => 'another long password',
        'password_confirmation' => 'another long password',
        'acknowledge' => true,
        ...$input,
    ])->assertUnprocessable()->assertJsonValidationErrors($field);

    expect(Vault::query()->count())->toBe($before)
        ->and(File::isDirectory($this->tmp.DIRECTORY_SEPARATOR.'Documents'.DIRECTORY_SEPARATOR.'MDVault'.DIRECTORY_SEPARATOR.'Rejected'))->toBeFalse();
})->with([
    'short password' => [['password' => 'short', 'password_confirmation' => 'short'], 'password'],
    'mismatched confirmation' => [['password_confirmation' => 'something else entirely'], 'password'],
    'missing acknowledgment' => [['acknowledge' => false], 'acknowledge'],
    'bad name' => [['name' => 'a/b'], 'name'],
    'duplicate name' => [['name' => 'secrets'], 'name'],
]);

test('a wrong password is a generic 422 and leaves no session entry', function () {
    app(VaultKeyService::class)->forgetAll();

    $this->postJson(route('vaults.unlock', $this->vault), ['password' => 'definitely wrong'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('password');

    expect(session()->has(VaultKeyService::SESSION_PREFIX.$this->vault->uuid))->toBeFalse();
});

test('the correct password returns exactly a token', function () {
    app(VaultKeyService::class)->forgetAll();

    $response = $this->postJson(route('vaults.unlock', $this->vault), ['password' => 'correct horse battery']);

    $response->assertOk();
    expect(array_keys($response->json()))->toBe(['token'])
        ->and($response->json('token'))->toMatch('/^[A-Za-z0-9_-]{43}$/')
        ->and(session()->has(VaultKeyService::SESSION_PREFIX.$this->vault->uuid))->toBeTrue();

    // The new token works; the old one is replaced.
    $this->withHeader(VaultKeyService::HEADER, $this->vault->uuid.':'.$response->json('token'))
        ->get(route('notes.show', $this->note->uuid))
        ->assertInertia(fn ($page) => $page->where('note.content', HTTP_CANARY));

    $this->withHeader(VaultKeyService::HEADER, $this->vault->uuid.':'.$this->token)
        ->get(route('notes.show', $this->note->uuid))
        ->assertInertia(fn ($page) => $page->where('note', null)->where('encryption.locked', true));
});

test('unlocking needs a password and an encrypted vault', function () {
    $this->postJson(route('vaults.unlock', $this->vault), [])->assertUnprocessable()->assertJsonValidationErrors('password');

    $plain = app(VaultService::class)->create('Plain');
    $this->postJson(route('vaults.unlock', $plain), ['password' => 'whatever you like'])->assertUnprocessable();
});

test('a locked workspace sends no tree, folders, signature or note', function () {
    $response = $this->get(route('notes.show', $this->note->uuid));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('currentVault.uuid', $this->vault->uuid)
        ->where('currentVault.is_encrypted', true)
        ->where('currentVault.is_unlocked', false)
        ->where('tree', null)
        ->where('folders', [''])
        ->where('treeSignature', null)
        ->where('note', null)
        ->where('encryption.locked', true)
        ->where('encryption.unencrypted_files', [])
        ->where('encryption.inconsistent', false));

    $body = $response->getContent();

    foreach (['Bank Accounts', 'My Credentials', HTTP_CANARY] as $needle) {
        expect($body)->not->toContain($needle);
    }
});

test('an unlocked workspace shows decrypted names and encrypts history', function () {
    ($this->withToken)();

    $response = $this->get(route('notes.show', $this->note->uuid), ($this->inertia)());

    $response->assertOk();

    expect($response->json('encryptHistory'))->toBeTrue()
        ->and($response->json('props.currentVault.is_unlocked'))->toBeTrue()
        ->and($response->json('props.folders'))->toBe(['', 'My Credentials'])
        ->and($response->json('props.tree.0.name'))->toBe('My Credentials')
        ->and($response->json('props.tree.0.children.0.title'))->toBe('Bank Accounts')
        ->and($response->json('props.tree.0.children.0.path'))->toBe('My Credentials/Bank Accounts.md')
        ->and($response->json('props.note.title'))->toBe('Bank Accounts')
        ->and($response->json('props.note.relative_path'))->toBe('My Credentials/Bank Accounts.md')
        ->and($response->json('props.note.content'))->toBe(HTTP_CANARY)
        ->and($response->json('props.encryption.locked'))->toBeFalse()
        ->and($response->json('props.treeSignature'))->toBeString();

    $body = $response->getContent();

    foreach ([$this->token, 'salt', 'wrapped', 'encrypted_key'] as $needle) {
        expect($body)->not->toContain($needle);
    }
});

test('the shared vault summaries carry only booleans for encryption', function () {
    $this->get(route('workspace'))->assertInertia(fn ($page) => $page
        ->where('vaults.0.is_encrypted', true)
        ->where('vaults.0.is_unlocked', false)
        ->missing('vaults.0.salt')
        ->missing('vaults.0.token'));

    ($this->withToken)();

    $this->get(route('workspace'))->assertInertia(fn ($page) => $page->where('vaults.0.is_unlocked', true));
});

test('saving needs the token: 200 with it, 423 JSON without', function () {
    $hash = $this->notes->preview($this->note->fresh())['base_hash'];
    $payload = ['content' => 'new body', 'base_hash' => $hash, 'mode' => 'source'];

    $this->putJson(route('notes.content.update', $this->note->uuid), $payload)
        ->assertStatus(423)
        ->assertExactJson(['message' => 'This vault is locked. Unlock it to continue.', 'reason' => 'locked']);

    ($this->withToken)();

    $this->putJson(route('notes.content.update', $this->note->uuid), $payload)
        ->assertOk()
        ->assertJsonPath('saved', true);

    // The request is over, so its tokens are gone: provide them again.
    app(VaultKeyService::class)->provideTokens($this->vault->uuid.':'.$this->token);

    expect($this->notes->preview($this->note->fresh())['content'])->toBe('new body');
});

test('the disk compare endpoint needs the token', function () {
    $this->getJson(route('notes.disk.show', $this->note->uuid))->assertStatus(423);

    ($this->withToken)();

    $this->getJson(route('notes.disk.show', $this->note->uuid))
        ->assertOk()
        ->assertJsonPath('content', HTTP_CANARY);
});

test('locking makes the old token useless and clears history', function () {
    ($this->withToken)();

    $this->post(route('vaults.lock', $this->vault))->assertRedirect(route('workspace'));

    $follow = $this->get(route('workspace'), ($this->inertia)());

    expect($follow->json('clearHistory'))->toBeTrue()
        ->and($follow->json('props.currentVault.is_unlocked'))->toBeFalse()
        ->and($follow->json('props.tree'))->toBeNull();

    // The same token, replayed, still opens nothing.
    $this->getJson(route('notes.disk.show', $this->note->uuid))->assertStatus(423);
});

test('lock all locks every vault', function () {
    [$other, $otherToken] = encryptedVault('Other');

    $this->withHeader(VaultKeyService::HEADER, $this->vault->uuid.':'.$this->token.','.$other->uuid.':'.$otherToken);

    $this->get(route('workspace'))->assertInertia(fn ($page) => $page->where('vaults.0.is_unlocked', true)->where('vaults.1.is_unlocked', true));

    $this->post(route('vaults.lock.all'))->assertRedirect(route('workspace'));

    $this->get(route('workspace'))->assertInertia(fn ($page) => $page->where('vaults.0.is_unlocked', false)->where('vaults.1.is_unlocked', false));
});

test('closing the vault locks it', function () {
    ($this->withToken)();

    $this->post(route('vaults.close'))->assertRedirect(route('workspace'));

    expect(session()->has(VaultKeyService::SESSION_PREFIX.$this->vault->uuid))->toBeFalse();
});

test('note and folder actions show generic toasts and never flash names', function () {
    ($this->withToken)();

    $this->post(route('vaults.folders.store', $this->vault), ['name' => 'Secret Folder', 'parent' => ''])
        ->assertRedirect()
        ->assertInertiaFlash('toast.message', 'Folder created.');

    $created = $this->post(route('vaults.notes.store', $this->vault), ['name' => 'Secret Note', 'folder' => 'Secret Folder', 'timezone' => 'UTC']);
    $created->assertRedirect()->assertInertiaFlash('toast.message', 'Note created.');

    $note = Note::query()->latest('id')->first();

    $this->patch(route('notes.update', $note->uuid), ['name' => 'Renamed Secret'])
        ->assertInertiaFlash('toast.message', 'Note renamed.');

    $this->post(route('notes.move', $note->uuid), ['folder' => ''])
        ->assertInertiaFlash('toast.message', 'Note moved to the new folder.');

    // A failure reports a generic message in the validation errors.
    $this->post(route('vaults.notes.store', $this->vault), ['name' => 'Renamed Secret', 'folder' => ''])
        ->assertSessionHasErrors('name');

    $stored = serialize(session()->all());

    foreach (['Secret Folder', 'Secret Note', 'Renamed Secret', 'Bank Accounts', 'My Credentials', HTTP_CANARY] as $needle) {
        expect($stored)->not->toContain($needle);
    }
});

test('a note is deleted with a generic toast', function () {
    fakeTrash();
    app()->forgetInstance(EncryptedNoteService::class);
    ($this->withToken)();

    $this->delete(route('notes.destroy', $this->note->uuid))
        ->assertRedirect(route('workspace'))
        ->assertInertiaFlash('toast.message', 'Note moved to the Recycle Bin / Trash.');
});

test('save as a new note returns the logical title and path', function () {
    ($this->withToken)();

    $response = $this->postJson(route('vaults.notes.copy', $this->vault), [
        'source_path' => 'My Credentials/Bank Accounts.md',
        'content' => 'mine',
        'mode' => 'source',
    ]);

    $response->assertCreated()
        ->assertJsonPath('title', 'Bank Accounts (my version)')
        ->assertJsonPath('relative_path', 'My Credentials/Bank Accounts (my version).md');
});

test('the external change check works while locked and reports no path', function () {
    $this->postJson(route('vaults.changes.check', $this->vault), ['open_note' => $this->note->uuid])
        ->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('open_note.exists', true)
        ->assertJsonPath('open_note.relative_path', null);

    ($this->withToken)();

    $this->postJson(route('vaults.changes.check', $this->vault), ['open_note' => $this->note->uuid])
        ->assertJsonPath('open_note.relative_path', 'My Credentials/Bank Accounts.md');
});

test('the workspace warns about plaintext files and a missing key file', function () {
    File::put($this->vault->path.DIRECTORY_SEPARATOR.'Secret.md', '# not encrypted');

    $this->get(route('workspace'))->assertInertia(fn ($page) => $page
        ->where('encryption.unencrypted_files', ['Secret.md'])
        ->where('encryption.inconsistent', false));

    File::delete($this->vault->path.DIRECTORY_SEPARATOR.VaultEncryptionService::HEADER_FILENAME);
    $this->vault->encryption()->delete();

    $this->get(route('workspace'))->assertInertia(fn ($page) => $page->where('encryption.inconsistent', true));
});

test('a plaintext vault gets no encryption prop', function () {
    $plain = app(VaultService::class)->create('Plain');
    app(VaultService::class)->open($plain);

    $this->get(route('workspace'))->assertInertia(fn ($page) => $page->where('encryption', null));
});
