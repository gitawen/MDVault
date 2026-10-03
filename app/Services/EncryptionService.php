<?php

namespace App\Services;

use App\Exceptions\EncryptionException;
use App\Support\EncryptionHeader;
use App\Support\VaultKey;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Every cryptographic operation in MDVault: the ONLY user of `sodium_*`
 * (ADR `vault-encryption-cryptography`). It never logs, reports or dumps,
 * its exceptions carry fixed messages, and a `SodiumException` is never
 * chained into one.
 */
final class EncryptionService
{
    public const FORMAT_VERSION = 1;

    public const CIPHER = 'xchacha20poly1305-ietf';

    public const KDF_ALGORITHM = 'argon2id13';

    public const MIN_OPSLIMIT = 1;

    public const MAX_OPSLIMIT = 10;

    public const MIN_MEMLIMIT = 8192;

    public const MAX_MEMLIMIT = 1073741824;

    public const MAX_NOTE_PLAINTEXT_BYTES = 16 * 1024 * 1024;

    public const MAX_NAME_BYTES = 255;

    public const MAX_FOLDER_FILE_BYTES = 4096;

    public const MAX_HEADER_BYTES = 65536;

    private const SALT_BYTES = 16;

    private const NONCE_BYTES = 24;

    private const KEY_BYTES = 32;

    private const TAG_BYTES = 16;

    private const NOTE_MAGIC = 'MDVN';

    private const FOLDER_MAGIC = 'MDVF';

    private const KEY_MAGIC = 'MDVK';

    private const SESSION_MAGIC = 'MDVS';

    private const NOTE_SUBKEY_ID = 1;

    private const FOLDER_SUBKEY_ID = 2;

    private const NOTE_SUBKEY_CONTEXT = 'MDVNOTE1';

    private const FOLDER_SUBKEY_CONTEXT = 'MDVFOLD1';

    private const KEY_ID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    private const FILE_ID_PATTERN = '/^[0-9a-f]{32}$/';

    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    private const TOKEN_PATTERN = '/^[A-Za-z0-9_-]{43}$/';

    private const HEADER_APPLICATION = 'MDVault';

    private const HEADER_FORMAT = 'mdvault-encrypted-vault';

    private const CREATED_AT_PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/';

    public function __construct(
        private readonly Repository $config,
    ) {}

    /**
     * A new vault key, wrapped under a key derived from $password.
     *
     * @return array{0: EncryptionHeader, 1: VaultKey}
     */
    public function newVaultKey(#[\SensitiveParameter] string $password): array
    {
        [$opslimit, $memlimit] = $this->creationCost();
        $kek = null;

        try {
            $salt = random_bytes(self::SALT_BYTES);
            $keyId = (string) Str::uuid7();
            $nonce = random_bytes(self::NONCE_BYTES);
            $kek = $this->deriveKek($password, $salt, $opslimit, $memlimit);
            $dek = sodium_crypto_aead_xchacha20poly1305_ietf_keygen();

            $header = new EncryptionHeader(
                self::FORMAT_VERSION,
                $keyId,
                1,
                self::CIPHER,
                self::KDF_ALGORITHM,
                $opslimit,
                $memlimit,
                $salt,
                $nonce,
                '',
                Carbon::now('UTC')->format('Y-m-d\TH:i:s\Z'),
            );

            $wrapped = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($dek, $this->wrapAd($header), $nonce, $kek);

            return [$this->withWrappedKey($header, $wrapped), new VaultKey($dek, $keyId)];
        } catch (\Throwable) {
            throw EncryptionException::cryptoFailed();
        } finally {
            $this->wipe($kek);
        }
    }

    /**
     * Unwraps the vault key. A wrong password, tampered parameters and a
     * tampered wrapped key all produce the same generic error.
     */
    public function unlock(EncryptionHeader $header, #[\SensitiveParameter] string $password): VaultKey
    {
        $this->assertHeaderBounds($header);

        // libsodium refuses an empty password; it can never be the right one.
        if ($password === '') {
            throw EncryptionException::wrongPassword();
        }

        $kek = null;

        try {
            $kek = $this->deriveKek($password, $header->salt, $header->opslimit, $header->memlimit);
            $dek = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                $header->wrappedKey,
                $this->wrapAd($header),
                $header->wrapNonce,
                $kek,
            );
        } catch (\Throwable) {
            throw EncryptionException::cryptoFailed();
        } finally {
            $this->wipe($kek);
        }

        if ($dek === false || strlen($dek) !== self::KEY_BYTES) {
            throw EncryptionException::wrongPassword();
        }

        return new VaultKey($dek, $header->keyId);
    }

    /**
     * Re-wraps the same data key under a new password: new salt and nonce,
     * `key_version` + 1. Notes are untouched.
     */
    public function rewrap(EncryptionHeader $header, VaultKey $key, #[\SensitiveParameter] string $newPassword): EncryptionHeader
    {
        $this->assertHeaderBounds($header);

        if ($key->keyId !== $header->keyId) {
            throw EncryptionException::damagedHeader();
        }

        $kek = null;

        try {
            $salt = random_bytes(self::SALT_BYTES);
            $nonce = random_bytes(self::NONCE_BYTES);
            $kek = $this->deriveKek($newPassword, $salt, $header->opslimit, $header->memlimit);

            $next = new EncryptionHeader(
                $header->formatVersion,
                $header->keyId,
                $header->keyVersion + 1,
                $header->cipher,
                $header->kdfAlgorithm,
                $header->opslimit,
                $header->memlimit,
                $salt,
                $nonce,
                '',
                $header->createdAt,
            );

            $wrapped = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($key->material(), $this->wrapAd($next), $nonce, $kek);

            return $this->withWrappedKey($next, $wrapped);
        } catch (\Throwable) {
            throw EncryptionException::cryptoFailed();
        } finally {
            $this->wipe($kek);
        }
    }

    /**
     * The key file's bytes (deterministic for a given header).
     */
    public function encodeHeader(EncryptionHeader $header): string
    {
        $json = json_encode([
            'application' => self::HEADER_APPLICATION,
            'format' => self::HEADER_FORMAT,
            'format_version' => $header->formatVersion,
            'key_id' => $header->keyId,
            'key_version' => $header->keyVersion,
            'cipher' => $header->cipher,
            'kdf' => [
                'algorithm' => $header->kdfAlgorithm,
                'opslimit' => $header->opslimit,
                'memlimit' => $header->memlimit,
                'salt' => base64_encode($header->salt),
            ],
            'wrapped_key' => [
                'nonce' => base64_encode($header->wrapNonce),
                'ciphertext' => base64_encode($header->wrappedKey),
            ],
            'created_at' => $header->createdAt,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            throw EncryptionException::damagedHeader();
        }

        return $json."\n";
    }

    /**
     * Strictly parses key-file bytes: exact keys, types, base64 lengths and
     * KDF bounds. Never runs the KDF.
     */
    public function parseHeader(string $bytes): EncryptionHeader
    {
        if (strlen($bytes) > self::MAX_HEADER_BYTES) {
            throw EncryptionException::damagedHeader();
        }

        try {
            $data = json_decode($bytes, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw EncryptionException::damagedHeader();
        }

        return $this->headerFromArray($data);
    }

    /**
     * Rebuilds a header from `vault_encryption` mirror columns, with the same
     * validation as a key file.
     *
     * @param  array<string, mixed>  $attributes  `key_id`, `key_version`, `algorithm`, `kdf_algorithm`, `kdf_opslimit`, `kdf_memlimit`, `salt`, `nonce`, `encrypted_key`, `format_version`, `created_at`
     */
    public function headerFromRegistry(array $attributes): EncryptionHeader
    {
        return $this->headerFromArray([
            'application' => self::HEADER_APPLICATION,
            'format' => self::HEADER_FORMAT,
            'format_version' => $attributes['format_version'] ?? null,
            'key_id' => $attributes['key_id'] ?? null,
            'key_version' => $attributes['key_version'] ?? null,
            'cipher' => $attributes['algorithm'] ?? null,
            'kdf' => [
                'algorithm' => $attributes['kdf_algorithm'] ?? null,
                'opslimit' => $attributes['kdf_opslimit'] ?? null,
                'memlimit' => $attributes['kdf_memlimit'] ?? null,
                'salt' => $attributes['salt'] ?? null,
            ],
            'wrapped_key' => [
                'nonce' => $attributes['nonce'] ?? null,
                'ciphertext' => $attributes['encrypted_key'] ?? null,
            ],
            'created_at' => $attributes['created_at'] ?? null,
        ]);
    }

    /**
     * 32 lowercase hex characters (16 random bytes): an on-disk note or
     * folder identifier.
     */
    public function newFileId(): string
    {
        return bin2hex(random_bytes(16));
    }

    public function encryptNote(VaultKey $key, string $fileId, string $name, string $content): string
    {
        $this->assertFileId($fileId);

        if ($this->invalidName($name) || strlen($content) > self::MAX_NOTE_PLAINTEXT_BYTES) {
            throw EncryptionException::unsupportedNote();
        }

        $subkey = null;

        try {
            $subkey = $this->subkey($key, self::NOTE_SUBKEY_ID, self::NOTE_SUBKEY_CONTEXT);
            $nonce = random_bytes(self::NONCE_BYTES);
            $payload = pack('n', strlen($name)).$name.$content;
            $ad = $this->fileAd(self::NOTE_MAGIC, $key, $fileId);

            return self::NOTE_MAGIC."\x01".$nonce.sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($payload, $ad, $nonce, $subkey);
        } catch (\Throwable) {
            throw EncryptionException::cryptoFailed();
        } finally {
            $this->wipe($subkey);
        }
    }

    /**
     * @return array{name: string, content: string}
     */
    public function decryptNote(VaultKey $key, string $fileId, string $bytes): array
    {
        $this->assertFileId($fileId);

        $overhead = 4 + 1 + self::NONCE_BYTES + self::TAG_BYTES;

        if (
            strlen($bytes) < $overhead + 3
            || strlen($bytes) > $overhead + 2 + self::MAX_NAME_BYTES + self::MAX_NOTE_PLAINTEXT_BYTES
            || ! str_starts_with($bytes, self::NOTE_MAGIC."\x01")
        ) {
            throw EncryptionException::undecryptable();
        }

        $subkey = null;

        try {
            $subkey = $this->subkey($key, self::NOTE_SUBKEY_ID, self::NOTE_SUBKEY_CONTEXT);
            $payload = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                substr($bytes, 5 + self::NONCE_BYTES),
                $this->fileAd(self::NOTE_MAGIC, $key, $fileId),
                substr($bytes, 5, self::NONCE_BYTES),
                $subkey,
            );
        } catch (\Throwable) {
            throw EncryptionException::undecryptable();
        } finally {
            $this->wipe($subkey);
        }

        if ($payload === false || strlen($payload) < 3) {
            throw EncryptionException::undecryptable();
        }

        $unpacked = unpack('n', $payload);

        if ($unpacked === false) {
            throw EncryptionException::undecryptable();
        }

        $nameLength = $unpacked[1];
        $name = substr($payload, 2, $nameLength);

        if ($nameLength < 1 || $nameLength > self::MAX_NAME_BYTES || strlen($name) !== $nameLength || $this->invalidName($name)) {
            throw EncryptionException::undecryptable();
        }

        return ['name' => $name, 'content' => substr($payload, 2 + $nameLength)];
    }

    public function encryptFolderName(VaultKey $key, string $folderId, string $name): string
    {
        $this->assertFileId($folderId);

        if ($this->invalidName($name)) {
            throw EncryptionException::unsupportedNote();
        }

        $subkey = null;

        try {
            $subkey = $this->subkey($key, self::FOLDER_SUBKEY_ID, self::FOLDER_SUBKEY_CONTEXT);
            $nonce = random_bytes(self::NONCE_BYTES);
            $ad = $this->fileAd(self::FOLDER_MAGIC, $key, $folderId);

            return self::FOLDER_MAGIC."\x01".$nonce.sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($name, $ad, $nonce, $subkey);
        } catch (\Throwable) {
            throw EncryptionException::cryptoFailed();
        } finally {
            $this->wipe($subkey);
        }
    }

    public function decryptFolderName(VaultKey $key, string $folderId, string $bytes): string
    {
        $this->assertFileId($folderId);

        $overhead = 4 + 1 + self::NONCE_BYTES + self::TAG_BYTES;

        if (
            strlen($bytes) < $overhead + 1
            || strlen($bytes) > self::MAX_FOLDER_FILE_BYTES
            || ! str_starts_with($bytes, self::FOLDER_MAGIC."\x01")
        ) {
            throw EncryptionException::undecryptable();
        }

        $subkey = null;

        try {
            $subkey = $this->subkey($key, self::FOLDER_SUBKEY_ID, self::FOLDER_SUBKEY_CONTEXT);
            $name = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                substr($bytes, 5 + self::NONCE_BYTES),
                $this->fileAd(self::FOLDER_MAGIC, $key, $folderId),
                substr($bytes, 5, self::NONCE_BYTES),
                $subkey,
            );
        } catch (\Throwable) {
            throw EncryptionException::undecryptable();
        } finally {
            $this->wipe($subkey);
        }

        if ($name === false || $this->invalidName($name)) {
            throw EncryptionException::undecryptable();
        }

        return $name;
    }

    /**
     * A random per-unlock token: 32 bytes, base64url without padding. Held
     * only in the renderer's memory (ADR `encrypted-vault-key-custody`).
     */
    public function newSessionToken(): string
    {
        return sodium_bin2base64(random_bytes(self::KEY_BYTES), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    }

    /**
     * Seals the data key under $token for storage in the session.
     *
     * @return array{nonce: string, sealed: string} base64
     */
    public function sealForSession(VaultKey $key, string $vaultUuid, int $epoch, #[\SensitiveParameter] string $token): array
    {
        $tokenKey = $this->tokenKey($token);

        if ($tokenKey === null || preg_match(self::UUID_PATTERN, $vaultUuid) !== 1) {
            throw EncryptionException::cryptoFailed();
        }

        try {
            $nonce = random_bytes(self::NONCE_BYTES);
            $sealed = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
                $key->material(),
                $this->sessionAd($vaultUuid, $key->keyId, $epoch),
                $nonce,
                $tokenKey,
            );

            return ['nonce' => base64_encode($nonce), 'sealed' => base64_encode($sealed)];
        } catch (\Throwable) {
            throw EncryptionException::cryptoFailed();
        } finally {
            $this->wipe($tokenKey);
        }
    }

    /**
     * Opens a session entry with its token, or null when anything does not
     * match (token, vault, epoch, key id, tampering).
     *
     * @param  array<string, mixed>  $entry  `key_id`, `nonce`, `sealed`
     */
    public function openFromSession(array $entry, string $vaultUuid, int $epoch, #[\SensitiveParameter] string $token): ?VaultKey
    {
        $keyId = $entry['key_id'] ?? null;
        $nonce = is_string($entry['nonce'] ?? null) ? base64_decode($entry['nonce'], true) : false;
        $sealed = is_string($entry['sealed'] ?? null) ? base64_decode($entry['sealed'], true) : false;
        $tokenKey = $this->tokenKey($token);

        if (
            ! is_string($keyId) || preg_match(self::KEY_ID_PATTERN, $keyId) !== 1
            || preg_match(self::UUID_PATTERN, $vaultUuid) !== 1
            || $nonce === false || strlen($nonce) !== self::NONCE_BYTES
            || $sealed === false
            || $tokenKey === null
        ) {
            $this->wipe($tokenKey);

            return null;
        }

        try {
            $dek = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                $sealed,
                $this->sessionAd($vaultUuid, $keyId, $epoch),
                $nonce,
                $tokenKey,
            );
        } catch (\Throwable) {
            return null;
        } finally {
            $this->wipe($tokenKey);
        }

        if ($dek === false || strlen($dek) !== self::KEY_BYTES) {
            return null;
        }

        return new VaultKey($dek, $keyId);
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function creationCost(): array
    {
        $opslimit = (int) $this->config->get('mdvault.encryption.kdf.opslimit', SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE);
        $memlimit = (int) $this->config->get('mdvault.encryption.kdf.memlimit', SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE);

        if (! (bool) $this->config->get('mdvault.encryption.allow_weak_kdf', false)) {
            $opslimit = max($opslimit, SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE);
            $memlimit = max($memlimit, SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE);
        }

        return [
            min(max($opslimit, self::MIN_OPSLIMIT), self::MAX_OPSLIMIT),
            min(max($memlimit, self::MIN_MEMLIMIT), self::MAX_MEMLIMIT),
        ];
    }

    private function deriveKek(#[\SensitiveParameter] string $password, string $salt, int $opslimit, int $memlimit): string
    {
        return sodium_crypto_pwhash(
            self::KEY_BYTES,
            $password,
            $salt,
            $opslimit,
            $memlimit,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13,
        );
    }

    private function subkey(VaultKey $key, int $id, string $context): string
    {
        return sodium_crypto_kdf_derive_from_key(self::KEY_BYTES, $id, $context, $key->material());
    }

    /**
     * Associated data of the key wrap: changing any key-file field makes
     * unwrapping fail.
     */
    private function wrapAd(EncryptionHeader $header): string
    {
        return self::KEY_MAGIC
            .pack('C', $header->formatVersion)
            .$this->rawKeyId($header->keyId)
            .pack('N', $header->keyVersion)
            .$header->cipher.'|'.$header->kdfAlgorithm.'|'
            .pack('N', $header->opslimit)
            .pack('J', $header->memlimit)
            .$header->salt;
    }

    private function fileAd(string $magic, VaultKey $key, string $fileId): string
    {
        return $magic."\x01".$this->rawKeyId($key->keyId).(string) hex2bin($fileId);
    }

    private function sessionAd(string $vaultUuid, string $keyId, int $epoch): string
    {
        return self::SESSION_MAGIC."\x01".strtolower($vaultUuid).$this->rawKeyId($keyId).pack('J', $epoch);
    }

    private function rawKeyId(string $keyId): string
    {
        return (string) hex2bin(str_replace('-', '', $keyId));
    }

    private function tokenKey(#[\SensitiveParameter] string $token): ?string
    {
        if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            return null;
        }

        try {
            $key = sodium_base642bin($token, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        } catch (\Throwable) {
            return null;
        }

        return strlen($key) === self::KEY_BYTES ? $key : null;
    }

    private function withWrappedKey(EncryptionHeader $header, string $wrapped): EncryptionHeader
    {
        return new EncryptionHeader(
            $header->formatVersion,
            $header->keyId,
            $header->keyVersion,
            $header->cipher,
            $header->kdfAlgorithm,
            $header->opslimit,
            $header->memlimit,
            $header->salt,
            $header->wrapNonce,
            $wrapped,
            $header->createdAt,
        );
    }

    private function assertFileId(string $id): void
    {
        if (preg_match(self::FILE_ID_PATTERN, $id) !== 1) {
            throw EncryptionException::undecryptable();
        }
    }

    private function invalidName(string $name): bool
    {
        return $name === '' || strlen($name) > self::MAX_NAME_BYTES || ! mb_check_encoding($name, 'UTF-8');
    }

    private function assertHeaderBounds(EncryptionHeader $header): void
    {
        if (
            $header->formatVersion !== self::FORMAT_VERSION
            || preg_match(self::KEY_ID_PATTERN, $header->keyId) !== 1
            || $header->keyVersion < 1 || $header->keyVersion > 4294967295
            || $header->cipher !== self::CIPHER
            || $header->kdfAlgorithm !== self::KDF_ALGORITHM
            || $header->opslimit < self::MIN_OPSLIMIT || $header->opslimit > self::MAX_OPSLIMIT
            || $header->memlimit < self::MIN_MEMLIMIT || $header->memlimit > self::MAX_MEMLIMIT
            || strlen($header->salt) !== self::SALT_BYTES
            || strlen($header->wrapNonce) !== self::NONCE_BYTES
            || strlen($header->wrappedKey) !== self::KEY_BYTES + self::TAG_BYTES
        ) {
            throw EncryptionException::damagedHeader();
        }
    }

    /**
     * @param  mixed  $data  decoded key-file JSON
     */
    private function headerFromArray(mixed $data): EncryptionHeader
    {
        if (! is_array($data) || array_is_list($data)) {
            throw EncryptionException::damagedHeader();
        }

        $kdf = $data['kdf'] ?? null;
        $wrapped = $data['wrapped_key'] ?? null;

        if (
            ! $this->sameKeys($data, ['application', 'format', 'format_version', 'key_id', 'key_version', 'cipher', 'kdf', 'wrapped_key', 'created_at'])
            || ! is_array($kdf) || ! $this->sameKeys($kdf, ['algorithm', 'opslimit', 'memlimit', 'salt'])
            || ! is_array($wrapped) || ! $this->sameKeys($wrapped, ['nonce', 'ciphertext'])
            || $data['application'] !== self::HEADER_APPLICATION
            || $data['format'] !== self::HEADER_FORMAT
            || ! is_int($data['format_version']) || ! is_string($data['key_id']) || ! is_int($data['key_version'])
            || ! is_string($data['cipher']) || ! is_string($data['created_at'])
            || ! is_string($kdf['algorithm']) || ! is_int($kdf['opslimit']) || ! is_int($kdf['memlimit'])
            || ! is_string($kdf['salt']) || ! is_string($wrapped['nonce']) || ! is_string($wrapped['ciphertext'])
            || preg_match(self::CREATED_AT_PATTERN, $data['created_at']) !== 1
        ) {
            throw EncryptionException::damagedHeader();
        }

        $salt = base64_decode($kdf['salt'], true);
        $nonce = base64_decode($wrapped['nonce'], true);
        $ciphertext = base64_decode($wrapped['ciphertext'], true);

        if ($salt === false || $nonce === false || $ciphertext === false) {
            throw EncryptionException::damagedHeader();
        }

        $header = new EncryptionHeader(
            $data['format_version'],
            $data['key_id'],
            $data['key_version'],
            $data['cipher'],
            $kdf['algorithm'],
            $kdf['opslimit'],
            $kdf['memlimit'],
            $salt,
            $nonce,
            $ciphertext,
            $data['created_at'],
        );

        $this->assertHeaderBounds($header);

        return $header;
    }

    /**
     * @param  array<mixed>  $array
     * @param  list<string>  $keys
     */
    private function sameKeys(array $array, array $keys): bool
    {
        $actual = array_keys($array);
        sort($actual);
        sort($keys);

        return $actual === $keys;
    }

    private function wipe(?string &$secret): void
    {
        if ($secret !== null && $secret !== '') {
            sodium_memzero($secret);
        }
    }
}
