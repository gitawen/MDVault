<?php

use App\Enums\EditorDefaultView;
use App\Enums\EditorFontFamily;
use App\Enums\IndexMode;
use App\Enums\RestoreAction;
use App\Enums\SettingGroup;
use App\Enums\SettingKey;
use App\Enums\Theme;
use App\Exceptions\BackupException;
use App\Models\Backup;
use App\Models\Note;
use App\Models\Vault;
use App\Services\BackupService;
use App\Services\SettingsService;
use App\Services\VaultIndexService;
use App\Services\VaultService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mdvault-backup-settings-'.Str::random(8);
    fakeDocumentsDirectory($this->tmp.DIRECTORY_SEPARATOR.'Documents');
    File::makeDirectory($this->tmp.DIRECTORY_SEPARATOR.'Documents', 0755, true);
    $this->root = $this->tmp.DIRECTORY_SEPARATOR.'Documents'.DIRECTORY_SEPARATOR.'MDVault';
    $this->destDir = $this->tmp.DIRECTORY_SEPARATOR.'Backups';
    File::makeDirectory($this->destDir, 0755, true);

    $this->vaults = app(VaultService::class);
    $this->index = app(VaultIndexService::class);
    $this->settings = app(SettingsService::class);
    $this->service = app(BackupService::class);
});

afterEach(function () {
    File::deleteDirectory($this->tmp);
});

function createSampleVaultForSettingsBackup(string $name = 'Work'): Vault
{
    $vault = test()->vaults->create($name);
    writeVaultFiles($vault->path, ['Note.md' => "hello\n"]);
    test()->index->reconcile($vault, IndexMode::Full);

    return $vault;
}

function customizeAllPortableSettings(SettingsService $settings): void
{
    $settings->setMany([
        SettingKey::AppearanceTheme->value => Theme::Dark->value,
        SettingKey::EditorFontSize->value => 18,
        SettingKey::EditorFontFamily->value => EditorFontFamily::Mono->value,
        SettingKey::EditorLineHeight->value => 2.0,
        SettingKey::EditorWordWrap->value => false,
        SettingKey::EditorShowLineNumbers->value => true,
        SettingKey::EditorIndentSize->value => 2,
        SettingKey::EditorNewNoteTemplateEnabled->value => false,
        SettingKey::EditorNewNoteTemplate->value => "title: {{title}}\ntags: [work]",
        SettingKey::EditorDefaultView->value => EditorDefaultView::Split->value,
        SettingKey::CheckExternalChanges->value => false,
        SettingKey::SecurityAutoLockMinutes->value => 30,
        SettingKey::SecurityLockOnScreenLock->value => false,
    ]);
}

/**
 * Rewrites `manifest.json` inside an existing ZIP archive using the provided
 * callback, keeping all other archive entries verbatim.
 *
 * @param  callable(array<string, mixed>): array<string, mixed>  $mutator
 */
function rewriteManifestInArchive(string $sourceZip, string $destZip, callable $mutator): void
{
    $zip = new ZipArchive;
    $zip->open($sourceZip, ZipArchive::RDONLY);
    $entries = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        $name = $stat['name'];

        if (str_ends_with($name, '/')) {
            $entries[$name] = null;
        } else {
            $entries[$name] = $zip->getFromIndex($i);
        }
    }

    $zip->close();

    $manifest = json_decode((string) $entries['manifest.json'], true, 512, JSON_THROW_ON_ERROR);
    $entries['manifest.json'] = json_encode($mutator($manifest), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

    makeZip($destZip, $entries);
}

// --- 1. Enum classification ---------------------------------------------------

test('enums classify portable settings, groups, and labels exhaustively', function () {
    $portable = SettingKey::portableCases();
    $nonPortable = [
        SettingKey::StorageRootPath,
        SettingKey::StorageFolderName,
        SettingKey::CurrentVault,
    ];

    expect($portable)->toHaveCount(13);

    foreach ($portable as $key) {
        expect($key->isPortable())->toBeTrue();
    }

    foreach ($nonPortable as $key) {
        expect($key->isPortable())->toBeFalse()
            ->and($portable)->not->toContain($key);
    }

    expect(SettingKey::portableGroups())->toBe([
        SettingGroup::Appearance,
        SettingGroup::Editor,
        SettingGroup::General,
        SettingGroup::Security,
    ])->and(SettingKey::portableGroupValues())->toBe([
        'appearance',
        'editor',
        'general',
        'security',
    ]);

    expect(SettingGroup::General->label())->toBe('General')
        ->and(SettingGroup::Storage->label())->toBe('Storage')
        ->and(SettingGroup::Editor->label())->toBe('Editor')
        ->and(SettingGroup::Appearance->label())->toBe('Appearance')
        ->and(SettingGroup::Backup->label())->toBe('Backup')
        ->and(SettingGroup::Security->label())->toBe('Security');

    expect(SettingGroup::Appearance->isPortable())->toBeTrue()
        ->and(SettingGroup::Editor->isPortable())->toBeTrue()
        ->and(SettingGroup::General->isPortable())->toBeTrue()
        ->and(SettingGroup::Security->isPortable())->toBeTrue()
        ->and(SettingGroup::Storage->isPortable())->toBeFalse()
        ->and(SettingGroup::Backup->isPortable())->toBeFalse();
});

// --- 2. Export into manifest (all & single vault) -----------------------------

test('both full and single-vault backups write format 3 with all 13 portable settings and exclude storage, current vault, and backup history', function () {
    $work = createSampleVaultForSettingsBackup('Work');
    $personal = createSampleVaultForSettingsBackup('Personal');
    customizeAllPortableSettings($this->settings);
    $this->settings->set(SettingKey::StorageRootPath, $this->root);
    $this->settings->set(SettingKey::StorageFolderName, 'CustomVaults');
    $this->settings->set(SettingKey::CurrentVault, $work->uuid);

    Backup::query()->create([
        'scope' => 'all',
        'filename' => 'earlier.zip',
        'path' => $this->destDir.DIRECTORY_SEPARATOR.'earlier.zip',
        'file_size' => 123,
        'file_hash' => str_repeat('a', 64),
        'format_version' => 2,
        'vault_count' => 1,
        'note_count' => 1,
        'file_count' => 0,
        'contents' => [['uuid' => $work->uuid, 'name' => $work->name, 'notes' => 1]],
    ]);

    $allZip = $this->destDir.DIRECTORY_SEPARATOR.'all.zip';
    $singleZip = $this->destDir.DIRECTORY_SEPARATOR.'single.zip';

    $this->service->create(null, $allZip);
    $this->service->create($personal, $singleZip);

    foreach ([$allZip, $singleZip] as $zipPath) {
        $manifestJson = zipEntryContents($zipPath, 'manifest.json');
        $manifest = json_decode($manifestJson, true, 512, JSON_THROW_ON_ERROR);

        expect($manifest['format_version'])->toBe(3)
            ->and($manifest)->toHaveKey('settings')
            ->and($manifest)->not->toHaveKey('backups')
            ->and($manifestJson)->toContain('"editor.line_height": 2.0')
            ->and($manifestJson)->not->toContain('earlier.zip')
            ->and($manifestJson)->not->toContain('CustomVaults')
            ->and($manifestJson)->not->toContain(str_replace('\\', '/', $this->root));

        $settings = $manifest['settings'];
        $expectedKeys = array_map(static fn (SettingKey $k): string => $k->value, SettingKey::portableCases());
        sort($expectedKeys);

        expect(array_keys($settings))->toBe($expectedKeys)
            ->and($settings['appearance.theme'])->toBe('dark')
            ->and($settings['editor.font_size'])->toBe(18)
            ->and($settings['editor.font_family'])->toBe('mono')
            ->and((float) $settings['editor.line_height'])->toBe(2.0)
            ->and($settings['editor.word_wrap'])->toBeFalse()
            ->and($settings['editor.show_line_numbers'])->toBeTrue()
            ->and($settings['editor.indent_size'])->toBe(2)
            ->and($settings['editor.new_note_template_enabled'])->toBeFalse()
            ->and($settings['editor.new_note_template'])->toBe("title: {{title}}\ntags: [work]")
            ->and($settings['editor.default_view'])->toBe('split')
            ->and($settings['app.check_external_changes'])->toBeFalse()
            ->and($settings['security.auto_lock_minutes'])->toBe(30)
            ->and($settings['security.lock_on_screen_lock'])->toBeFalse()
            ->and($settings)->not->toHaveKey('storage.root_path')
            ->and($settings)->not->toHaveKey('storage.folder_name')
            ->and($settings)->not->toHaveKey('app.current_vault');
    }
});

// --- 3. Backward compatibility (formats 1 & 2) --------------------------------

test('format 1 and format 2 backups without settings inspect with null settings and restore vaults cleanly', function () {
    $vault = createSampleVaultForSettingsBackup('Work');
    $v3Zip = $this->destDir.DIRECTORY_SEPARATOR.'v3.zip';
    $this->service->create($vault, $v3Zip);

    foreach ([1, 2] as $legacyVersion) {
        $legacyZip = $this->destDir.DIRECTORY_SEPARATOR."v{$legacyVersion}.zip";
        rewriteManifestInArchive($v3Zip, $legacyZip, function (array $manifest) use ($legacyVersion): array {
            $manifest['format_version'] = $legacyVersion;
            unset($manifest['settings']);

            return $manifest;
        });

        $inspection = $this->service->inspect($legacyZip);

        expect($inspection->valid)->toBeTrue()
            ->and($inspection->backup['format_version'])->toBe($legacyVersion)
            ->and($inspection->backup['settings'])->toBeNull();

        expect(fn () => $this->service->restore($legacyZip, [$vault->uuid => RestoreAction::Skip], [SettingGroup::Editor]))
            ->toThrow(BackupException::class, "This backup doesn't include settings.");

        $this->post(route('settings.backup.restore.store'), [
            'path' => $legacyZip,
            'vaults' => [['uuid' => $vault->uuid, 'action' => 'skip']],
            'setting_groups' => ['editor'],
        ])->assertSessionHasErrors('setting_groups');

        Note::query()->delete();
        Vault::query()->delete();
        File::deleteDirectory($this->root);

        $result = $this->service->restore($legacyZip, [$vault->uuid => RestoreAction::Restore]);
        expect($result->restored)->toHaveCount(1)
            ->and($result->settingsRestored)->toBeFalse()
            ->and($result->summary())->toBe('Restored 1 vault(s) (1 note(s)).');
    }
});

// --- 4. Strict validation -----------------------------------------------------

test('validation rejects invalid settings structures, non-portable keys, invalid values, and ignores unknown future keys', function () {
    $vault = createSampleVaultForSettingsBackup('Work');
    $baseZip = $this->destDir.DIRECTORY_SEPARATOR.'base.zip';
    $this->service->create($vault, $baseZip);

    $invalidCases = [
        'missing settings key' => function (array $m): array {
            unset($m['settings']);

            return $m;
        },
        'settings is a list instead of object' => function (array $m): array {
            $m['settings'] = ['dark', 16];

            return $m;
        },
        'settings is a string' => function (array $m): array {
            $m['settings'] = 'invalid';

            return $m;
        },
        'injected storage.root_path' => function (array $m): array {
            $m['settings']['storage.root_path'] = '/tmp/evil';

            return $m;
        },
        'injected storage.folder_name' => function (array $m): array {
            $m['settings']['storage.folder_name'] = 'Evil';

            return $m;
        },
        'injected app.current_vault' => function (array $m): array {
            $m['settings']['app.current_vault'] = (string) Str::uuid();

            return $m;
        },
        'invalid theme' => function (array $m): array {
            $m['settings']['appearance.theme'] = 'sepia';

            return $m;
        },
        'invalid font family' => function (array $m): array {
            $m['settings']['editor.font_family'] = 'comic-sans';

            return $m;
        },
        'invalid default view' => function (array $m): array {
            $m['settings']['editor.default_view'] = 'wysiwyg';

            return $m;
        },
        'out of range font size' => function (array $m): array {
            $m['settings']['editor.font_size'] = 25;

            return $m;
        },
        'out of range indent size' => function (array $m): array {
            $m['settings']['editor.indent_size'] = 0;

            return $m;
        },
        'out of range line height' => function (array $m): array {
            $m['settings']['editor.line_height'] = 2.5;

            return $m;
        },
        'invalid auto lock choice' => function (array $m): array {
            $m['settings']['security.auto_lock_minutes'] = 12;

            return $m;
        },
        'string boolean' => function (array $m): array {
            $m['settings']['editor.word_wrap'] = 'true';

            return $m;
        },
        'nul byte in template' => function (array $m): array {
            $m['settings']['editor.new_note_template'] = "title: a\0b";

            return $m;
        },
        'oversized template' => function (array $m): array {
            $m['settings']['editor.new_note_template'] = str_repeat('a', 4001);

            return $m;
        },
        'bare triple dash in template' => function (array $m): array {
            $m['settings']['editor.new_note_template'] = "title: test\n---\nbody";

            return $m;
        },
        'empty template when enabled' => function (array $m): array {
            $m['settings']['editor.new_note_template_enabled'] = true;
            $m['settings']['editor.new_note_template'] = "   \n";

            return $m;
        },
    ];

    foreach ($invalidCases as $label => $mutator) {
        $badZip = $this->destDir.DIRECTORY_SEPARATOR.'bad-'.Str::slug($label).'.zip';
        rewriteManifestInArchive($baseZip, $badZip, $mutator);

        $inspection = $this->service->inspect($badZip);
        expect($inspection->valid)->toBeFalse("Expected inspect() invalid for: {$label}")
            ->and($inspection->problems)->not->toBe([]);

        expect(fn () => $this->service->restore($badZip, [$vault->uuid => RestoreAction::Skip], [SettingGroup::Editor]))
            ->toThrow(BackupException::class);
    }

    // Empty template IS allowed when new_note_template_enabled is false, and unknown keys are ignored safely.
    $validEdgeZip = $this->destDir.DIRECTORY_SEPARATOR.'valid-edge.zip';
    rewriteManifestInArchive($baseZip, $validEdgeZip, function (array $m): array {
        $m['settings']['editor.new_note_template_enabled'] = false;
        $m['settings']['editor.new_note_template'] = '';
        $m['settings']['future.unknown_preference'] = 'ignored';

        return $m;
    });

    $edgeInspection = $this->service->inspect($validEdgeZip);
    expect($edgeInspection->valid)->toBeTrue();

    $result = $this->service->restore($validEdgeZip, [$vault->uuid => RestoreAction::Skip], [SettingGroup::Editor]);
    expect($result->settingsRestored)->toBeTrue()
        ->and($this->settings->boolean(SettingKey::EditorNewNoteTemplateEnabled))->toBeFalse()
        ->and($this->settings->string(SettingKey::EditorNewNoteTemplate))->toBe('');
});

// --- 5. Inspection diffs & smart defaults -------------------------------------

test('inspect computes per-group differing counts and default_restore correctly', function () {
    $vault = createSampleVaultForSettingsBackup('Work');
    customizeAllPortableSettings($this->settings);

    $zip = $this->destDir.DIRECTORY_SEPARATOR.'custom.zip';
    $this->service->create($vault, $zip);

    // Same installation with matching settings & registered vault: differing 0, default_restore false.
    $same = $this->service->inspect($zip);
    expect($same->backup['settings']['total_count'])->toBe(13)
        ->and($same->backup['settings']['differing_count'])->toBe(0)
        ->and($same->backup['settings']['default_restore'])->toBeFalse();

    // Reset to fresh unconfigured state: no vaults, all settings default => default_restore true, all 13 differ.
    simulateFreshInstall($this->root);
    $freshService = app(BackupService::class);
    $freshInspection = $freshService->inspect($zip);

    expect($freshInspection->backup['settings']['default_restore'])->toBeTrue()
        ->and($freshInspection->backup['settings']['total_count'])->toBe(13)
        ->and($freshInspection->backup['settings']['differing_count'])->toBe(13);

    $groupsByName = collect($freshInspection->backup['settings']['groups'])->keyBy('group');
    expect($groupsByName['appearance'])->toBe(['group' => 'appearance', 'label' => 'Appearance', 'count' => 1, 'differing' => 1])
        ->and($groupsByName['editor'])->toBe(['group' => 'editor', 'label' => 'Editor', 'count' => 9, 'differing' => 9])
        ->and($groupsByName['general'])->toBe(['group' => 'general', 'label' => 'General', 'count' => 1, 'differing' => 1])
        ->and($groupsByName['security'])->toBe(['group' => 'security', 'label' => 'Security', 'count' => 2, 'differing' => 2]);

    // Customize a single portable setting while vaults remain empty => default_restore becomes false.
    app(SettingsService::class)->set(SettingKey::AppearanceTheme, Theme::Light->value);
    $customizedInspection = app(BackupService::class)->inspect($zip);
    expect($customizedInspection->backup['settings']['default_restore'])->toBeFalse();
});

// --- 6. Selective restore, sparse defaults, normalization, untouched state ----

test('selective per-group restore updates only chosen groups, keeps defaults sparse, normalizes templates, and preserves local state', function () {
    $vault = createSampleVaultForSettingsBackup('Work');
    customizeAllPortableSettings($this->settings);

    $zip = $this->destDir.DIRECTORY_SEPARATOR.'custom.zip';
    $this->service->create($vault, $zip);

    // Prepare a second archive with CRLF + trailing newlines in template and default security values.
    $normalizedZip = $this->destDir.DIRECTORY_SEPARATOR.'norm.zip';
    rewriteManifestInArchive($zip, $normalizedZip, function (array $m): array {
        $m['settings']['editor.new_note_template_enabled'] = true;
        $m['settings']['editor.new_note_template'] = "title: {{title}}\r\ncustom: yes\r\n\r\n";
        $m['settings']['security.auto_lock_minutes'] = SettingKey::SecurityAutoLockMinutes->default();
        $m['settings']['security.lock_on_screen_lock'] = SettingKey::SecurityLockOnScreenLock->default();

        return $m;
    });

    // Reset target installation and give it distinct local storage, current_vault, backup history, and appearance.
    $this->settings->setMany([
        SettingKey::AppearanceTheme->value => Theme::Light->value,
        SettingKey::EditorFontSize->value => 14,
        SettingKey::SecurityAutoLockMinutes->value => 60,
        SettingKey::SecurityLockOnScreenLock->value => false,
        SettingKey::CheckExternalChanges->value => true,
        SettingKey::StorageRootPath->value => $this->root,
        SettingKey::StorageFolderName->value => 'TargetFolder',
        SettingKey::CurrentVault->value => $vault->uuid,
    ]);
    $backupCountBefore = Backup::count();

    // Restore only Editor and Security (leaving Appearance and General untouched).
    $result = $this->service->restore(
        $normalizedZip,
        [$vault->uuid => RestoreAction::Skip],
        [SettingGroup::Editor, SettingGroup::Security],
    );

    expect($result->settingsRestored)->toBeTrue()
        ->and($result->restoredSettingGroups)->toBe(['editor', 'security'])
        ->and($this->settings->string(SettingKey::EditorNewNoteTemplate))->toBe("title: {{title}}\ncustom: yes")
        ->and($this->settings->integer(SettingKey::EditorFontSize))->toBe(18)
        // Security matched defaults so rows were forgotten (sparse storage).
        ->and($this->settings->has(SettingKey::SecurityAutoLockMinutes))->toBeFalse()
        ->and($this->settings->has(SettingKey::SecurityLockOnScreenLock))->toBeFalse()
        // Unselected groups remained untouched.
        ->and($this->settings->string(SettingKey::AppearanceTheme))->toBe('light')
        ->and($this->settings->boolean(SettingKey::CheckExternalChanges))->toBeTrue()
        // Non-portable settings and backup history remained untouched.
        ->and($this->settings->string(SettingKey::StorageRootPath))->toBe($this->root)
        ->and($this->settings->string(SettingKey::StorageFolderName))->toBe('TargetFolder')
        ->and($this->settings->string(SettingKey::CurrentVault))->toBe($vault->uuid)
        ->and(Backup::count())->toBe($backupCountBefore);
});

// --- 7. Settings-only restore & HTTP validation -------------------------------

test('settings-only restore works via HTTP even without a storage root on disk and rejects invalid selections', function () {
    $vault = createSampleVaultForSettingsBackup('Work');
    customizeAllPortableSettings($this->settings);

    $zip = $this->destDir.DIRECTORY_SEPARATOR.'settings-only.zip';
    $this->service->create($vault, $zip);

    // Wipe storage root and reset settings back to defaults.
    Note::query()->delete();
    Vault::query()->delete();
    File::deleteDirectory($this->root);
    app(SettingsService::class)->setMany([
        SettingKey::AppearanceTheme->value => null,
        SettingKey::EditorFontSize->value => null,
        SettingKey::SecurityAutoLockMinutes->value => null,
        SettingKey::CheckExternalChanges->value => null,
    ]);

    // All vaults skipped + empty setting_groups => validation error on vaults.
    $this->post(route('settings.backup.restore.store'), [
        'path' => $zip,
        'vaults' => [['uuid' => $vault->uuid, 'action' => 'skip']],
        'setting_groups' => [],
    ])->assertSessionHasErrors('vaults');

    // Non-portable group name rejected.
    $this->post(route('settings.backup.restore.store'), [
        'path' => $zip,
        'vaults' => [['uuid' => $vault->uuid, 'action' => 'skip']],
        'setting_groups' => ['storage'],
    ])->assertSessionHasErrors('setting_groups.0');

    // Settings-only restore succeeds without creating storage root on disk.
    $response = $this->post(route('settings.backup.restore.store'), [
        'path' => $zip,
        'vaults' => [['uuid' => $vault->uuid, 'action' => 'skip']],
        'setting_groups' => ['appearance', 'security'],
    ]);

    $response->assertRedirect(route('settings.backup.edit'))
        ->assertInertiaFlash('toast.type', 'success')
        ->assertInertiaFlash('toast.message', 'Restored settings.');

    expect(is_dir($this->root))->toBeFalse();

    $freshSettings = app(SettingsService::class);
    expect($freshSettings->string(SettingKey::AppearanceTheme))->toBe('dark')
        ->and($freshSettings->integer(SettingKey::SecurityAutoLockMinutes))->toBe(30)
        // Editor was not selected so it stays default.
        ->and($freshSettings->integer(SettingKey::EditorFontSize))->toBe(16);
});

// --- 8. Atomicity & rollback --------------------------------------------------

test('a vault commit or folder-rename failure rolls back settings atomically', function () {
    $work = createSampleVaultForSettingsBackup('Work');
    $personal = createSampleVaultForSettingsBackup('Personal');
    customizeAllPortableSettings($this->settings);

    $zip = $this->destDir.DIRECTORY_SEPARATOR.'two-vaults.zip';
    $this->service->create(null, $zip);

    Note::query()->delete();
    Vault::query()->delete();
    File::deleteDirectory($this->root);

    $this->settings->setMany([
        SettingKey::AppearanceTheme->value => Theme::Light->value,
        SettingKey::EditorFontSize->value => 13,
    ]);

    $calls = 0;
    Vault::creating(function () use (&$calls): void {
        $calls++;

        if ($calls === 2) {
            throw new RuntimeException('simulated DB failure');
        }
    });

    expect(fn () => $this->service->restore($zip, [
        $work->uuid => RestoreAction::Restore,
        $personal->uuid => RestoreAction::Restore,
    ], [SettingGroup::Appearance, SettingGroup::Editor]))->toThrow(BackupException::class);

    $freshSettings = app(SettingsService::class);
    expect(Vault::count())->toBe(0)
        ->and($freshSettings->string(SettingKey::AppearanceTheme))->toBe('light')
        ->and($freshSettings->integer(SettingKey::EditorFontSize))->toBe(13);

    // Also test folder-rename failure rollback.
    failFolderRenames([2, 3, 4]);
    $service = app(BackupService::class);

    expect(fn () => $service->restore($zip, [
        $work->uuid => RestoreAction::Restore,
        $personal->uuid => RestoreAction::Restore,
    ], [SettingGroup::Appearance, SettingGroup::Editor]))->toThrow(BackupException::class);

    $freshSettings = app(SettingsService::class);
    expect(Vault::count())->toBe(0)
        ->and($freshSettings->string(SettingKey::AppearanceTheme))->toBe('light')
        ->and($freshSettings->integer(SettingKey::EditorFontSize))->toBe(13);
});

// --- 9. Live Inertia appearance sharing ---------------------------------------

test('restoring appearance theme updates the shared Inertia appearance prop immediately', function () {
    $vault = createSampleVaultForSettingsBackup('Work');
    $this->settings->set(SettingKey::AppearanceTheme, Theme::Dark->value);

    $zip = $this->destDir.DIRECTORY_SEPARATOR.'theme.zip';
    $this->service->create($vault, $zip);

    $this->settings->set(SettingKey::AppearanceTheme, Theme::Light->value);

    $this->get(route('settings.backup.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('appearance', 'light'));

    $this->post(route('settings.backup.restore.store'), [
        'path' => $zip,
        'vaults' => [['uuid' => $vault->uuid, 'action' => 'skip']],
        'setting_groups' => ['appearance'],
    ])->assertRedirect(route('settings.backup.edit'));

    $this->get(route('settings.backup.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('appearance', 'dark'));
});

// --- 10. §55 full round trip with customized settings -------------------------

test('§55 full round trip restores vaults, notes, and all 13 portable settings while keeping target storage and backup history clean', function () {
    $vault = createSampleVaultForSettingsBackup('Work');
    customizeAllPortableSettings($this->settings);

    $backupPath = $this->destDir.DIRECTORY_SEPARATOR.'roundtrip.zip';
    $this->service->create(null, $backupPath);

    $newDocuments = simulateFreshInstall($this->root);
    $newRoot = $newDocuments.DIRECTORY_SEPARATOR.'MDVault';

    $freshService = app(BackupService::class);
    $inspection = $freshService->inspect($backupPath);

    expect($inspection->valid)->toBeTrue()
        ->and($inspection->backup['settings']['default_restore'])->toBeTrue();

    $result = $freshService->restore(
        $backupPath,
        [$vault->uuid => RestoreAction::Restore],
        SettingKey::portableGroups(),
    );

    expect($result->restored)->toHaveCount(1)
        ->and($result->settingsRestored)->toBeTrue()
        ->and($result->summary())->toBe('Restored 1 vault(s) (1 note(s)) and settings.');

    $restoredVault = Vault::query()->where('uuid', $vault->uuid)->first();
    expect($restoredVault)->not->toBeNull()
        ->and(str_starts_with($restoredVault->path, $newRoot))->toBeTrue()
        ->and(Backup::count())->toBe(0);

    $freshSettings = app(SettingsService::class);
    expect($freshSettings->string(SettingKey::AppearanceTheme))->toBe('dark')
        ->and($freshSettings->integer(SettingKey::EditorFontSize))->toBe(18)
        ->and($freshSettings->string(SettingKey::EditorFontFamily))->toBe('mono')
        ->and($freshSettings->float(SettingKey::EditorLineHeight))->toBe(2.0)
        ->and($freshSettings->boolean(SettingKey::EditorWordWrap))->toBeFalse()
        ->and($freshSettings->boolean(SettingKey::EditorShowLineNumbers))->toBeTrue()
        ->and($freshSettings->integer(SettingKey::EditorIndentSize))->toBe(2)
        ->and($freshSettings->boolean(SettingKey::EditorNewNoteTemplateEnabled))->toBeFalse()
        ->and($freshSettings->string(SettingKey::EditorNewNoteTemplate))->toBe("title: {{title}}\ntags: [work]")
        ->and($freshSettings->string(SettingKey::EditorDefaultView))->toBe('split')
        ->and($freshSettings->boolean(SettingKey::CheckExternalChanges))->toBeFalse()
        ->and($freshSettings->integer(SettingKey::SecurityAutoLockMinutes))->toBe(30)
        ->and($freshSettings->boolean(SettingKey::SecurityLockOnScreenLock))->toBeFalse()
        ->and($freshSettings->string(SettingKey::StorageRootPath))->toBeNull()
        ->and($freshSettings->string(SettingKey::StorageFolderName))->toBe('MDVault')
        ->and($freshSettings->string(SettingKey::CurrentVault))->toBe($vault->uuid);
});
