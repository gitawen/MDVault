<?php

use App\Exceptions\EncryptionException;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Note;
use App\Models\VaultEncryption;
use App\Services\VaultEncryptionService;
use App\Services\VaultIndexService;
use App\Services\VaultKeyService;
use App\Services\VaultService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mdvault-enchttp2-'.Str::random(8);
    fakeDocumentsDirectory($this->tmp.DIRECTORY_SEPARATOR.'Documents');

    $vault = app(VaultService::class)->create('Plain');
    writeVaultFiles($vault->path, [
        'Bank Accounts.md' => 'CANARY-CONTENT-7f3a',
        'My Credentials/Passwords.md' => 'secret',
    ]);
    app(VaultIndexService::class)->reindex($vault);
    app(VaultService::class)->open($vault);
    $this->vault = $vault->refresh();

    $this->inertia = fn (): array => ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request())];
});

afterEach(function () {
    File::deleteDirectory($this->tmp);
});

function encryptInput(array $overrides = []): array
{
    return [
        'password' => 'a brand new password',
        'password_confirmation' => 'a brand new password',
        'acknowledge' => true,
        'acknowledge_delete' => true,
        ...$overrides,
    ];
}

test('encrypting a vault returns the unlock token and the workspace shows the decrypted tree', function () {
    $response = $this->postJson(route('vaults.encryption.store', $this->vault), encryptInput());

    $response->assertOk();
    expect(array_keys($response->json()))->toEqualCanonicalizing(['token', 'warning'])
        ->and($response->json('token'))->toMatch('/^[A-Za-z0-9_-]{43}$/')
        ->and($response->json('warning'))->toBeNull()
        ->and($this->vault->refresh()->is_encrypted)->toBeTrue();

    $page = $this->withHeaders(($this->inertia)())
        ->withHeader(VaultKeyService::HEADER, $this->vault->uuid.':'.$response->json('token'))
        ->get(route('workspace'))
        ->assertOk();

    expect($page->json('props.vaults.0.is_unlocked'))->toBeTrue()
        ->and(json_encode($page->json('props.tree')))->toContain('Bank Accounts', 'My Credentials');

    expect(session()->get('_old_input'))->toBeNull();
    assertNoNeedlesInDatabase(['a brand new password', $response->json('token'), 'Bank Accounts', 'CANARY-CONTENT-7f3a']);
});

test('invalid encryption input is a 422 and changes nothing', function (array $overrides, string $field) {
    $before = File::allFiles($this->vault->path);

    $this->postJson(route('vaults.encryption.store', $this->vault), encryptInput($overrides))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);

    expect($this->vault->refresh()->is_encrypted)->toBeFalse()
        ->and(File::allFiles($this->vault->path))->toHaveCount(count($before))
        ->and(VaultEncryption::query()->count())->toBe(0);
})->with([
    'a short password' => [['password' => 'short', 'password_confirmation' => 'short'], 'password'],
    'a mismatched confirmation' => [['password_confirmation' => 'something else entirely'], 'password'],
    'no password acknowledgment' => [['acknowledge' => false], 'acknowledge'],
    'no deletion acknowledgment' => [['acknowledge_delete' => false], 'acknowledge_delete'],
]);

test('a refused conversion lists the problems and changes nothing', function () {
    File::put($this->vault->path.DIRECTORY_SEPARATOR.'photo.png', 'png');
    File::makeDirectory($this->vault->path.DIRECTORY_SEPARATOR.'.git');
    File::put($this->vault->path.DIRECTORY_SEPARATOR.'.git'.DIRECTORY_SEPARATOR.'HEAD', 'ref');

    $response = $this->postJson(route('vaults.encryption.store', $this->vault), encryptInput())->assertUnprocessable();

    expect($response->json('problems'))->toHaveCount(2)
        ->and(implode(' ', $response->json('problems')))->toContain('photo.png', '.git')
        ->and($response->json('errors.problem_0'))->toHaveCount(1)
        ->and($response->json('errors.vault'))->toHaveCount(1)
        ->and($vault = $this->vault->refresh())->is_encrypted->toBeFalse()
        ->and(File::exists($vault->path.DIRECTORY_SEPARATOR.'Bank Accounts.md'))->toBeTrue();

    expect(session()->get('_old_input'))->toBeNull();
});

test('encrypting an already encrypted vault is a 422', function () {
    [$vault] = encryptedVault('Already');

    $this->postJson(route('vaults.encryption.store', $vault), encryptInput())
        ->assertUnprocessable()
        ->assertJsonPath('errors.vault.0', 'This vault is already encrypted.');
});

test('removing encryption restores the plain files and clears the keyring entry', function () {
    $token = $this->postJson(route('vaults.encryption.store', $this->vault), encryptInput())->json('token');

    $this->withHeader(VaultKeyService::HEADER, $this->vault->uuid.':'.$token)
        ->from(route('vaults.index'))
        ->delete(route('vaults.encryption.destroy', $this->vault), ['password' => 'a brand new password', 'acknowledge' => true])
        ->assertRedirect(route('vaults.index'));

    $vault = $this->vault->refresh();

    expect($vault->is_encrypted)->toBeFalse()
        ->and(VaultEncryption::query()->count())->toBe(0)
        ->and(File::get($vault->path.DIRECTORY_SEPARATOR.'Bank Accounts.md'))->toBe('CANARY-CONTENT-7f3a')
        ->and(File::exists($vault->path.DIRECTORY_SEPARATOR.VaultEncryptionService::HEADER_FILENAME))->toBeFalse()
        ->and(session()->get('_old_input'))->toBeNull();
});

test('removing encryption with a wrong password or no acknowledgment is refused', function (array $input, string $field) {
    $this->postJson(route('vaults.encryption.store', $this->vault), encryptInput());
    $before = array_map(fn (SplFileInfo $f): string => $f->getFilename(), File::allFiles($this->vault->path));

    $this->from(route('vaults.index'))
        ->delete(route('vaults.encryption.destroy', $this->vault), $input)
        ->assertRedirect(route('vaults.index'))
        ->assertSessionHasErrors($field);

    expect($this->vault->refresh()->is_encrypted)->toBeTrue()
        ->and(array_map(fn (SplFileInfo $f): string => $f->getFilename(), File::allFiles($this->vault->path)))->toBe($before)
        ->and(array_keys(session()->get('_old_input') ?? []))->not->toContain('password', 'password_confirmation', 'current_password');
})->with([
    'a wrong password' => [['password' => 'not the right one!', 'acknowledge' => true], 'password'],
    'no acknowledgment' => [['password' => 'a brand new password', 'acknowledge' => false], 'acknowledge'],
]);

test('changing the password keeps the session unlocked and the notes untouched', function () {
    $token = $this->postJson(route('vaults.encryption.store', $this->vault), encryptInput())->json('token');
    $unlocked = fn () => $this->withHeaders(($this->inertia)())->withHeader(VaultKeyService::HEADER, $this->vault->uuid.':'.$token);
    $before = array_map(fn (Note $n): string => $n->file_hash, Note::query()->orderBy('relative_path')->get()->all());

    $this->withHeader(VaultKeyService::HEADER, $this->vault->uuid.':'.$token)
        ->from(route('vaults.index'))
        ->put(route('vaults.encryption.password.update', $this->vault), [
            'current_password' => 'a brand new password',
            'password' => 'yet another password',
            'password_confirmation' => 'yet another password',
        ])
        ->assertRedirect(route('vaults.index'))
        ->assertSessionHasNoErrors();

    $page = $unlocked()->get(route('workspace'))->assertOk();

    expect($page->json('props.vaults.0.is_unlocked'))->toBeTrue()
        ->and(array_map(fn (Note $n): string => $n->file_hash, Note::query()->orderBy('relative_path')->get()->all()))->toBe($before)
        ->and(VaultEncryption::query()->first()->key_version)->toBe(2);

    $vault = $this->vault->refresh();
    expect(fn () => app(VaultEncryptionService::class)->unlock($vault, 'a brand new password'))->toThrow(EncryptionException::class);
    expect(app(VaultEncryptionService::class)->unlock($vault, 'yet another password'))->toBeString();
    expect(session()->get('_old_input'))->toBeNull();
    assertNoNeedlesInDatabase(['a brand new password', 'yet another password']);
});

test('invalid password changes are refused', function (array $input, string $field) {
    $this->postJson(route('vaults.encryption.store', $this->vault), encryptInput());

    $this->from(route('vaults.index'))
        ->put(route('vaults.encryption.password.update', $this->vault), $input)
        ->assertRedirect(route('vaults.index'))
        ->assertSessionHasErrors($field);

    expect(VaultEncryption::query()->first()->key_version)->toBe(1)
        ->and(array_keys(session()->get('_old_input') ?? []))->not->toContain('password', 'password_confirmation', 'current_password');
})->with([
    'a wrong current password' => [['current_password' => 'nope nope nope', 'password' => 'yet another password', 'password_confirmation' => 'yet another password'], 'current_password'],
    'a short new password' => [['current_password' => 'a brand new password', 'password' => 'short', 'password_confirmation' => 'short'], 'password'],
    'a mismatched confirmation' => [['current_password' => 'a brand new password', 'password' => 'yet another password', 'password_confirmation' => 'different'], 'password'],
    'the same password' => [['current_password' => 'a brand new password', 'password' => 'a brand new password', 'password_confirmation' => 'a brand new password'], 'password'],
]);

test('the unlock route is throttled with a generic error', function () {
    [$vault] = encryptedVault('Throttled');
    app(VaultKeyService::class)->forgetAll();

    for ($attempt = 1; $attempt <= 10; $attempt++) {
        $this->postJson(route('vaults.unlock', $vault), ['password' => 'definitely wrong'])->assertUnprocessable();
    }

    $blocked = $this->postJson(route('vaults.unlock', $vault), ['password' => 'correct horse battery']);

    $blocked->assertStatus(429);
    expect($blocked->getContent())->not->toContain('correct horse battery')
        ->and($blocked->json('message'))->toBe('Too Many Attempts.');
});

test('decrypt and change-password are throttled with a generic error', function (string $method, string $routeName, array $input) {
    $this->postJson(route('vaults.encryption.store', $this->vault), encryptInput());

    for ($attempt = 1; $attempt <= 10; $attempt++) {
        $this->{$method}(route($routeName, $this->vault), $input)->assertUnprocessable();
    }

    $blocked = $this->{$method}(route($routeName, $this->vault), $input);

    $blocked->assertStatus(429);
    expect($blocked->json('message'))->toBe('Too Many Attempts.');
})->with([
    'decrypt' => ['deleteJson', 'vaults.encryption.destroy', ['password' => 'wrong wrong wrong', 'acknowledge' => true]],
    'change password' => ['putJson', 'vaults.encryption.password.update', ['current_password' => 'nope nope nope', 'password' => 'yet another password', 'password_confirmation' => 'yet another password']],
]);
