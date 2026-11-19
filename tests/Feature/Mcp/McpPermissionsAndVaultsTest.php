<?php

use App\Enums\VaultStatus;
use App\Mcp\Resources\NoteResource;
use App\Mcp\Resources\SkillResource;
use App\Mcp\Servers\MdVaultServer;
use App\Mcp\Tools\AppendNoteTool;
use App\Mcp\Tools\CreateFolderTool;
use App\Mcp\Tools\CreateNoteTool;
use App\Mcp\Tools\DeleteFolderTool;
use App\Mcp\Tools\DeleteNoteTool;
use App\Mcp\Tools\ListNotesTool;
use App\Mcp\Tools\ListSkillsTool;
use App\Mcp\Tools\ListVaultsTool;
use App\Mcp\Tools\MoveNoteTool;
use App\Mcp\Tools\ReadNoteTool;
use App\Mcp\Tools\RenameNoteTool;
use App\Mcp\Tools\SaveSkillTool;
use App\Mcp\Tools\SearchNotesTool;
use App\Mcp\Tools\UpdateNoteTool;
use App\Models\Note;
use App\Models\Vault;
use App\Services\VaultIndexService;
use App\Services\VaultService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Testing\Fluent\AssertableJson;
use Laravel\Mcp\Server\Testing\TestResponse;

beforeEach(function () {
    $this->tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mdvault-mcp-perm-'.Str::random(8);
    File::makeDirectory($this->tmp, 0755, true);
    fakeDocumentsDirectory($this->tmp.DIRECTORY_SEPARATOR.'Documents');
    fakeTrash(available: false);
});

afterEach(function () {
    File::deleteDirectory($this->tmp);
});

/**
 * @return array<string, mixed>
 */
function extractStructuredPerm(TestResponse $response): array
{
    $data = [];
    $response->assertStructuredContent(function (AssertableJson $json) use (&$data): void {
        $data = $json->toArray();
        $json->etc();
    });

    return $data;
}

test('list_vaults classifies AI, Personal, Encrypted, and Missing vaults without querying excluded note counts', function () {
    $vaults = app(VaultService::class);
    $ai = $vaults->create('AI', 'Agent workspace');
    $personal = $vaults->create('Personal', 'User notes');
    [$encrypted] = encryptedVault('Secrets');
    $missing = $vaults->create('MissingVault');

    writeVaultFiles($ai->path, ['memory.md' => "# Memory\n"]);
    writeVaultFiles($personal->path, ['journal.md' => "# Journal\n", 'todo.md' => "# Todo\n"]);
    app(VaultIndexService::class)->reindex($ai);
    app(VaultIndexService::class)->reindex($personal);

    File::deleteDirectory($missing->path);

    $response = MdVaultServer::tool(ListVaultsTool::class);
    $response->assertOk();

    $structured = extractStructuredPerm($response);
    $byName = collect($structured['vaults'])->keyBy('name');

    expect($byName['AI'])->toMatchArray([
        'accessible' => true,
        'tier' => 'ai_workspace',
        'permission' => 'full_crud',
        'note_count' => 1,
        'exclusion_reason' => null,
    ]);

    expect($byName['Personal'])->toMatchArray([
        'accessible' => true,
        'tier' => 'personal',
        'permission' => 'read_safe_write',
        'note_count' => 2,
        'exclusion_reason' => null,
    ]);

    expect($byName['Secrets'])->toMatchArray([
        'accessible' => false,
        'is_encrypted' => true,
        'tier' => 'excluded',
        'permission' => 'none',
        'note_count' => null,
        'exclusion_reason' => 'encrypted',
    ]);

    expect($byName['MissingVault'])->toMatchArray([
        'accessible' => false,
        'status' => VaultStatus::Missing->value,
        'tier' => 'excluded',
        'permission' => 'none',
        'note_count' => null,
        'exclusion_reason' => 'missing',
    ]);

    expect($structured['ai_workspace']['provisioned'])->toBeTrue()
        ->and($structured['ai_workspace']['accessible'])->toBeTrue();
});

test('encrypted vaults are strictly rejected across all MCP tools and resources', function () {
    [$encrypted] = encryptedVault('VaultSecret');

    $note = $encrypted->notes()->create([
        'title' => 'Secret',
        'filename' => str_repeat('a', 32).'.mdenc',
        'relative_path' => str_repeat('a', 32).'.mdenc',
        'extension' => 'mdenc',
        'mime_type' => Note::ENCRYPTED_MIME_TYPE,
        'file_size' => 64,
        'file_hash' => str_repeat('0', 64),
        'is_encrypted' => true,
    ]);

    MdVaultServer::tool(ListNotesTool::class, ['vault' => 'VaultSecret'])->assertHasErrors()->assertSee('encrypted');
    MdVaultServer::tool(SearchNotesTool::class, ['query' => 'anything', 'vault' => 'VaultSecret'])->assertHasErrors()->assertSee('encrypted');
    MdVaultServer::tool(ReadNoteTool::class, ['vault' => 'VaultSecret', 'uuid' => $note->uuid])->assertHasErrors()->assertSee('encrypted');
    MdVaultServer::tool(CreateNoteTool::class, ['vault' => 'VaultSecret', 'path' => 'new.md', 'content' => 'hi'])->assertHasErrors()->assertSee('encrypted');
    MdVaultServer::tool(UpdateNoteTool::class, ['vault' => 'VaultSecret', 'uuid' => $note->uuid, 'content' => 'hi', 'file_hash' => str_repeat('a', 64)])->assertHasErrors()->assertSee('encrypted');
    MdVaultServer::tool(AppendNoteTool::class, ['vault' => 'VaultSecret', 'uuid' => $note->uuid, 'content' => 'hi'])->assertHasErrors()->assertSee('encrypted');
    MdVaultServer::tool(RenameNoteTool::class, ['vault' => 'VaultSecret', 'uuid' => $note->uuid, 'new_name' => 'other'])->assertHasErrors()->assertSee('encrypted');
    MdVaultServer::tool(MoveNoteTool::class, ['vault' => 'VaultSecret', 'uuid' => $note->uuid, 'target_folder' => 'sub'])->assertHasErrors()->assertSee('encrypted');
    MdVaultServer::tool(DeleteNoteTool::class, ['vault' => 'VaultSecret', 'uuid' => $note->uuid])->assertHasErrors()->assertSee('encrypted');
    MdVaultServer::tool(CreateFolderTool::class, ['vault' => 'VaultSecret', 'path' => 'sub'])->assertHasErrors()->assertSee('encrypted');
    MdVaultServer::tool(DeleteFolderTool::class, ['vault' => 'VaultSecret', 'path' => 'sub'])->assertHasErrors()->assertSee('encrypted');
    MdVaultServer::tool(ListSkillsTool::class, ['vault' => 'VaultSecret'])->assertHasErrors()->assertSee('encrypted');
    MdVaultServer::tool(SaveSkillTool::class, ['vault' => 'VaultSecret', 'name' => 'skill', 'content' => '# Skill'])->assertHasErrors()->assertSee('encrypted');

    MdVaultServer::resource(NoteResource::class, ['vault' => 'VaultSecret', 'uuid' => $note->uuid])
        ->assertHasErrors()
        ->assertSee('encrypted');

    MdVaultServer::resource(SkillResource::class, ['vault' => 'VaultSecret', 'skill' => 'demo'])
        ->assertHasErrors()
        ->assertSee('encrypted');
});

test('AI vault auto-provisions on first write and fails cleanly when AI vault is encrypted', function () {
    $res = MdVaultServer::tool(CreateNoteTool::class, [
        'vault' => 'AI',
        'path' => 'scratch.md',
        'content' => "# Scratch\n",
    ]);

    $res->assertOk()
        ->assertStructuredContentPath('vault', 'AI')
        ->assertStructuredContentPath('relative_path', 'scratch.md');

    $aiVault = Vault::query()->where('name', 'AI')->firstOrFail();

    // Remove the AI vault + its disk folder, then create an encrypted AI vault.
    File::deleteDirectory($aiVault->path);
    $aiVault->notes()->delete();
    $aiVault->delete();
    encryptedVault('AI');

    MdVaultServer::tool(CreateNoteTool::class, [
        'vault' => 'AI',
        'path' => 'blocked.md',
        'content' => 'no',
    ])->assertHasErrors()->assertSee('encrypted');
});

test('structural mutations succeed in AI vault and are refused on Personal vaults', function () {
    $vaults = app(VaultService::class);
    $personal = $vaults->create('Personal');
    writeVaultFiles($personal->path, [
        'keep.md' => "# Keep\n",
        'empty-dir/' => null,
    ]);

    // Personal vault refuses rename, move, delete, create_folder, delete_folder
    MdVaultServer::tool(RenameNoteTool::class, ['vault' => 'Personal', 'path' => 'keep.md', 'new_name' => 'moved'])->assertHasErrors()->assertSee('only permitted in the dedicated AI vault');
    MdVaultServer::tool(MoveNoteTool::class, ['vault' => 'Personal', 'path' => 'keep.md', 'target_folder' => 'empty-dir'])->assertHasErrors()->assertSee('only permitted in the dedicated AI vault');
    MdVaultServer::tool(DeleteNoteTool::class, ['vault' => 'Personal', 'path' => 'keep.md'])->assertHasErrors()->assertSee('only permitted in the dedicated AI vault');
    MdVaultServer::tool(CreateFolderTool::class, ['vault' => 'Personal', 'path' => 'new-folder'])->assertHasErrors()->assertSee('only permitted in the dedicated AI vault');
    MdVaultServer::tool(DeleteFolderTool::class, ['vault' => 'Personal', 'path' => 'empty-dir'])->assertHasErrors()->assertSee('only permitted in the dedicated AI vault');

    expect(file_exists($personal->path.DIRECTORY_SEPARATOR.'keep.md'))->toBeTrue();
    expect(is_dir($personal->path.DIRECTORY_SEPARATOR.'empty-dir'))->toBeTrue();

    // AI vault permits all structural mutations
    MdVaultServer::tool(CreateFolderTool::class, ['vault' => 'AI', 'path' => 'archive/2026'])
        ->assertOk()
        ->assertStructuredContentPath('path', 'archive/2026');

    MdVaultServer::tool(CreateNoteTool::class, ['vault' => 'AI', 'path' => 'draft.md', 'content' => "# Draft\n"])
        ->assertOk();

    MdVaultServer::tool(RenameNoteTool::class, ['path' => 'draft.md', 'new_name' => 'final'])
        ->assertOk()
        ->assertStructuredContentPath('relative_path', 'final.md');

    MdVaultServer::tool(MoveNoteTool::class, ['path' => 'final.md', 'target_folder' => 'archive/2026'])
        ->assertOk()
        ->assertStructuredContentPath('relative_path', 'archive/2026/final.md');

    MdVaultServer::tool(DeleteNoteTool::class, ['path' => 'archive/2026/final.md'])
        ->assertOk()
        ->assertStructuredContentPath('deleted', true)
        ->assertStructuredContentPath('trashed_to', 'vault_trash');

    MdVaultServer::tool(DeleteFolderTool::class, ['path' => 'archive/2026'])
        ->assertOk()
        ->assertStructuredContentPath('deleted', true);
});
