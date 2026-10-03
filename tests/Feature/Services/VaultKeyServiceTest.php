<?php

use App\Enums\SettingKey;
use App\Exceptions\VaultLockedException;
use App\Http\Middleware\ProvideVaultKeys;
use App\Models\VaultEncryption;
use App\Services\EncryptionService;
use App\Services\SettingsService;
use App\Services\VaultKeyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Native\Desktop\Events\PowerMonitor\ScreenLocked;

beforeEach(function () {
    $this->tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mdvault-keyring-'.Str::random(8);
    fakeDocumentsDirectory($this->tmp.DIRECTORY_SEPARATOR.'Documents');

    [$this->vault, $this->token] = encryptedVault('Secrets');
    $this->keys = app(VaultKeyService::class);
    $this->header = $this->vault->uuid.':'.$this->token;
});

afterEach(function () {
    File::deleteDirectory($this->tmp);
});

test('a stored key opens with its token and only with its token', function () {
    $this->keys->provideTokens($this->header);

    expect($this->keys->keyFor($this->vault))->not->toBeNull()
        ->and($this->keys->isUnlocked($this->vault))->toBeTrue()
        ->and($this->keys->requireKey($this->vault)->keyId)->toBe($this->vault->encryption->key_id);

    $this->keys->provideTokens(null);
    expect($this->keys->keyFor($this->vault))->toBeNull()
        ->and($this->keys->isUnlocked($this->vault))->toBeFalse();

    $this->keys->provideTokens($this->vault->uuid.':'.app(EncryptionService::class)->newSessionToken());
    expect($this->keys->keyFor($this->vault))->toBeNull();

    // A missing or wrong token is a locked request, not a purge: the right
    // token still works afterwards.
    $this->keys->provideTokens($this->header);
    expect($this->keys->keyFor($this->vault))->not->toBeNull();
});

test('requireKey throws the locked exception when locked', function () {
    $this->keys->provideTokens(null);

    $this->keys->requireKey($this->vault);
})->throws(VaultLockedException::class, 'This vault is locked. Unlock it to continue.');

test('forget locks one vault and forgetAll locks every vault', function () {
    [$other, $otherToken] = encryptedVault('Other');
    $this->keys->provideTokens($this->header.','.$other->uuid.':'.$otherToken);

    expect($this->keys->isUnlocked($this->vault))->toBeTrue()
        ->and($this->keys->isUnlocked($other))->toBeTrue();

    $this->keys->forget($this->vault);
    $this->keys->provideTokens($this->header.','.$other->uuid.':'.$otherToken);

    expect($this->keys->isUnlocked($this->vault))->toBeFalse()
        ->and($this->keys->isUnlocked($other))->toBeTrue();

    $this->keys->forgetAll();
    $this->keys->provideTokens($this->header.','.$other->uuid.':'.$otherToken);

    expect($this->keys->isUnlocked($other))->toBeFalse()
        ->and(session()->get('mdvault.keyring'))->toBeNull();
});

test('an entry idle for more than the limit plus the grace period is purged', function () {
    $this->keys->provideTokens($this->header);

    $this->travel(15 * 60 + VaultKeyService::IDLE_GRACE_SECONDS - 5)->seconds();
    expect($this->keys->keyFor($this->vault, false))->not->toBeNull();

    $this->travel(10)->seconds();
    expect($this->keys->keyFor($this->vault))->toBeNull()
        ->and(session()->has(VaultKeyService::SESSION_PREFIX.$this->vault->uuid))->toBeFalse();

    // Purged for good: the token no longer helps.
    expect($this->keys->keyFor($this->vault))->toBeNull();
});

test('touch false does not extend the idle timer but touch true does', function () {
    $this->keys->provideTokens($this->header);

    $this->travel(10 * 60)->seconds();
    $this->keys->keyFor($this->vault, false);
    $this->travel(8 * 60)->seconds();

    expect($this->keys->keyFor($this->vault))->toBeNull();

    [$vault, $token] = encryptedVault('Second');
    $this->keys->provideTokens($vault->uuid.':'.$token);

    $this->travel(10 * 60)->seconds();
    $this->keys->keyFor($vault, true);
    $this->travel(8 * 60)->seconds();

    expect($this->keys->keyFor($vault))->not->toBeNull();
});

test('auto-lock 0 never expires', function () {
    app(SettingsService::class)->set(SettingKey::SecurityAutoLockMinutes, 0);
    $this->keys->provideTokens($this->header);

    $this->travel(30)->days();

    expect($this->keys->keyFor($this->vault))->not->toBeNull();
});

test('a configured auto-lock limit is honoured', function () {
    app(SettingsService::class)->set(SettingKey::SecurityAutoLockMinutes, 5);
    $this->keys->provideTokens($this->header);

    $this->travel(5 * 60 + VaultKeyService::IDLE_GRACE_SECONDS + 1)->seconds();

    expect($this->keys->keyFor($this->vault))->toBeNull();
});

test('lockEverywhere invalidates every entry', function () {
    $this->keys->provideTokens($this->header);
    $this->keys->lockEverywhere();
    $this->keys->provideTokens($this->header);

    expect($this->keys->keyFor($this->vault))->toBeNull()
        ->and(cache()->get(VaultKeyService::EPOCH_CACHE_KEY))->toBe(1);
});

test('an entry sealed in another session stops working after lockEverywhere', function () {
    $entry = session()->get(VaultKeyService::SESSION_PREFIX.$this->vault->uuid);

    $this->keys->lockEverywhere();

    // Simulates a different session still holding its own sealed entry.
    session()->put(VaultKeyService::SESSION_PREFIX.$this->vault->uuid, $entry);
    $this->keys->provideTokens($this->header);

    expect($this->keys->keyFor($this->vault))->toBeNull();
});

test('the screen lock event locks everything when the setting is on', function () {
    $this->keys->provideTokens($this->header);

    ScreenLocked::dispatch();

    $this->keys->provideTokens($this->header);
    expect($this->keys->keyFor($this->vault))->toBeNull();
});

test('the screen lock event leaves vaults unlocked when the setting is off', function () {
    app(SettingsService::class)->set(SettingKey::SecurityLockOnScreenLock, false);
    $this->keys->provideTokens($this->header);

    ScreenLocked::dispatch();

    $this->keys->provideTokens($this->header);
    expect($this->keys->keyFor($this->vault))->not->toBeNull();
});

test('a key id that no longer matches the registry is locked', function () {
    $this->keys->provideTokens($this->header);
    expect($this->keys->keyFor($this->vault))->not->toBeNull();

    VaultEncryption::query()->where('vault_id', $this->vault->id)->update(['key_id' => '0198a000-0000-7000-8000-00000000ffff']);

    expect($this->keys->keyFor($this->vault))->toBeNull()
        ->and(session()->has(VaultKeyService::SESSION_PREFIX.$this->vault->uuid))->toBeFalse();
});

test('the session never holds the data key or the token', function () {
    $this->keys->provideTokens($this->header);
    $material = $this->keys->keyFor($this->vault)->material();
    $stored = serialize(session()->all());

    foreach ([$material, base64_encode($material), bin2hex($material), $this->token, strtr(base64_encode($material), '+/', '-_')] as $needle) {
        expect(str_contains($stored, $needle))->toBeFalse();
    }

    expect($stored)->toContain($this->vault->uuid);
});

test('the header is capped at 20 pairs and malformed pairs are ignored', function () {
    $tokenFor = fn () => app(EncryptionService::class)->newSessionToken();
    $filler = [];

    for ($i = 0; $i < 20; $i++) {
        $filler[] = (string) Str::uuid().':'.$tokenFor();
    }

    // The real pair is the 21st: ignored.
    $this->keys->provideTokens(implode(',', [...$filler, $this->header]));
    expect($this->keys->keyFor($this->vault))->toBeNull();

    // The real pair is the 20th: read.
    $this->keys->provideTokens(implode(',', [...array_slice($filler, 0, 19), $this->header]));
    expect($this->keys->keyFor($this->vault))->not->toBeNull();

    // Malformed pairs are skipped without affecting valid ones.
    $this->keys->provideTokens('garbage,:,a:b:c,'.$this->vault->uuid.':short,'.$this->header);
    expect($this->keys->keyFor($this->vault))->not->toBeNull();
});

test('each request replaces the tokens of the previous one', function () {
    $this->keys->provideTokens($this->header);
    expect($this->keys->keyFor($this->vault))->not->toBeNull();

    $this->keys->provideTokens('');
    expect($this->keys->keyFor($this->vault))->toBeNull();
});

test('the ProvideVaultKeys middleware hands the header to the keyring', function () {
    $middleware = app(ProvideVaultKeys::class);
    $request = Request::create('/', 'GET', server: ['HTTP_X_MDVAULT_UNLOCK' => $this->header]);
    $seen = null;

    $middleware->handle($request, function () use (&$seen) {
        $seen = $this->keys->isUnlocked($this->vault);

        return response('ok');
    });

    expect($seen)->toBeTrue();

    $middleware->handle(Request::create('/'), function () use (&$seen) {
        $seen = $this->keys->isUnlocked($this->vault);

        return response('ok');
    });

    expect($seen)->toBeFalse();
});

test('a negative auto-lock value falls back to the default and never fails open', function () {
    app(SettingsService::class)->set(SettingKey::SecurityAutoLockMinutes, -5);
    $this->keys->provideTokens($this->header);

    $this->travel(10 * 60)->seconds();
    expect($this->keys->keyFor($this->vault, false))->not->toBeNull();

    $this->travel(5 * 60 + VaultKeyService::IDLE_GRACE_SECONDS + 1)->seconds();
    expect($this->keys->keyFor($this->vault, false))->toBeNull();
});

test('a corrupt stored auto-lock value uses the default', function () {
    DB::table('settings')->updateOrInsert(['key' => SettingKey::SecurityAutoLockMinutes->value], ['value' => 'not a number', 'type' => 'integer', 'group' => 'security']);
    app()->forgetInstance(SettingsService::class);
    app()->forgetInstance(VaultKeyService::class);

    $keys = app(VaultKeyService::class);
    $keys->provideTokens($this->header);

    $this->travel(15 * 60 + VaultKeyService::IDLE_GRACE_SECONDS + 1)->seconds();

    expect($keys->keyFor($this->vault, false))->toBeNull();
});

test('opened keys and tokens are cleared when the request terminates', function () {
    $this->keys->provideTokens($this->header);
    $this->keys->keyFor($this->vault);

    $read = fn (string $property) => (new ReflectionProperty(VaultKeyService::class, $property))->getValue($this->keys);

    expect($read('opened'))->not->toBeEmpty()
        ->and($read('tokens'))->not->toBeEmpty();

    app()->terminate();

    expect($read('opened'))->toBe([])
        ->and($read('tokens'))->toBe([])
        ->and($this->keys->keyFor($this->vault))->toBeNull();
});

test('a missing or wrong token leaves the keyring entry in place', function () {
    $entry = VaultKeyService::SESSION_PREFIX.$this->vault->uuid;

    $this->keys->provideTokens(null);
    expect($this->keys->keyFor($this->vault))->toBeNull()
        ->and(session()->has($entry))->toBeTrue();

    $this->keys->provideTokens($this->vault->uuid.':'.app(EncryptionService::class)->newSessionToken());
    expect($this->keys->keyFor($this->vault))->toBeNull()
        ->and(session()->has($entry))->toBeTrue();

    $this->keys->provideTokens($this->header);
    expect($this->keys->keyFor($this->vault))->not->toBeNull();
});

test('the security prop is shared lazily', function () {
    app(SettingsService::class)->set(SettingKey::SecurityAutoLockMinutes, 30);
    app(SettingsService::class)->set(SettingKey::SecurityLockOnScreenLock, false);

    $this->get('/')->assertInertia(fn ($page) => $page
        ->where('security.auto_lock_minutes', 30)
        ->where('security.lock_on_screen_lock', false));

    // A partial reload that does not ask for it never evaluates it.
    $response = $this->withHeaders([
        'X-Inertia' => 'true',
        'X-Inertia-Version' => Inertia\Inertia::getVersion(),
        'X-Inertia-Partial-Component' => 'Workspace',
        'X-Inertia-Partial-Data' => 'treeSignature',
    ])->get('/');

    $response->assertOk();
    expect($response->json('props'))->not->toHaveKey('security')
        ->and($response->json('props'))->not->toHaveKey('vaults');
});
