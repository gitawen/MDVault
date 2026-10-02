<?php

use App\Enums\IndexMode;
use App\Enums\SettingKey;
use App\Models\Note;
use App\Services\ExternalChangeService;
use App\Services\SettingsService;
use App\Services\VaultIndexService;
use App\Services\VaultService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

test('a guest can open the workspace with system status', function () {
    $response = $this->get(route('workspace'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Workspace')
        ->where('status.application', config('app.name'))
        ->where('status.runtime', 'browser')
        ->where('status.database.driver', 'sqlite')
        ->where('status.database.connected', true)
        ->has('status.version')
        ->where('editor.font_size', 16)
        ->where('editor.word_wrap', true)
        ->where('currentVault', null)
        ->where('tree', null)
        ->where('note', null)
        ->where('folders', [])
    );
});

test('the current vault persists across a restart', function () {
    $tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mdvault-workspace-'.Str::random(8);

    try {
        fakeDocumentsDirectory($tmp.DIRECTORY_SEPARATOR.'Documents');

        $vault = app(VaultService::class)->create('Work');
        app(VaultService::class)->open($vault);

        app()->forgetScopedInstances();

        $response = $this->get(route('workspace'));

        $response->assertInertia(fn ($page) => $page
            ->where('currentVault.uuid', $vault->uuid)
            ->where('currentVault.name', $vault->name)
            ->has('tree'));
    } finally {
        File::deleteDirectory($tmp);
    }
});

test('a missing current vault folder gives a null tree', function () {
    $tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mdvault-workspace-'.Str::random(8);

    try {
        fakeDocumentsDirectory($tmp.DIRECTORY_SEPARATOR.'Documents');

        $vault = app(VaultService::class)->create('Work');
        app(VaultService::class)->open($vault);
        File::deleteDirectory($vault->path);

        $response = $this->get(route('workspace'));

        $response->assertInertia(fn ($page) => $page->where('tree', null));
    } finally {
        File::deleteDirectory($tmp);
    }
});

test('the treeSignature prop matches the external-change check for an unchanged vault', function () {
    $tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mdvault-workspace-'.Str::random(8);

    try {
        fakeDocumentsDirectory($tmp.DIRECTORY_SEPARATOR.'Documents');

        $vault = app(VaultService::class)->create('Work');
        app(VaultService::class)->open($vault);
        writeVaultFiles($vault->path, ['a.md' => 'one']);
        app(VaultIndexService::class)->reconcile($vault, IndexMode::Full);

        $check = app(ExternalChangeService::class)->check($vault, null);

        $response = $this->get(route('workspace'));

        $response->assertInertia(fn ($page) => $page->where('treeSignature', $check['tree_signature']));
    } finally {
        File::deleteDirectory($tmp);
    }
});

test('a partial tree reload of a deleted open note renders the workspace instead of 404ing (AR-01)', function () {
    $tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mdvault-workspace-'.Str::random(8);

    try {
        fakeDocumentsDirectory($tmp.DIRECTORY_SEPARATOR.'Documents');

        $vault = app(VaultService::class)->create('Work');
        app(VaultService::class)->open($vault);
        writeVaultFiles($vault->path, ['a.md' => 'one']);
        app(VaultIndexService::class)->reconcile($vault, IndexMode::Full);
        $note = Note::query()->sole();

        File::delete($vault->path.DIRECTORY_SEPARATOR.'a.md');
        // As the real flow does: the "changes" check reconciles the
        // deletion (removing the row) and returns the new tree signature
        // before the renderer's tree-only partial reload of notes.show for
        // the same, now-missing, uuid.
        $check = app(ExternalChangeService::class)->check($vault, $note->uuid);

        $response = $this->get(route('notes.show', $note->uuid), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json')),
            'X-Inertia-Partial-Component' => 'Workspace',
            'X-Inertia-Partial-Data' => 'tree,folders,treeSignature',
        ]);

        $response->assertOk();
        expect($response->json('component'))->toBe('Workspace');
        expect($response->json('props.treeSignature'))->toBe($check['tree_signature']);
        expect(collect($response->json('props.tree'))->pluck('uuid'))->not->toContain($note->uuid);
    } finally {
        File::deleteDirectory($tmp);
    }
});

test('a partial note reload of a deleted open note returns note as null instead of 404ing (AR-01)', function () {
    $tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mdvault-workspace-'.Str::random(8);

    try {
        fakeDocumentsDirectory($tmp.DIRECTORY_SEPARATOR.'Documents');

        $vault = app(VaultService::class)->create('Work');
        app(VaultService::class)->open($vault);
        writeVaultFiles($vault->path, ['a.md' => 'one']);
        app(VaultIndexService::class)->reconcile($vault, IndexMode::Full);
        $note = Note::query()->sole();

        File::delete($vault->path.DIRECTORY_SEPARATOR.'a.md');
        app(ExternalChangeService::class)->check($vault, $note->uuid);

        $response = $this->get(route('notes.show', $note->uuid), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json')),
            'X-Inertia-Partial-Component' => 'Workspace',
            'X-Inertia-Partial-Data' => 'note',
        ]);

        $response->assertOk();
        expect($response->json('component'))->toBe('Workspace');
        expect($response->json('props.note'))->toBeNull();
    } finally {
        File::deleteDirectory($tmp);
    }
});

test('a full (non-partial) GET to a deleted uuid still 404s (AR-01)', function () {
    $tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mdvault-workspace-'.Str::random(8);

    try {
        fakeDocumentsDirectory($tmp.DIRECTORY_SEPARATOR.'Documents');

        $vault = app(VaultService::class)->create('Work');
        app(VaultService::class)->open($vault);
        writeVaultFiles($vault->path, ['a.md' => 'one']);
        app(VaultIndexService::class)->reconcile($vault, IndexMode::Full);
        $note = Note::query()->sole();

        File::delete($vault->path.DIRECTORY_SEPARATOR.'a.md');
        app(ExternalChangeService::class)->check($vault, $note->uuid);

        $this->get(route('notes.show', $note->uuid))->assertNotFound();
    } finally {
        File::deleteDirectory($tmp);
    }
});

test('checkExternalChanges is true by default', function () {
    $response = $this->get(route('workspace'));

    $response->assertInertia(fn ($page) => $page->where('checkExternalChanges', true));
});

test('the workspace editor prop reflects a stored preference', function () {
    app(SettingsService::class)->set(SettingKey::EditorFontSize, 20);

    $response = $this->get(route('workspace'));

    $response->assertInertia(fn ($page) => $page->where('editor.font_size', 20));
});

test('removed auth and account routes are gone', function (string $uri) {
    $this->get($uri)->assertNotFound();
})->with([
    '/login',
    '/register',
    '/forgot-password',
    '/two-factor-challenge',
    '/user/confirm-password',
    '/dashboard',
    '/settings/profile',
    '/settings/security',
    '/.well-known/passkey-endpoints',
]);

test('the workspace provides the sidebar tree data for an active vault with an open note', function () {
    $tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mdvault-workspace-'.Str::random(8);

    try {
        fakeDocumentsDirectory($tmp.DIRECTORY_SEPARATOR.'Documents');

        $vault = app(VaultService::class)->create('Work');
        app(VaultService::class)->open($vault);
        writeVaultFiles($vault->path, ['Projects/a.md' => 'one']);
        app(VaultIndexService::class)->reconcile($vault, IndexMode::Full);
        $note = Note::query()->sole();

        $response = $this->get(route('notes.show', $note->uuid));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Workspace')
            ->where('currentVault.uuid', $vault->uuid)
            ->has('tree')
            ->has('folders')
            ->has('canTrash')
            ->where('note.uuid', $note->uuid)
            ->where('tree.0.type', 'folder')
            ->where('tree.0.name', 'Projects')
            ->where('tree.0.open', true));
    } finally {
        File::deleteDirectory($tmp);
    }
});

test('non-workspace pages do not build the note tree', function (string $routeName) {
    $tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mdvault-workspace-'.Str::random(8);

    try {
        fakeDocumentsDirectory($tmp.DIRECTORY_SEPARATOR.'Documents');

        $vault = app(VaultService::class)->create('Work');
        app(VaultService::class)->open($vault);
        writeVaultFiles($vault->path, ['Projects/a.md' => 'one']);
        app(VaultIndexService::class)->reconcile($vault, IndexMode::Full);

        $response = $this->get(route($routeName));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->missing('tree')
            ->missing('folders')
            ->missing('treeSignature')
            ->where('vaults.0.is_current', true));
    } finally {
        File::deleteDirectory($tmp);
    }
})->with([
    'settings' => 'settings.general.edit',
    'vaults' => 'vaults.index',
]);
