<?php

use App\Exceptions\EncryptionException;
use App\Services\EncryptionService;
use App\Support\EncryptionHeader;
use App\Support\VaultKey;

const ENC_TEST_PASSWORD = 'Canary-Password-91!';

beforeEach(function () {
    $this->crypto = app(EncryptionService::class);
    [$this->header, $this->key] = $this->crypto->newVaultKey(ENC_TEST_PASSWORD);
    $this->fileId = $this->crypto->newFileId();
});

/** Re-encodes $header with $mutate applied to the decoded key-file array. */
function tamperedHeaderBytes(EncryptionHeader $header, Closure $mutate): string
{
    $data = json_decode(app(EncryptionService::class)->encodeHeader($header), true);
    $data = $mutate($data);

    return json_encode($data, JSON_UNESCAPED_SLASHES);
}

function expectCrypto(Closure $callback, string $factory): void
{
    try {
        $callback();
        test()->fail('Expected an EncryptionException.');
    } catch (EncryptionException $e) {
        expect($e->getMessage())->toBe(EncryptionException::$factory()->getMessage());
    }
}

function flipFirstByte(string $raw): string
{
    $raw[0] = $raw[0] ^ "\x01";

    return $raw;
}

test('a key file round trips through encode and parse', function () {
    $parsed = $this->crypto->parseHeader($this->crypto->encodeHeader($this->header));

    expect($parsed)->toEqual($this->header)
        ->and($this->header->keyVersion)->toBe(1)
        ->and($this->header->cipher)->toBe('xchacha20poly1305-ietf')
        ->and($this->header->kdfAlgorithm)->toBe('argon2id13')
        ->and($this->header->opslimit)->toBe(1)
        ->and($this->header->memlimit)->toBe(8192);
});

test('the key file holds no password and no data key', function () {
    $bytes = $this->crypto->encodeHeader($this->header);
    $material = $this->key->material();

    expect($bytes)->not->toContain(ENC_TEST_PASSWORD)
        ->and($bytes)->not->toContain($material)
        ->and($bytes)->not->toContain(base64_encode($material))
        ->and($bytes)->not->toContain(bin2hex($material));
});

test('the correct password unlocks and a wrong one does not', function () {
    $key = $this->crypto->unlock($this->header, ENC_TEST_PASSWORD);

    expect($key)->toBeInstanceOf(VaultKey::class)
        ->and($key->keyId)->toBe($this->header->keyId)
        ->and($key->material())->toBe($this->key->material());

    expectCrypto(fn () => $this->crypto->unlock($this->header, 'wrong password'), 'wrongPassword');
    expectCrypto(fn () => $this->crypto->unlock($this->header, ''), 'wrongPassword');
});

test('the wrong password error names the password field and is generic', function () {
    try {
        $this->crypto->unlock($this->header, 'definitely-not-it');
        test()->fail('Expected an exception.');
    } catch (EncryptionException $e) {
        expect($e->field())->toBe('password')
            ->and($e->getMessage())->toBe("That password didn't unlock this vault.")
            ->and($e->getMessage())->not->toContain('definitely-not-it')
            ->and($e->getPrevious())->toBeNull();
    }
});

test('an exception thrown from unlock never contains the password', function () {
    $password = 'Very-Secret-Canary-Pw-4421';

    try {
        $this->crypto->unlock($this->header, $password);
        test()->fail('Expected an exception.');
    } catch (EncryptionException $e) {
        expect($e->getMessage())->not->toContain($password)
            ->and($e->getTraceAsString())->not->toContain($password);
    }
});

test('tampering with any key file field makes unlocking fail', function (Closure $mutate) {
    $bytes = tamperedHeaderBytes($this->header, $mutate);

    try {
        $header = $this->crypto->parseHeader($bytes);
    } catch (EncryptionException) {
        // Rejected while parsing is also a failure to unlock.
        expect(true)->toBeTrue();

        return;
    }

    expectCrypto(fn () => $this->crypto->unlock($header, ENC_TEST_PASSWORD), 'wrongPassword');
})->with([
    'salt' => function (array $d) {
        $d['kdf']['salt'] = base64_encode(flipFirstByte(base64_decode($d['kdf']['salt'])));

        return $d;
    },
    'opslimit' => function (array $d) {
        $d['kdf']['opslimit'] = 2;

        return $d;
    },
    'memlimit' => function (array $d) {
        $d['kdf']['memlimit'] = 16384;

        return $d;
    },
    'key_version' => function (array $d) {
        $d['key_version'] = 2;

        return $d;
    },
    'key_id' => function (array $d) {
        $d['key_id'] = '0198a000-0000-7000-8000-00000000ffff';

        return $d;
    },
    'nonce' => function (array $d) {
        $d['wrapped_key']['nonce'] = base64_encode(flipFirstByte(base64_decode($d['wrapped_key']['nonce'])));

        return $d;
    },
    'ciphertext' => function (array $d) {
        $d['wrapped_key']['ciphertext'] = base64_encode(flipFirstByte(base64_decode($d['wrapped_key']['ciphertext'])));

        return $d;
    },
]);

test('hostile KDF parameters are rejected while parsing, before the KDF could run', function (int $ops, int $mem) {
    $bytes = tamperedHeaderBytes($this->header, function (array $d) use ($ops, $mem) {
        $d['kdf']['opslimit'] = $ops;
        $d['kdf']['memlimit'] = $mem;

        return $d;
    });

    $start = hrtime(true);
    expectCrypto(fn () => $this->crypto->parseHeader($bytes), 'damagedHeader');
    expect((hrtime(true) - $start) / 1e6)->toBeLessThan(50);
})->with([
    'memlimit 2^31' => [1, 2 ** 31],
    'opslimit 0' => [0, 8192],
    'opslimit 11' => [11, 8192],
    'memlimit below minimum' => [1, 8191],
]);

test('hostile header objects are also refused by unlock without running the KDF', function () {
    $hostile = new EncryptionHeader(1, $this->header->keyId, 1, $this->header->cipher, $this->header->kdfAlgorithm, 1, 2 ** 31, $this->header->salt, $this->header->wrapNonce, $this->header->wrappedKey, $this->header->createdAt);

    $start = hrtime(true);
    expectCrypto(fn () => $this->crypto->unlock($hostile, ENC_TEST_PASSWORD), 'damagedHeader');
    expect((hrtime(true) - $start) / 1e6)->toBeLessThan(50);
});

test('the key file parser is strict', function (Closure $mutate) {
    expectCrypto(
        fn () => $this->crypto->parseHeader(tamperedHeaderBytes($this->header, $mutate)),
        'damagedHeader',
    );
})->with([
    'unknown key' => function (array $d) {
        $d['extra'] = 1;

        return $d;
    },
    'missing key' => function (array $d) {
        unset($d['key_version']);

        return $d;
    },
    'wrong application' => function (array $d) {
        $d['application'] = 'Other';

        return $d;
    },
    'wrong format version' => function (array $d) {
        $d['format_version'] = 2;

        return $d;
    },
    'wrong cipher' => function (array $d) {
        $d['cipher'] = 'aes-256-gcm';

        return $d;
    },
    'wrong kdf' => function (array $d) {
        $d['kdf']['algorithm'] = 'pbkdf2';

        return $d;
    },
    'string ops' => function (array $d) {
        $d['kdf']['opslimit'] = '1';

        return $d;
    },
    'short salt' => function (array $d) {
        $d['kdf']['salt'] = base64_encode('short');

        return $d;
    },
    'short nonce' => function (array $d) {
        $d['wrapped_key']['nonce'] = base64_encode('short');

        return $d;
    },
    'short ciphertext' => function (array $d) {
        $d['wrapped_key']['ciphertext'] = base64_encode('short');

        return $d;
    },
    'invalid base64' => function (array $d) {
        $d['kdf']['salt'] = '!!!not base64!!!';

        return $d;
    },
    'bad key id' => function (array $d) {
        $d['key_id'] = 'not-a-uuid';

        return $d;
    },
    'bad created_at' => function (array $d) {
        $d['created_at'] = 'yesterday';

        return $d;
    },
]);

test('non-JSON, non-object and oversized key files are refused', function () {
    foreach (['', 'not json', '[]', '"string"', '{', str_repeat('a', 70000)] as $bytes) {
        expectCrypto(fn () => $this->crypto->parseHeader($bytes), 'damagedHeader');
    }
});

test('a note round trips: unicode names, empty content, binary content, BOM and CRLF', function (string $name, string $content) {
    $bytes = $this->crypto->encryptNote($this->key, $this->fileId, $name, $content);

    expect($this->crypto->decryptNote($this->key, $this->fileId, $bytes))->toBe(['name' => $name, 'content' => $content]);
})->with([
    'unicode name' => ['Café – 日本語 🚀', "# Title\n"],
    'longest name' => [str_repeat('a', 255), 'x'],
    'empty content' => ['Empty', ''],
    'binary content' => ['Binary', "\xff\xfe\x00\x01\x80 not utf-8 \xc3\x28"],
    'BOM and CRLF' => ['Windows', "\xEF\xBB\xBFline one\r\nline two\r\n"],
]);

test('encrypting the same note twice gives different bytes', function () {
    $a = $this->crypto->encryptNote($this->key, $this->fileId, 'Same', 'same content');
    $b = $this->crypto->encryptNote($this->key, $this->fileId, 'Same', 'same content');

    expect($a)->not->toBe($b);
});

test('the ciphertext contains neither the name nor the content', function () {
    $bytes = $this->crypto->encryptNote($this->key, $this->fileId, 'Bank Accounts', 'CANARY-CONTENT-7f3a');

    expect(stripos($bytes, 'Bank Accounts'))->toBeFalse()
        ->and(stripos($bytes, 'CANARY-CONTENT-7f3a'))->toBeFalse()
        ->and(substr($bytes, 0, 5))->toBe("MDVN\x01");
});

test('a note decrypts only under its own file id', function () {
    $bytes = $this->crypto->encryptNote($this->key, $this->fileId, 'Name', 'content');

    expectCrypto(fn () => $this->crypto->decryptNote($this->key, $this->crypto->newFileId(), $bytes), 'undecryptable');
});

test('a note from another vault key does not decrypt', function () {
    [, $other] = $this->crypto->newVaultKey('another password!');
    $bytes = $this->crypto->encryptNote($this->key, $this->fileId, 'Name', 'content');

    expectCrypto(fn () => $this->crypto->decryptNote($other, $this->fileId, $bytes), 'undecryptable');
});

test('damaged note files are undecryptable', function (Closure $damage) {
    $bytes = $this->crypto->encryptNote($this->key, $this->fileId, 'Name', 'some content here');

    expectCrypto(fn () => $this->crypto->decryptNote($this->key, $this->fileId, $damage($bytes)), 'undecryptable');
})->with([
    'truncated' => fn (string $b) => substr($b, 0, -5),
    'tiny' => fn (string $b) => substr($b, 0, 10),
    'empty' => fn (string $b) => '',
    'flipped tag' => fn (string $b) => substr($b, 0, -1).($b[strlen($b) - 1] ^ "\x01"),
    'flipped body' => fn (string $b) => substr($b, 0, 40).($b[40] ^ "\x01").substr($b, 41),
    'wrong magic' => fn (string $b) => 'XXXX'.substr($b, 4),
    'wrong version' => fn (string $b) => substr($b, 0, 4)."\x02".substr($b, 5),
]);

test('a name over 255 bytes, an empty name, an invalid name and oversized content are rejected', function () {
    expectCrypto(fn () => $this->crypto->encryptNote($this->key, $this->fileId, str_repeat('a', 256), 'x'), 'unsupportedNote');
    expectCrypto(fn () => $this->crypto->encryptNote($this->key, $this->fileId, '', 'x'), 'unsupportedNote');
    expectCrypto(fn () => $this->crypto->encryptNote($this->key, $this->fileId, "bad\xff", 'x'), 'unsupportedNote');
    expectCrypto(
        fn () => $this->crypto->encryptNote($this->key, $this->fileId, 'Big', str_repeat('a', EncryptionService::MAX_NOTE_PLAINTEXT_BYTES + 1)),
        'unsupportedNote',
    );
});

test('a file id must be 32 lowercase hex characters', function () {
    expectCrypto(fn () => $this->crypto->encryptNote($this->key, 'nothex', 'Name', 'x'), 'undecryptable');
    expect($this->crypto->newFileId())->toMatch('/^[0-9a-f]{32}$/');
});

test('a folder name round trips and detects a swap', function () {
    $folderId = $this->crypto->newFileId();
    $bytes = $this->crypto->encryptFolderName($this->key, $folderId, 'My Credentials');

    expect($this->crypto->decryptFolderName($this->key, $folderId, $bytes))->toBe('My Credentials')
        ->and(stripos($bytes, 'My Credentials'))->toBeFalse()
        ->and(substr($bytes, 0, 5))->toBe("MDVF\x01");

    expectCrypto(fn () => $this->crypto->decryptFolderName($this->key, $this->crypto->newFileId(), $bytes), 'undecryptable');
    expectCrypto(fn () => $this->crypto->decryptFolderName($this->key, $folderId, substr($bytes, 0, -1)), 'undecryptable');
});

test('a folder name file is not accepted as a note and vice versa', function () {
    $id = $this->crypto->newFileId();
    $folder = $this->crypto->encryptFolderName($this->key, $id, 'Folder');
    $note = $this->crypto->encryptNote($this->key, $id, 'Note', 'content');

    expectCrypto(fn () => $this->crypto->decryptNote($this->key, $id, $folder), 'undecryptable');
    expectCrypto(fn () => $this->crypto->decryptFolderName($this->key, $id, $note), 'undecryptable');
});

test('rewrap changes the password but not the data key', function () {
    $bytes = $this->crypto->encryptNote($this->key, $this->fileId, 'Name', 'content');

    $next = $this->crypto->rewrap($this->header, $this->key, 'a brand new password');

    expect($next->keyVersion)->toBe(2)
        ->and($next->keyId)->toBe($this->header->keyId)
        ->and($next->salt)->not->toBe($this->header->salt)
        ->and($next->createdAt)->toBe($this->header->createdAt);

    expectCrypto(fn () => $this->crypto->unlock($next, ENC_TEST_PASSWORD), 'wrongPassword');

    $rewrapped = $this->crypto->unlock($next, 'a brand new password');

    expect($this->crypto->decryptNote($rewrapped, $this->fileId, $bytes)['content'])->toBe('content');
});

test('the old key file still opens with the old password', function () {
    $this->crypto->rewrap($this->header, $this->key, 'a brand new password');

    expect($this->crypto->unlock($this->header, ENC_TEST_PASSWORD)->keyId)->toBe($this->header->keyId);
});

test('session sealing needs the right token, vault, epoch and entry', function () {
    $vaultUuid = '0198a000-0000-7000-8000-0000000000aa';
    $token = $this->crypto->newSessionToken();
    $sealed = $this->crypto->sealForSession($this->key, $vaultUuid, 3, $token);
    $entry = $sealed + ['key_id' => $this->key->keyId];

    expect($token)->toMatch('/^[A-Za-z0-9_-]{43}$/')
        ->and($this->crypto->openFromSession($entry, $vaultUuid, 3, $token)?->material())->toBe($this->key->material())
        ->and($this->crypto->openFromSession($entry, $vaultUuid, 3, $this->crypto->newSessionToken()))->toBeNull()
        ->and($this->crypto->openFromSession($entry, $vaultUuid, 4, $token))->toBeNull()
        ->and($this->crypto->openFromSession($entry, '0198a000-0000-7000-8000-0000000000bb', 3, $token))->toBeNull()
        ->and($this->crypto->openFromSession($entry, $vaultUuid, 3, 'short'))->toBeNull()
        ->and($this->crypto->openFromSession(['key_id' => '0198a000-0000-7000-8000-00000000ffff'] + $entry, $vaultUuid, 3, $token))->toBeNull()
        ->and($this->crypto->openFromSession(['nonce' => 'x', 'sealed' => 'y', 'key_id' => $this->key->keyId], $vaultUuid, 3, $token))->toBeNull()
        ->and($this->crypto->openFromSession([], $vaultUuid, 3, $token))->toBeNull();
});

test('KDF cost is floored at INTERACTIVE unless weak KDF is allowed', function () {
    config([
        'mdvault.encryption.allow_weak_kdf' => false,
        'mdvault.encryption.kdf.opslimit' => 1,
        'mdvault.encryption.kdf.memlimit' => 8192,
    ]);

    [$header] = app(EncryptionService::class)->newVaultKey('floor test password');

    expect($header->opslimit)->toBe(SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE)
        ->and($header->memlimit)->toBe(SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE);
});

test('the key id is a UUIDv7 and each vault key is new', function () {
    [$other] = $this->crypto->newVaultKey(ENC_TEST_PASSWORD);

    expect($this->header->keyId)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}$/')
        ->and($other->keyId)->not->toBe($this->header->keyId)
        ->and($other->wrappedKey)->not->toBe($this->header->wrappedKey);
});
