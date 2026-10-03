<?php

arch()->preset()->php();
arch()->preset()->security();

arch('services are final and suffixed with Service')
    ->expect('App\Services')
    ->classes()
    ->toBeFinal()
    ->toHaveSuffix('Service');

arch('services do not depend on the HTTP layer')
    ->expect('App\Services')
    ->not->toUse(['App\Http', 'Illuminate\Http', 'Inertia']);

arch('HTTP layer does not touch the filesystem directly')
    ->expect([
        'Illuminate\Support\Facades\File',
        'Illuminate\Support\Facades\Storage',
        'file_put_contents',
        'file_get_contents',
        'fopen',
        'unlink',
        'mkdir',
        'rmdir',
        'rename',
        'copy',
        'scandir',
    ])
    ->not->toBeUsedIn('App\Http');

arch('settings are only accessed through SettingsService')
    ->expect('App\Models\Setting')
    ->toOnlyBeUsedIn('App\Services\SettingsService');

arch('enums folder only contains enums')
    ->expect('App\Enums')
    ->toBeEnums();

arch('native dialogs only via NativeDialogService')
    ->expect('Native\Desktop\Dialog')
    ->toOnlyBeUsedIn('App\Services\NativeDialogService');

arch('the OS shell is only used via NativeTrash')
    ->expect(['Native\Desktop\Facades\Shell', 'Native\Desktop\Shell'])
    ->toOnlyBeUsedIn('App\Support\NativeTrash');

arch('vault records are only used by services, controllers, models and factories')
    ->expect('App\Models\Vault')
    ->toOnlyBeUsedIn(['App\Services', 'App\Http\Controllers', 'App\Models', 'Database\Factories']);

arch('note records are only used by services, controllers, models and factories')
    ->expect('App\Models\Note')
    ->toOnlyBeUsedIn(['App\Services', 'App\Http\Controllers', 'App\Models', 'Database\Factories']);

arch('files are hashed only in FileHashService')
    ->expect('hash_file')
    ->toOnlyBeUsedIn('App\Services\FileHashService');

arch('only FileStorageService deletes, writes or syncs files')
    ->expect(['unlink', 'file_put_contents', 'fsync'])
    ->toOnlyBeUsedIn('App\Services\FileStorageService');

arch('index and note services use no raw filesystem functions')
    ->expect([
        'file_get_contents',
        'file_put_contents',
        'fopen',
        'unlink',
        'rmdir',
        'mkdir',
        'rename',
        'copy',
        'scandir',
        'hash_file',
        'Illuminate\Support\Facades\File',
        'Illuminate\Support\Facades\Storage',
    ])
    ->not->toBeUsedIn(['App\Services\VaultIndexService', 'App\Services\NoteService', 'App\Services\MarkdownService', 'App\Services\ExternalChangeService', 'App\Services\BackupService', 'App\Services\ArchiveService', 'App\Services\EncryptedNoteService']);

// ArchiveService itself is exempt from the rule above only for ZipArchive
// (below); it must not use fopen/unlink either, which the rule above
// already enforces since it names ArchiveService explicitly.

arch('zip archives only via ArchiveService')
    ->expect('ZipArchive')
    ->toOnlyBeUsedIn('App\Services\ArchiveService')
    // Tests craft hostile/invalid archives directly with ZipArchive
    // (tests/Pest.php's makeZip() helper and ArchiveServiceTest's
    // symlink/encrypted-entry fixtures) to exercise BackupService's
    // validation without going through the write() boundary. Documented
    // in implementation.md per the plan's T3 instruction.
    ->ignoring('Tests');

arch('backup records are only used by services, controllers, models and factories')
    ->expect('App\Models\Backup')
    ->toOnlyBeUsedIn(['App\Services', 'App\Http\Controllers', 'App\Models', 'Database\Factories']);

arch('libsodium primitives are only used in EncryptionService')
    ->expect([
        'sodium_crypto_pwhash',
        'sodium_crypto_aead_xchacha20poly1305_ietf_encrypt',
        'sodium_crypto_aead_xchacha20poly1305_ietf_decrypt',
        'sodium_crypto_aead_xchacha20poly1305_ietf_keygen',
        'sodium_crypto_kdf_derive_from_key',
        'sodium_bin2base64',
        'sodium_base642bin',
    ])
    ->toOnlyBeUsedIn('App\Services\EncryptionService');

arch('key material is only wiped by EncryptionService and VaultKey')
    ->expect('sodium_memzero')
    ->toOnlyBeUsedIn(['App\Services\EncryptionService', 'App\Support\VaultKey']);

arch('vault encryption mirror records are only used by services, models and database code')
    ->expect('App\Models\VaultEncryption')
    ->toOnlyBeUsedIn(['App\Services', 'App\Models', 'Database']);

arch('vault keys only flow through services and support classes')
    ->expect('App\Support\VaultKey')
    ->toOnlyBeUsedIn(['App\Services', 'App\Support'])
    // Tests build keys directly to exercise the crypto service
    // (VaultKeyTest, EncryptionServiceTest) and the redaction rules.
    ->ignoring('Tests');

arch('the crypto and keyring services never log, report or dump')
    ->expect([
        'Illuminate\Support\Facades\Log',
        'logger',
        'info',
        'report',
        'dump',
        'dd',
        'var_dump',
        'print_r',
        'var_export',
        'error_log',
    ])
    ->not->toBeUsedIn(['App\Services\EncryptionService', 'App\Services\VaultKeyService']);

arch('the encryption service uses no raw filesystem functions')
    ->expect([
        'file_get_contents',
        'file_put_contents',
        'fopen',
        'unlink',
        'rmdir',
        'mkdir',
        'rename',
        'copy',
        'scandir',
        'hash_file',
        'Illuminate\Support\Facades\File',
        'Illuminate\Support\Facades\Storage',
    ])
    ->not->toBeUsedIn([
        'App\Services\VaultEncryptionService',
        'App\Services\EncryptionHeaderService',
        'App\Services\VaultConversionService',
        'App\Services\VaultRecoveryService',
    ]);

arch('the conversion services never log or dump')
    ->expect(['Illuminate\Support\Facades\Log', 'logger', 'info', 'dump', 'dd', 'var_dump', 'print_r', 'var_export', 'error_log'])
    ->not->toBeUsedIn(['App\Services\VaultConversionService', 'App\Services\VaultRecoveryService']);

arch('services, controllers and support classes never use the File facade')
    ->expect(['Illuminate\Support\Facades\File'])
    ->not->toBeUsedIn(['App\Services', 'App\Http', 'App\Support']);

/**
 * Every PHP file outside tests/ and vendor/ that matches $pattern.
 *
 * @return list<string> paths relative to the project root, '/'-separated
 */
function filesMatchingInApplicationCode(string $pattern): array
{
    $root = dirname(__DIR__, 2);
    $matches = [];

    foreach (['app', 'bootstrap', 'config', 'database', 'routes'] as $directory) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php' && preg_match($pattern, (string) file_get_contents($file->getPathname())) === 1) {
                $matches[] = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($root) + 1));
            }
        }
    }

    sort($matches);

    return $matches;
}

test('every sodium_ function is only called from EncryptionService and VaultKey', function () {
    // Prefix-based: also catches any libsodium function not named above.
    expect(filesMatchingInApplicationCode('/\bsodium_[a-z0-9_]+\s*\(/i'))
        ->toEqualCanonicalizing(['app/Services/EncryptionService.php', 'app/Support/VaultKey.php']);

    // VaultKey may only wipe.
    $vaultKey = (string) file_get_contents(dirname(__DIR__, 2).'/app/Support/VaultKey.php');
    preg_match_all('/\bsodium_[a-z0-9_]+/i', $vaultKey, $used);

    expect(array_unique($used[0]))->toBe(['sodium_memzero']);
});

test('VaultKey::material() is only called from EncryptionService', function () {
    expect(filesMatchingInApplicationCode('/->material\s*\(/'))
        ->toBe(['app/Services/EncryptionService.php']);
});
