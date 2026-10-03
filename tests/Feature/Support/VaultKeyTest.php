<?php

use App\Support\VaultKey;

beforeEach(function () {
    $this->material = random_bytes(32);
    $this->key = new VaultKey($this->material, '0198a000-0000-7000-8000-000000000001');
    $this->needles = [$this->material, base64_encode($this->material), bin2hex($this->material)];
});

function vaultKeyTestContainsAny(string $haystack, array $needles): bool
{
    foreach ($needles as $needle) {
        if (str_contains($haystack, $needle)) {
            return true;
        }
    }

    return false;
}

test('print_r never shows the key material', function () {
    expect(vaultKeyTestContainsAny(print_r($this->key, true), $this->needles))->toBeFalse()
        ->and(print_r($this->key, true))->toContain('[redacted]');
});

test('var_dump never shows the key material', function () {
    ob_start();
    var_dump($this->key);
    $dump = (string) ob_get_clean();

    expect(vaultKeyTestContainsAny($dump, $this->needles))->toBeFalse()
        ->and($dump)->toContain('[redacted]');
});

test('json_encode never shows the key material', function () {
    expect(vaultKeyTestContainsAny((string) json_encode($this->key), $this->needles))->toBeFalse();
});

test('a key cannot be serialized', function () {
    serialize($this->key);
})->throws(LogicException::class);

test('a key cannot be cloned', function () {
    clone $this->key;
})->throws(LogicException::class);

test('the key id stays readable and the material is available to the encryption service', function () {
    expect($this->key->keyId)->toBe('0198a000-0000-7000-8000-000000000001')
        ->and($this->key->material())->toBe($this->material);
});
