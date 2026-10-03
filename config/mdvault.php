<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Vault encryption
    |--------------------------------------------------------------------------
    |
    | Argon2id cost for newly created encrypted vaults (ADR
    | `vault-encryption-cryptography`). The cost is stored per vault, so
    | raising it later never breaks existing vaults. At creation it is raised
    | to at least libsodium's INTERACTIVE level unless `allow_weak_kdf` is on,
    | which only the test suite enables.
    |
    */

    'encryption' => [
        'kdf' => [
            'opslimit' => (int) env('MDVAULT_KDF_OPSLIMIT', SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE),
            'memlimit' => (int) env('MDVAULT_KDF_MEMLIMIT', SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE),
        ],
        'allow_weak_kdf' => (bool) env('MDVAULT_ALLOW_WEAK_KDF', false),
    ],

];
