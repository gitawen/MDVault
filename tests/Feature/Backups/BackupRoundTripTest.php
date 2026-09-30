<?php

use App\Enums\IndexMode;
use App\Enums\NoteSaveMode;
use App\Enums\RestoreAction;
use App\Models\Backup;
use App\Models\Note;
use App\Models\Vault;
use App\Services\BackupService;
use App\Services\FileHashService;
use App\Services\FileStorageService;
use App\Services\NoteService;
use App\Services\VaultIndexService;
use App\Services\VaultService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mdvault-roundtrip-'.Str::random(8);
    fakeDocumentsDirectory($this->tmp.DIRECTORY_SEPARATOR.'Documents');
    File::makeDirectory($this->tmp.DIRECTORY_SEPARATOR.'Documents', 0755, true);
    $this->root = $this->tmp.DIRECTORY_SEPARATOR.'Documents'.DIRECTORY_SEPARATOR.'MDVault';
    $this->outsideDir = $this->tmp.DIRECTORY_SEPARATOR.'Outside';
    File::makeDirectory($this->outsideDir, 0755, true);

    $this->vaults = app(VaultService::class);
    $this->index = app(VaultIndexService::class);
    $this->notes = app(NoteService::class);
    $this->files = app(FileStorageService::class);
    $this->hashes = app(FileHashService::class);
    $this->service = app(BackupService::class);
});

afterEach(function () {
    File::deleteDirectory($this->tmp);
});

/**
 * Builds the §55 fixture: two vaults, nested folders, an empty folder, a
 * unicode name, CRLF+BOM content, and an attachment. Returns everything
 * needed to verify a round trip: vault/note metadata and every file's
 * bytes, keyed by "<vault name>/<relative path>".
 *
 * @return array{
 *     work: Vault, personal: Vault,
 *     vaults: array<string, array{uuid: string, name: string, description: ?string}>,
 *     notes: array<string, array{uuid: string, vault: string, relative_path: string, file_hash: string, file_size: int}>,
 *     bytes: array<string, string>,
 * }
 */
function buildRoundTripFixture(): array
{
    $work = test()->vaults->create('Work', 'The work vault');
    $personal = test()->vaults->create('Personal', 'Personal notes');

    // A note created and then saved through the full service layer.
    $note = test()->notes->create($work, null, 'Overview');
    $preview = test()->notes->preview($note);
    test()->notes->save($note, 'Plain overview content.', $preview['base_hash'], NoteSaveMode::Source, null);
    $note->refresh();

    // A nested-folder note, an empty folder, a unicode name, CRLF+BOM
    // content and an attachment — written directly and reconciled, exactly
    // as external tools would produce them.
    test()->notes->createFolder($work, null, 'Empty');
    writeVaultFiles($work->path, [
        'Projects/Deep/Nested.md' => "nested content\n",
        'Café/Ünïcode.md' => "unicode content\n",
        'CrLf.md' => "\xEF\xBB\xBFTitle\r\n\r\nBody with CRLF\r\n",
        'assets/a.png' => "\x89PNG\x0D\x0A\x1A\x0A".random_bytes(32),
    ]);
    test()->index->reconcile($work, IndexMode::Full);

    $personalNote = test()->notes->create($personal, null, 'Ideas');

    $vaultsCaptured = [];
    $notesCaptured = [];
    $bytesCaptured = [];

    foreach ([$work, $personal] as $vault) {
        $vault->refresh();
        $vaultsCaptured[$vault->name] = [
            'uuid' => $vault->uuid,
            'name' => $vault->name,
            'description' => $vault->description,
        ];

        foreach ($vault->notes()->get() as $registeredNote) {
            $key = $vault->name.'/'.$registeredNote->relative_path;
            $notesCaptured[$key] = [
                'uuid' => $registeredNote->uuid,
                'vault' => $vault->name,
                'relative_path' => $registeredNote->relative_path,
                'file_hash' => $registeredNote->file_hash,
                'file_size' => $registeredNote->file_size,
            ];
            $bytesCaptured[$key] = File::get($vault->path.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $registeredNote->relative_path));
        }
    }

    // The attachment is not in `notes` — capture its bytes separately.
    $bytesCaptured['Work/assets/a.png'] = File::get($work->path.DIRECTORY_SEPARATOR.'assets'.DIRECTORY_SEPARATOR.'a.png');

    return [
        'work' => $work,
        'personal' => $personal,
        'workNoteUuid' => $note->uuid,
        'vaults' => $vaultsCaptured,
        'notes' => $notesCaptured,
        'bytes' => $bytesCaptured,
    ];
}

// --- Service level -------------------------------------------------------------

test('§55 round trip at the service level: backup, fresh install, restore, verify', function () {
    $fixture = buildRoundTripFixture();
    $oldRoot = $this->root;

    $backupPath = $this->outsideDir.DIRECTORY_SEPARATOR.'roundtrip.zip';
    $this->service->create(null, $backupPath);

    // Backup survives "remove local data → fresh application state".
    expect(File::exists($backupPath))->toBeTrue();
    $newDocuments = simulateFreshInstall($oldRoot);
    expect(File::exists($backupPath))->toBeTrue();

    $inspection = $this->service->inspect($backupPath);
    expect($inspection->valid)->toBeTrue();
    foreach ($inspection->vaults as $v) {
        expect($v['state'])->toBe('new');
    }

    $actions = [];
    foreach ($inspection->vaults as $v) {
        $actions[$v['uuid']] = RestoreAction::Restore;
    }

    $restoreService = app(BackupService::class);
    $restoreService->restore($backupPath, $actions);

    $newRoot = $newDocuments.DIRECTORY_SEPARATOR.'MDVault';

    foreach ($fixture['vaults'] as $name => $expected) {
        $vault = Vault::query()->where('uuid', $expected['uuid'])->first();

        expect($vault)->not->toBeNull()
            ->and($vault->name)->toBe($expected['name'])
            ->and($vault->description)->toBe($expected['description'])
            ->and($vault->relative_path)->toBe($name)
            ->and(str_starts_with($vault->path, $newRoot))->toBeTrue();
    }

    expect(is_dir($newRoot.DIRECTORY_SEPARATOR.'Work'.DIRECTORY_SEPARATOR.'Empty'))->toBeTrue();

    foreach ($fixture['notes'] as $key => $expected) {
        $note = Note::query()->where('uuid', $expected['uuid'])->first();

        expect($note)->not->toBeNull()
            ->and($note->relative_path)->toBe($expected['relative_path'])
            ->and($note->file_hash)->toBe($expected['file_hash'])
            ->and($note->file_size)->toBe($expected['file_size']);

        $vault = $note->vault;
        $absolute = $vault->path.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $note->relative_path);
        expect(File::get($absolute))->toBe($fixture['bytes'][$key]);
    }

    $restoredWork = Vault::query()->where('uuid', $fixture['work']->uuid)->first();
    $attachmentPath = $restoredWork->path.DIRECTORY_SEPARATOR.'assets'.DIRECTORY_SEPARATOR.'a.png';
    expect(File::get($attachmentPath))->toBe($fixture['bytes']['Work/assets/a.png']);

    foreach ([$fixture['work']->uuid, $fixture['personal']->uuid] as $uuid) {
        $vault = Vault::query()->where('uuid', $uuid)->first();
        $result = $this->index->reconcile($vault, IndexMode::Full);
        expect($result->hasChanges())->toBeFalse();
    }

    // A fresh VaultService instance: $this->vaults (built in beforeEach,
    // before the settings table was wiped by simulateFreshInstall) has its
    // own SettingsService with a stale memoized cache.
    $current = app(VaultService::class)->current();
    expect($current?->uuid)->toBeIn([$fixture['work']->uuid, $fixture['personal']->uuid]);
});

// --- HTTP level ------------------------------------------------------------------

test('§55 round trip at the HTTP level', function () {
    $fixture = buildRoundTripFixture();
    $oldRoot = $this->root;
    $workUuid = $fixture['work']->uuid;
    $workNoteUuid = $fixture['workNoteUuid'];

    $this->post(route('settings.backup.store'), [])->assertRedirect();
    $backupPath = Backup::query()->first()->path;

    simulateFreshInstall($oldRoot);

    $inspectResponse = $this->postJson(route('settings.backup.restore.inspect'), ['path' => $backupPath]);
    $inspectResponse->assertOk()->assertJson(['valid' => true]);

    $vaultsPayload = collect($inspectResponse->json('vaults'))
        ->map(fn (array $v): array => ['uuid' => $v['uuid'], 'action' => 'restore'])
        ->all();

    $this->post(route('settings.backup.restore.store'), [
        'path' => $backupPath,
        'vaults' => $vaultsPayload,
    ])->assertRedirect(route('vaults.index'));

    expect(Vault::query()->where('uuid', $workUuid)->exists())->toBeTrue();

    $this->post(route('vaults.open', $workUuid))->assertRedirect();

    $this->get(route('workspace'))->assertOk();

    $response = $this->get(route('notes.show', $workNoteUuid));
    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('note.uuid', $workNoteUuid)
        ->where('note.content', 'Plain overview content.'));
});
