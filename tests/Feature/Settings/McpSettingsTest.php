<?php

use App\Models\Vault;
use App\Services\McpKosService;
use App\Services\VaultIndexService;
use App\Services\VaultService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mdvault-mcp-settings-'.Str::random(8);
    File::makeDirectory($this->tmp, 0755, true);
    fakeDocumentsDirectory($this->tmp.DIRECTORY_SEPARATOR.'Documents');
});

afterEach(function () {
    File::deleteDirectory($this->tmp);
});

test('the AI & MCP settings page renders config snippets and workspace summary', function () {
    $vaults = app(VaultService::class);
    $personal = $vaults->create('Personal');
    writeVaultFiles($personal->path, ['note.md' => "# Note\n"]);
    app(VaultIndexService::class)->reindex($personal);
    [$encrypted] = encryptedVault('Private');

    $response = $this->get(route('settings.mcp.edit'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('settings/Mcp')
        ->where('config.server_name', 'mdvault')
        ->has('config.json')
        ->has('config.codex_toml')
        ->has('config.cli_command')
        ->where('workspace.ai_vault.provisioned', false)
        ->where('workspace.about_me.exists', false)
        ->has('workspace.vaults', 2)
        ->has('workspace.primitives.tools', 16)
        ->has('workspace.primitives.resources', 4)
        ->has('workspace.primitives.prompts', 4));
});

test('posting to settings.mcp.provision creates AI vault and starter about-me.md idempotently', function () {
    $this->post(route('settings.mcp.provision'))
        ->assertRedirect();

    $aiVault = Vault::query()->where('name', McpKosService::AI_VAULT_NAME)->first();

    expect($aiVault)->not->toBeNull();
    $aboutMePath = $aiVault->path.DIRECTORY_SEPARATOR.'about-me.md';
    expect(file_exists($aboutMePath))->toBeTrue();
    expect(is_dir($aiVault->path.DIRECTORY_SEPARATOR.'skills'))->toBeTrue();

    // Edit about-me.md and re-provision: existing edits must be preserved
    File::put($aboutMePath, "# Custom Memory\nKeep me intact.\n");

    $this->post(route('settings.mcp.provision'))
        ->assertRedirect();

    expect(File::get($aboutMePath))->toBe("# Custom Memory\nKeep me intact.\n");
});

test('posting to settings.mcp.provision flashes an error when AI vault is encrypted', function () {
    encryptedVault('AI');

    $response = $this->from(route('settings.mcp.edit'))
        ->post(route('settings.mcp.provision'));

    $response->assertRedirect(route('settings.mcp.edit'));
    $response->assertInertiaFlash('toast.type', 'error');
});
