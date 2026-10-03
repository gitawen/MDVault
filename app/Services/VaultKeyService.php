<?php

namespace App\Services;

use App\Enums\SettingKey;
use App\Exceptions\VaultLockedException;
use App\Models\Vault;
use App\Models\VaultEncryption;
use App\Support\VaultKey;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Carbon;

/**
 * The keyring: which vaults are unlocked right now (ADR
 * `encrypted-vault-key-custody`). The data key lives in the session sealed
 * under a per-unlock token that only the renderer holds; this service opens
 * it on demand and enforces the idle limit, the lock epoch and the key id.
 *
 * Request-scoped: it holds the tokens supplied with the current request.
 */
final class VaultKeyService
{
    public const HEADER = 'X-MDVault-Unlock';

    public const SESSION_PREFIX = 'mdvault.keyring.';

    public const EPOCH_CACHE_KEY = 'mdvault.vault_lock_epoch';

    public const IDLE_GRACE_SECONDS = 120;

    public const MAX_TOKEN_PAIRS = 20;

    private const MAX_HEADER_LENGTH = 2048;

    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    private const TOKEN_PATTERN = '/^[A-Za-z0-9_-]{43}$/';

    /**
     * @var array<string, string> vault UUID => token, for this request only
     */
    private array $tokens = [];

    /**
     * @var array<string, VaultKey> vault UUID => opened key, wiped when the request ends
     */
    private array $opened = [];

    public function __construct(
        private readonly EncryptionService $encryption,
        private readonly SettingsService $settings,
        private readonly Session $session,
        private readonly Cache $cache,
        Application $app,
    ) {
        $app->terminating(function (): void {
            $this->opened = [];
            $this->tokens = [];
        });
    }

    /**
     * Receives the raw `X-MDVault-Unlock` header: `uuid:token` pairs
     * separated by commas. At most 20 pairs are read and malformed ones are
     * ignored. Replaces any tokens from an earlier request.
     */
    public function provideTokens(#[\SensitiveParameter] ?string $header): void
    {
        $this->tokens = [];
        $this->opened = [];

        if ($header === null || $header === '' || strlen($header) > self::MAX_HEADER_LENGTH) {
            return;
        }

        foreach (array_slice(explode(',', $header), 0, self::MAX_TOKEN_PAIRS) as $pair) {
            $parts = explode(':', trim($pair));

            if (count($parts) !== 2) {
                continue;
            }

            [$uuid, $token] = $parts;
            $uuid = strtolower($uuid);

            if (preg_match(self::UUID_PATTERN, $uuid) === 1 && preg_match(self::TOKEN_PATTERN, $token) === 1) {
                $this->tokens[$uuid] = $token;
            }
        }
    }

    /**
     * Seals $key into the session and returns the token for the renderer.
     * The token is also valid for the rest of this request.
     */
    public function store(Vault $vault, VaultKey $key): string
    {
        $token = $this->encryption->newSessionToken();
        $epoch = $this->epoch();
        $sealed = $this->encryption->sealForSession($key, $vault->uuid, $epoch, $token);
        $now = Carbon::now()->getTimestamp();

        $this->session->put(self::SESSION_PREFIX.$vault->uuid, [
            'key_id' => $key->keyId,
            'nonce' => $sealed['nonce'],
            'sealed' => $sealed['sealed'],
            'unlocked_at' => $now,
            'last_used_at' => $now,
            'epoch' => $epoch,
        ]);

        unset($this->opened[$vault->uuid]);
        $this->tokens[$vault->uuid] = $token;

        return $token;
    }

    /**
     * The vault's key if it is unlocked for this request, else null. Any
     * epoch, idle or key id mismatch purges the entry (= locked).
     * `touch: false` leaves the idle timer alone (background checks).
     */
    public function keyFor(Vault $vault, bool $touch = true): ?VaultKey
    {
        $name = self::SESSION_PREFIX.$vault->uuid;
        $entry = $this->session->get($name);

        if (! is_array($entry)) {
            return null;
        }

        $now = Carbon::now()->getTimestamp();

        if (($entry['epoch'] ?? null) !== $this->epoch() || $this->isIdle($entry, $now)) {
            $this->forget($vault);

            return null;
        }

        $mirrorKeyId = VaultEncryption::query()->where('vault_id', $vault->id)->value('key_id');

        if (! is_string($mirrorKeyId) || ($entry['key_id'] ?? null) !== $mirrorKeyId) {
            $this->forget($vault);

            return null;
        }

        $token = $this->tokens[$vault->uuid] ?? null;

        if ($token === null) {
            return null;
        }

        $key = $this->opened[$vault->uuid]
            ?? $this->encryption->openFromSession($entry, $vault->uuid, $entry['epoch'], $token);

        if ($key === null) {
            return null;
        }

        $this->opened[$vault->uuid] = $key;

        if ($touch) {
            $entry['last_used_at'] = $now;
            $this->session->put($name, $entry);
        }

        return $key;
    }

    /**
     * @throws VaultLockedException
     */
    public function requireKey(Vault $vault): VaultKey
    {
        return $this->keyFor($vault) ?? throw new VaultLockedException;
    }

    public function isUnlocked(Vault $vault): bool
    {
        return $this->keyFor($vault, false) !== null;
    }

    public function forget(Vault $vault): void
    {
        $this->session->forget(self::SESSION_PREFIX.$vault->uuid);
        unset($this->opened[$vault->uuid], $this->tokens[$vault->uuid]);
    }

    public function forgetAll(): void
    {
        // Dot notation nests the entries under `mdvault` => `keyring`.
        $this->session->forget(rtrim(self::SESSION_PREFIX, '.'));

        $this->opened = [];
        $this->tokens = [];
    }

    /**
     * Invalidates every unlocked vault in every session: the epoch is part
     * of each sealed entry's associated data.
     */
    public function lockEverywhere(): void
    {
        $this->cache->forever(self::EPOCH_CACHE_KEY, $this->epoch() + 1);
        $this->forgetAll();
    }

    private function epoch(): int
    {
        $epoch = $this->cache->get(self::EPOCH_CACHE_KEY, 0);

        return is_int($epoch) ? $epoch : 0;
    }

    /**
     * @param  array<mixed>  $entry
     */
    private function isIdle(array $entry, int $now): bool
    {
        $minutes = $this->settings->integer(SettingKey::SecurityAutoLockMinutes);

        // Only an explicit 0 means "never"; a negative (corrupt) value falls
        // back to the default rather than failing open.
        if ($minutes < 0) {
            $minutes = (int) SettingKey::SecurityAutoLockMinutes->default();
        }

        if ($minutes === 0) {
            return false;
        }

        $lastUsed = $entry['last_used_at'] ?? null;

        if (! is_int($lastUsed)) {
            return true;
        }

        return $now - $lastUsed > $minutes * 60 + self::IDLE_GRACE_SECONDS;
    }
}
