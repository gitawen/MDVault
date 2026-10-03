<?php

/**
 * The mandatory Phase 7 security suite (plan T17, Master Plan §56).
 *
 * Every scenario plants canaries (a note body, note and folder names, the
 * vault password) and then looks for them, and for the key material, in
 * every place a secret could leak: files and folder names on disk, every
 * SQLite row, the session, logs, exception messages and traces, Inertia
 * props and HTTP headers.
 */

use App\Exceptions\EncryptionException;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Note;
use App\Models\Vault;
use App\Models\VaultEncryption;
use App\Services\BackupService;
use App\Services\EncryptionService;
use App\Services\VaultConversionService;
use App\Services\VaultEncryptionService;
use App\Services\VaultIndexService;
use App\Services\VaultKeyService;
use App\Services\VaultService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Native\Desktop\Events\PowerMonitor\ScreenLocked;

const SEC_CONTENT = 'CANARY-CONTENT-7f3a';
const SEC_NOTE = 'Bank Accounts';
const SEC_FOLDER = 'My Credentials';
const SEC_PASSWORD = 'Canary-Password-91!';
const SEC_NEW_PASSWORD = 'Another-Canary-Phrase-77?';

beforeEach(function () {
    $this->tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mdvault-sec-'.Str::random(8);
    fakeDocumentsDirectory($this->tmp.DIRECTORY_SEPARATOR.'Documents');
    File::makeDirectory($this->tmp.DIRECTORY_SEPARATOR.'Documents', 0755, true);
    $this->root = $this->tmp.DIRECTORY_SEPARATOR.'Documents'.DIRECTORY_SEPARATOR.'MDVault';

    $this->tokens = [];
    $this->inertia = fn (): array => ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request())];

    // The vault is created, filled and edited through the HTTP layer.
    $created = $this->postJson(route('vaults.encrypted.store'), [
        'name' => 'Vault Alpha',
        'password' => SEC_PASSWORD,
        'password_confirmation' => SEC_PASSWORD,
        'acknowledge' => true,
    ])->assertCreated();

    $this->vault = Vault::query()->where('uuid', $created->json('uuid'))->firstOrFail();
    $this->token = $created->json('token');
    $this->tokens[] = $this->token;
    $this->withHeader(VaultKeyService::HEADER, $this->vault->uuid.':'.$this->token);

    $this->post(route('vaults.folders.store', $this->vault), ['name' => SEC_FOLDER, 'parent' => ''])->assertRedirect();
    $this->post(route('vaults.notes.store', $this->vault), ['name' => SEC_NOTE, 'folder' => SEC_FOLDER, 'timezone' => 'UTC'])->assertRedirect();
    $this->note = Note::query()->where('vault_id', $this->vault->id)->latest('id')->firstOrFail();
    $this->putJson(route('notes.content.update', $this->note->uuid), [
        'content' => SEC_CONTENT,
        'base_hash' => $this->note->file_hash,
        'mode' => 'source',
    ])->assertOk();
});

afterEach(function () {
    DB::unprepared('DROP TRIGGER IF EXISTS sec_fail');
    File::deleteDirectory($this->tmp);
});

/**
 * Searchable encodings of a binary secret: hex, base64 and base64url, each
 * with and without padding.
 *
 * @return list<string>
 */
function secEncodings(string $binary): array
{
    $base64 = base64_encode($binary);
    $url = strtr($base64, '+/', '-_');

    return array_values(array_unique([bin2hex($binary), $base64, rtrim($base64, '='), $url, rtrim($url, '=')]));
}

/**
 * The data key of $vault, obtained by unlocking through EncryptionService
 * with the password (a test-only path), as searchable encodings.
 *
 * @return list<string>
 */
function secKeyNeedles(Vault $vault, string $password): array
{
    [$header] = app(VaultEncryptionService::class)->readHeader($vault);
    $key = app(EncryptionService::class)->unlock($header, $password);

    return secEncodings($key->material());
}

/**
 * Every canary and every secret planted so far.
 *
 * @return list<string>
 */
function secNeedles(array $extra = []): array
{
    // The vault's password may have been changed during the scenario.
    $keyNeedles = [];

    foreach ([SEC_PASSWORD, SEC_NEW_PASSWORD] as $password) {
        try {
            $keyNeedles = secKeyNeedles(test()->vault->refresh(), $password);

            break;
        } catch (EncryptionException) {
            continue;
        }
    }

    $needles = [
        SEC_CONTENT, SEC_NOTE, SEC_FOLDER, SEC_PASSWORD, SEC_NEW_PASSWORD,
        'Renamed Account', 'Moved Folder', 'Legacy Plan', 'Legacy Folder',
        ...test()->tokens,
        ...$keyNeedles,
        ...$extra,
    ];

    return array_values(array_unique($needles));
}

/**
 * Fails if a needle occurs in any entry name or body of a ZIP.
 *
 * @param  list<string>  $needles
 */
function secAssertZipClean(string $zipPath, array $needles): void
{
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::RDONLY);

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);
        $body = (string) $zip->getFromIndex($i);

        foreach ($needles as $needle) {
            expect(stripos($name, $needle))->toBeFalse('A backup entry name contains a needle.');
            expect(stripos($body, $needle))->toBeFalse('A backup entry body contains a needle.');
        }
    }

    $zip->close();
}

/**
 * Requests that need the vault key, each as [method, url, payload]. All
 * are sent as JSON so a locked vault answers 423.
 *
 * @return list<array{0: string, 1: string, 2: array<string, mixed>}>
 */
function secKeyRequiringRequests(Vault $vault, Note $note): array
{
    return [
        ['getJson', route('notes.disk.show', $note->uuid), []],
        ['putJson', route('notes.content.update', $note->uuid), ['content' => 'x', 'base_hash' => str_repeat('0', 64), 'mode' => 'source']],
        ['postJson', route('vaults.notes.store', $vault), ['name' => 'Another', 'folder' => '', 'timezone' => 'UTC']],
        ['postJson', route('vaults.folders.store', $vault), ['name' => 'Another Folder', 'parent' => '']],
        ['patchJson', route('notes.update', $note->uuid), ['name' => 'Renamed']],
        ['postJson', route('notes.move', $note->uuid), ['folder' => '']],
        ['deleteJson', route('notes.destroy', $note->uuid), []],
        ['postJson', route('vaults.notes.copy', $vault), ['source_path' => 'x.md', 'content' => 'x', 'mode' => 'source']],
    ];
}

function secAssertEverythingLocked(object $test, Vault $vault, Note $note, string $token): void
{
    $test->withHeader(VaultKeyService::HEADER, $vault->uuid.':'.$token);

    foreach (secKeyRequiringRequests($vault, $note) as [$method, $url, $payload]) {
        $test->{$method}($url, $payload)
            ->assertStatus(423)
            ->assertJsonPath('reason', 'locked');
    }

    $page = $test->get(route('workspace'), ($test->inertia)());

    expect($page->json('props.tree'))->toBeNull()
        ->and($page->json('props.note'))->toBeNull()
        ->and($page->json('props.currentVault.is_unlocked'))->toBeFalse();

    foreach (['Bank Accounts', 'My Credentials', SEC_CONTENT, $token] as $needle) {
        expect($page->getContent())->not->toContain($needle);
    }
}

// --- 1 to 3: disk, SQLite and session across a whole lifecycle -------------

test('create, edit, rename, move, folder operations, password change, conversion and backup leave no canary on disk, in SQLite or in the session', function () {
    // Rename and move through HTTP.
    $this->patch(route('notes.update', $this->note->uuid), ['name' => 'Renamed Account'])->assertRedirect();
    $this->post(route('vaults.folders.store', $this->vault), ['name' => 'Moved Folder', 'parent' => ''])->assertRedirect();
    $this->post(route('notes.move', $this->note->uuid), ['folder' => 'Moved Folder'])->assertRedirect();
    $this->post(route('vaults.folders.store', $this->vault), ['name' => 'Scratch Folder', 'parent' => 'Moved Folder'])->assertRedirect();
    $this->delete(route('vaults.folders.destroy', $this->vault), ['path' => 'Moved Folder/Scratch Folder'])->assertRedirect();

    // A failed validation with the canaries in the request.
    $this->from(route('workspace'))->post(route('vaults.notes.store', $this->vault), ['name' => 'Renamed Account', 'folder' => 'Moved Folder', 'content' => SEC_CONTENT, 'timezone' => 'UTC'])
        ->assertSessionHasErrors('name');

    // Change the password.
    $this->from(route('vaults.index'))->put(route('vaults.encryption.password.update', $this->vault), [
        'current_password' => SEC_PASSWORD,
        'password' => SEC_NEW_PASSWORD,
        'password_confirmation' => SEC_NEW_PASSWORD,
    ])->assertSessionHasNoErrors();

    // Encrypt an existing plaintext vault that holds more canaries.
    $legacy = app(VaultService::class)->create('Legacy');
    writeVaultFiles($legacy->path, [
        'Legacy Plan.md' => SEC_CONTENT,
        'Legacy Folder/Legacy Plan.md' => SEC_CONTENT,
    ]);
    app(VaultIndexService::class)->reindex($legacy);

    $encrypted = $this->postJson(route('vaults.encryption.store', $legacy), [
        'password' => SEC_NEW_PASSWORD,
        'password_confirmation' => SEC_NEW_PASSWORD,
        'acknowledge' => true,
        'acknowledge_delete' => true,
    ])->assertOk();

    $this->tokens[] = $encrypted->json('token');

    // Back up everything.
    $zip = $this->tmp.DIRECTORY_SEPARATOR.'backup.zip';
    app(BackupService::class)->create(null, $zip);

    $needles = secNeedles([...secKeyNeedles($legacy->refresh(), SEC_NEW_PASSWORD)]);

    // Names (readable vault names aside) and bytes under the whole storage root, and the backup.
    assertNoNeedlesUnder($this->root, $needles);
    secAssertZipClean($zip, $needles);

    // Every SQLite row.
    assertNoNeedlesInDatabase($needles);

    // The session, after all of the above (including the failed validation).
    $stored = serialize(session()->all());

    foreach ($needles as $needle) {
        expect(stripos($stored, $needle))->toBeFalse('The session contains a needle.');
    }

    // Only opaque names remain on disk.
    foreach (File::allFiles($this->root) as $file) {
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($this->root) + 1));
        $inside = explode('/', $relative, 2)[1];

        expect($inside)->toMatch('#^(mdvault-encryption\.json|([0-9a-f]{32}/)*([0-9a-f]{32}\.mdenc|folder\.mdenc))$#');
    }
});

test('SQLite holds only opaque paths and ciphertext hashes for encrypted notes', function () {
    $row = Note::query()->where('vault_id', $this->vault->id)->firstOrFail();

    expect($row->relative_path)->toMatch('#^[0-9a-f]{32}/[0-9a-f]{32}\.mdenc$#')
        ->and($row->title)->toMatch('/^[0-9a-f]{32}$/')
        ->and($row->filename)->toMatch('/^[0-9a-f]{32}\.mdenc$/')
        ->and($row->mime_type)->toBe(Note::ENCRYPTED_MIME_TYPE)
        ->and($row->file_hash)->toBe(hash_file('sha256', $this->vault->path.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $row->relative_path)));

    // The mirror holds the non-secret key file contents and nothing else.
    expect(VaultEncryption::query()->count())->toBe(1);
    assertNoNeedlesInDatabase(secNeedles());
});

test('the session never holds the password, the token, the data key, names or content, even after failed attempts', function () {
    $this->postJson(route('vaults.unlock', $this->vault), ['password' => 'Canary-Password-91!wrong'])->assertUnprocessable();
    $this->postJson(route('vaults.encrypted.store'), ['name' => 'Rejected', 'password' => SEC_PASSWORD, 'password_confirmation' => 'different', 'acknowledge' => true])->assertUnprocessable();
    $this->from(route('vaults.index'))->delete(route('vaults.encryption.destroy', $this->vault), ['password' => 'Canary-Password-91!wrong', 'acknowledge' => true])->assertSessionHasErrors('password');
    $this->get(route('notes.show', $this->note->uuid), ($this->inertia)())->assertOk();

    $stored = serialize(session()->all());

    foreach (secNeedles(['Canary-Password-91!wrong']) as $needle) {
        expect(stripos($stored, $needle))->toBeFalse('The session contains a needle.');
    }

    // The sealed key entry exists, but only its sealed form.
    expect(session()->has(VaultKeyService::SESSION_PREFIX.$this->vault->uuid))->toBeTrue();
});

// --- 4: logs and exceptions -------------------------------------------------

test('logs and exceptions never carry a password, a name, content, a token or a key', function () {
    $logs = captureLogs();
    $crypto = app(EncryptionService::class);
    $service = app(VaultEncryptionService::class);
    $thrown = [];

    $capture = function (Closure $callback) use (&$thrown): void {
        try {
            $callback();
        } catch (Throwable $e) {
            $thrown[] = $e->getMessage()."\n".$e->getTraceAsString();
        }
    };

    // A wrong password.
    $this->postJson(route('vaults.unlock', $this->vault), ['password' => 'Canary-Password-91!wrong'])->assertUnprocessable();
    $capture(fn () => $service->unlock($this->vault->refresh(), 'Canary-Password-91!wrong'));
    $capture(fn () => $crypto->unlock($service->readHeader($this->vault)[0], 'Canary-Password-91!wrong'));

    // A tampered note.
    $victim = Note::query()->where('vault_id', $this->vault->id)->firstOrFail();
    $file = $this->vault->path.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $victim->relative_path);
    $original = File::get($file);
    File::put($file, substr($original, 0, -4).'xxxx');
    $this->get(route('notes.show', $victim->uuid), ($this->inertia)())->assertOk();
    $this->getJson(route('notes.disk.show', $victim->uuid));
    File::put($file, $original);

    // A DB failure while saving.
    DB::unprepared('create trigger sec_fail before update on notes begin select raise(abort, "boom"); end');
    $this->putJson(route('notes.content.update', $victim->uuid), ['content' => SEC_CONTENT.' again', 'base_hash' => $victim->fresh()->file_hash, 'mode' => 'source']);
    DB::unprepared('drop trigger sec_fail');

    // A DB failure while syncing the mirror during unlock.
    $bytes = File::get($this->vault->path.DIRECTORY_SEPARATOR.VaultEncryptionService::HEADER_FILENAME);
    $data = json_decode($bytes, true);
    $data['created_at'] = '2031-02-03T04:05:06Z';
    File::put($this->vault->path.DIRECTORY_SEPARATOR.VaultEncryptionService::HEADER_FILENAME, $crypto->encodeHeader($crypto->parseHeader(json_encode($data))));
    DB::unprepared('create trigger sec_fail before update on vault_encryption begin select raise(abort, "boom"); end');
    $this->postJson(route('vaults.unlock', $this->vault), ['password' => SEC_PASSWORD])->assertOk();
    DB::unprepared('drop trigger sec_fail');

    // A damaged key file.
    File::put($this->vault->path.DIRECTORY_SEPARATOR.VaultEncryptionService::HEADER_FILENAME, '{"not":"a key file"}');
    $this->postJson(route('vaults.unlock', $this->vault), ['password' => SEC_PASSWORD])->assertUnprocessable();
    $capture(fn () => $service->unlock($this->vault->refresh(), SEC_PASSWORD));
    File::put($this->vault->path.DIRECTORY_SEPARATOR.VaultEncryptionService::HEADER_FILENAME, $bytes);

    // Conversion failures: a DB failure and a swap failure.
    $legacy = app(VaultService::class)->create('Legacy');
    writeVaultFiles($legacy->path, ['Legacy Plan.md' => SEC_CONTENT, 'Legacy Folder/Note.md' => SEC_CONTENT]);
    app(VaultIndexService::class)->reindex($legacy);

    DB::unprepared('create trigger sec_fail before update on notes begin select raise(abort, "boom"); end');
    $capture(fn () => app(VaultConversionService::class)->encrypt($legacy, SEC_NEW_PASSWORD));
    DB::unprepared('drop trigger sec_fail');

    failFolderRenames([1]);
    $capture(fn () => app(VaultConversionService::class)->encrypt($legacy->refresh(), SEC_NEW_PASSWORD));

    // The harness really sees reports: the DB failures above are logged.
    expect($thrown)->not->toBeEmpty()->and($logs)->not->toBeEmpty();

    $needles = secNeedles(['Canary-Password-91!wrong']);

    foreach ([...$logs->all(), ...$thrown] as $text) {
        foreach ($needles as $needle) {
            expect(stripos($text, $needle))->toBeFalse('A log line or exception contains a needle.');
        }
    }
});

// --- 5: Inertia props -------------------------------------------------------

test('a locked workspace response has no names or content and an unlocked one no key material', function () {
    $locked = $this->withHeader(VaultKeyService::HEADER, '')->get(route('notes.show', $this->note->uuid), ($this->inertia)());

    expect($locked->json('props.tree'))->toBeNull()
        ->and($locked->json('props.note'))->toBeNull();

    foreach ([SEC_NOTE, SEC_FOLDER, SEC_CONTENT, 'Renamed Account'] as $needle) {
        expect($locked->getContent())->not->toContain($needle);
    }

    $this->withHeader(VaultKeyService::HEADER, $this->vault->uuid.':'.$this->token);
    $unlocked = $this->get(route('notes.show', $this->note->uuid), ($this->inertia)());

    expect($unlocked->json('props.note.content'))->toBe(SEC_CONTENT);

    $row = VaultEncryption::query()->first()->makeVisible(['salt', 'nonce', 'encrypted_key']);
    $forbidden = [...secKeyNeedles($this->vault->refresh(), SEC_PASSWORD), $this->token, $row->salt, $row->nonce, $row->encrypted_key, SEC_PASSWORD, 'wrapped_key'];

    foreach ($forbidden as $needle) {
        expect(stripos($unlocked->getContent(), $needle))->toBeFalse('An unlocked response contains key material.');
    }
});

test('the shared vault summaries expose only booleans for encryption', function () {
    $page = $this->get(route('workspace'), ($this->inertia)());

    foreach ($page->json('props.vaults') as $summary) {
        expect(array_keys($summary))->toEqualCanonicalizing(['uuid', 'name', 'description', 'path', 'relative_path', 'status', 'is_current', 'is_encrypted', 'is_unlocked'])
            ->and($summary['is_encrypted'])->toBeBool()
            ->and($summary['is_unlocked'])->toBeBool();
    }

    expect($page->json('props.security'))->toHaveKeys(['auto_lock_minutes', 'lock_on_screen_lock'])
        ->and(array_keys($page->json('props.security')))->toHaveCount(2);
});

test('only the unlock and create responses ever carry a token', function () {
    $unlock = $this->postJson(route('vaults.unlock', $this->vault), ['password' => SEC_PASSWORD]);
    $this->tokens[] = $unlock->json('token');

    expect(array_keys($unlock->json()))->toBe(['token']);

    $this->withHeader(VaultKeyService::HEADER, $this->vault->uuid.':'.$unlock->json('token'));

    foreach ([
        $this->get(route('workspace'), ($this->inertia)()),
        $this->get(route('vaults.index'), ($this->inertia)()),
        $this->getJson(route('notes.disk.show', $this->note->uuid)),
        $this->post(route('vaults.lock', $this->vault)),
    ] as $response) {
        foreach ($this->tokens as $token) {
            expect(stripos((string) $response->getContent(), $token))->toBeFalse('A response carries a token.');
        }
    }
});

// --- 6: lock ----------------------------------------------------------------

test('after lock every key-requiring endpoint is locked and the old token stays dead after a re-unlock', function () {
    $this->post(route('vaults.lock', $this->vault))->assertRedirect(route('workspace'));

    secAssertEverythingLocked($this, $this->vault, $this->note, $this->token);

    // Unlock again: a new token works, the replayed old one does not.
    $fresh = $this->postJson(route('vaults.unlock', $this->vault), ['password' => SEC_PASSWORD])->json('token');
    $this->tokens[] = $fresh;

    expect($fresh)->not->toBe($this->token);

    $this->withHeader(VaultKeyService::HEADER, $this->vault->uuid.':'.$fresh)
        ->getJson(route('notes.disk.show', $this->note->uuid))->assertOk();

    secAssertEverythingLocked($this, $this->vault, $this->note, $this->token);
});

test('after lock all, every endpoint is locked for every vault', function () {
    $this->post(route('vaults.lock.all'))->assertRedirect(route('workspace'));

    secAssertEverythingLocked($this, $this->vault, $this->note, $this->token);
});

test('after the idle limit every endpoint is locked', function () {
    $this->getJson(route('notes.disk.show', $this->note->uuid))->assertOk();

    $this->travel(15 * 60 + VaultKeyService::IDLE_GRACE_SECONDS + 5)->seconds();

    secAssertEverythingLocked($this, $this->vault, $this->note, $this->token);
});

test('a screen lock locks every endpoint', function () {
    $this->getJson(route('notes.disk.show', $this->note->uuid))->assertOk();

    ScreenLocked::dispatch();

    secAssertEverythingLocked($this, $this->vault, $this->note, $this->token);
});

test('a fresh session with no header is locked, and a token alone is not enough', function () {
    $this->getJson(route('notes.disk.show', $this->note->uuid))->assertOk();

    // A new session (a reload, a restart) has no sealed key entry.
    $this->flushSession();

    secAssertEverythingLocked($this, $this->vault, $this->note, $this->token);

    // No header at all, with a valid session entry, is locked too.
    $this->withHeader(VaultKeyService::HEADER, '');
    $this->postJson(route('vaults.unlock', $this->vault), ['password' => SEC_PASSWORD])->assertOk();
    $this->withHeader(VaultKeyService::HEADER, '');
    $this->getJson(route('notes.disk.show', $this->note->uuid))->assertStatus(423);
});

// --- 7: passwords -----------------------------------------------------------

test('a wrong password never unlocks and the correct one does', function () {
    $this->post(route('vaults.lock', $this->vault));

    foreach (['', 'wrong password!!', SEC_PASSWORD.' ', ' '.SEC_PASSWORD, strtoupper(SEC_PASSWORD), substr(SEC_PASSWORD, 0, -1), SEC_NEW_PASSWORD] as $attempt) {
        $response = $this->postJson(route('vaults.unlock', $this->vault), ['password' => $attempt]);

        $response->assertUnprocessable();
        expect($response->json('token'))->toBeNull();
        expect(session()->has(VaultKeyService::SESSION_PREFIX.$this->vault->uuid))->toBeFalse();
    }

    $this->postJson(route('vaults.unlock', $this->vault), ['password' => SEC_PASSWORD])
        ->assertOk()
        ->assertJsonStructure(['token']);
});

test('the old password stops working after a password change', function () {
    $this->from(route('vaults.index'))->put(route('vaults.encryption.password.update', $this->vault), [
        'current_password' => SEC_PASSWORD,
        'password' => SEC_NEW_PASSWORD,
        'password_confirmation' => SEC_NEW_PASSWORD,
    ])->assertSessionHasNoErrors();

    $this->post(route('vaults.lock', $this->vault));

    $this->postJson(route('vaults.unlock', $this->vault), ['password' => SEC_PASSWORD])->assertUnprocessable();
    $this->postJson(route('vaults.unlock', $this->vault), ['password' => SEC_NEW_PASSWORD])->assertOk();
});

// --- 8 and 9: cache and KDF bounds -----------------------------------------

test('workspace responses are never cacheable', function () {
    foreach ([route('workspace'), route('notes.show', $this->note->uuid), route('vaults.index')] as $url) {
        $cacheControl = (string) $this->get($url)->headers->get('Cache-Control');

        expect($cacheControl)->toContain('no-store')->and($cacheControl)->toContain('private');
    }
});

test('a hostile key file with extreme KDF parameters is rejected quickly without running the KDF', function (string $mutation) {
    $path = $this->vault->path.DIRECTORY_SEPARATOR.VaultEncryptionService::HEADER_FILENAME;
    $data = json_decode(File::get($path), true);

    match ($mutation) {
        'memory' => $data['kdf']['memlimit'] = 2 ** 31,
        'too many passes' => $data['kdf']['opslimit'] = 11,
        'zero passes' => $data['kdf']['opslimit'] = 0,
        'tiny memory' => $data['kdf']['memlimit'] = 1024,
    };

    File::put($path, json_encode($data));
    $this->post(route('vaults.lock', $this->vault));

    $started = microtime(true);
    $response = $this->postJson(route('vaults.unlock', $this->vault), ['password' => SEC_PASSWORD]);
    $elapsed = microtime(true) - $started;

    $response->assertUnprocessable();
    expect($elapsed)->toBeLessThan(1.0)
        ->and($response->json('token'))->toBeNull()
        ->and(session()->has(VaultKeyService::SESSION_PREFIX.$this->vault->uuid))->toBeFalse();
})->with(['memory', 'too many passes', 'zero passes', 'tiny memory']);

test('an unlock attempt that fails leaves the thrown exception free of the password', function () {
    try {
        app(VaultEncryptionService::class)->unlock($this->vault->refresh(), 'Canary-Password-91!wrong');
        $this->fail('Expected a failure.');
    } catch (EncryptionException $e) {
        expect($e->getMessage().$e->getTraceAsString())->not->toContain('Canary-Password-91!wrong');
    }
});
