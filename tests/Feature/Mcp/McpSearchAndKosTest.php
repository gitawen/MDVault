<?php

use App\Mcp\Prompts\ProjectKickoffPrompt;
use App\Mcp\Prompts\RecordReceiptPrompt;
use App\Mcp\Prompts\SessionBootstrapPrompt;
use App\Mcp\Prompts\SynthesizeNotesPrompt;
use App\Mcp\Resources\AboutMeResource;
use App\Mcp\Resources\NoteResource;
use App\Mcp\Resources\ProjectSkillResource;
use App\Mcp\Resources\SkillResource;
use App\Mcp\Servers\MdVaultServer;
use App\Mcp\Tools\GetKosContextTool;
use App\Mcp\Tools\InitAiProjectTool;
use App\Mcp\Tools\ListSkillsTool;
use App\Mcp\Tools\SaveSkillTool;
use App\Mcp\Tools\SearchNotesTool;
use App\Models\Vault;
use App\Services\VaultIndexService;
use App\Services\VaultService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Testing\Fluent\AssertableJson;
use Laravel\Mcp\Server\Testing\TestResponse;

beforeEach(function () {
    $this->tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mdvault-mcp-kos-'.Str::random(8);
    File::makeDirectory($this->tmp, 0755, true);
    fakeDocumentsDirectory($this->tmp.DIRECTORY_SEPARATOR.'Documents');
});

afterEach(function () {
    File::deleteDirectory($this->tmp);
});

/**
 * @return array<string, mixed>
 */
function extractStructuredKos(TestResponse $response): array
{
    $data = [];
    $response->assertStructuredContent(function (AssertableJson $json) use (&$data): void {
        $data = $json->toArray();
        $json->etc();
    });

    return $data;
}

test('search_notes combines title, path, and content grep with line numbers while skipping encrypted vaults', function () {
    $vaults = app(VaultService::class);
    $ai = $vaults->create('AI');
    $personal = $vaults->create('Personal');
    [$encrypted] = encryptedVault('EncryptedVault');

    writeVaultFiles($ai->path, [
        'alpha/decisions.md' => "# Decisions\nWe chose SQLite WAL and optimistic locking.\nLine 3\n",
    ]);
    writeVaultFiles($personal->path, [
        'SQLite Guide.md' => "# SQLite Guide\nIntro paragraph.\nSQLite is embedded.\n",
        'deep/nested.md' => "# Misc\nNo match here.\n",
    ]);
    // Even if a stray plaintext file sits in an encrypted vault, it must never be searched.
    writeVaultFiles($encrypted->path, [
        'leak.md' => "# Leak\nSQLite secret\n",
    ]);

    $res = MdVaultServer::tool(SearchNotesTool::class, [
        'query' => 'sqlite',
    ]);

    $res->assertOk()
        ->assertStructuredContentPath('total_matched_notes', 2)
        ->assertStructuredContentPath('skipped_encrypted_vault_count', 1);

    $results = extractStructuredKos($res)['results'];

    // Title match ("SQLite Guide") ranks above content-only match ("decisions")
    expect($results[0]['title'])->toBe('SQLite Guide')
        ->and($results[0]['title_match'])->toBeTrue()
        ->and($results[0]['matches'][0]['line'])->toBe(1)
        ->and($results[1]['relative_path'])->toBe('alpha/decisions.md')
        ->and($results[1]['matches'][0]['line'])->toBe(2)
        ->and($results[1]['matches'][0]['text'])->toContain('SQLite WAL');

    // Case-sensitive search filters out lowercase mismatch
    $cs = extractStructuredKos(
        MdVaultServer::tool(SearchNotesTool::class, [
            'query' => 'sqlite',
            'case_sensitive' => true,
        ])->assertOk(),
    );

    expect($cs['total_matched_notes'])->toBe(0);
});

test('init_ai_project scaffolds project workspace idempotently without overwriting edits', function () {
    $first = extractStructuredKos(
        MdVaultServer::tool(InitAiProjectTool::class, [
            'name' => 'mdvault-kos',
            'description' => 'MCP knowledge OS',
            'stack' => 'Laravel 13 + Vue 3',
        ])->assertOk(),
    );

    expect($first['created'])->toEqualCanonicalizing([
        'mdvault-kos/overview.md',
        'mdvault-kos/tasks.md',
        'mdvault-kos/receipts.md',
    ]);
    expect($first['existing'])->toBe([]);

    $aiVault = Vault::query()->where('name', 'AI')->firstOrFail();
    expect(is_dir($aiVault->path.DIRECTORY_SEPARATOR.'mdvault-kos'.DIRECTORY_SEPARATOR.'skills'))->toBeTrue();

    $overviewPath = $aiVault->path.DIRECTORY_SEPARATOR.'mdvault-kos'.DIRECTORY_SEPARATOR.'overview.md';
    File::put($overviewPath, "# Custom Edited Overview\n");

    $second = extractStructuredKos(
        MdVaultServer::tool(InitAiProjectTool::class, [
            'name' => 'mdvault-kos',
        ])->assertOk(),
    );

    expect($second['created'])->toBe([])
        ->and($second['existing'])->toEqualCanonicalizing([
            'mdvault-kos/overview.md',
            'mdvault-kos/tasks.md',
            'mdvault-kos/receipts.md',
        ])
        ->and(File::get($overviewPath))->toBe("# Custom Edited Overview\n");
});

test('get_kos_context and AboutMeResource read, truncate, and handle missing about-me.md cleanly', function () {
    MdVaultServer::tool(GetKosContextTool::class)
        ->assertOk()
        ->assertStructuredContentPath('found', false)
        ->assertStructuredContentPath('vault_provisioned', false);

    MdVaultServer::resource(AboutMeResource::class)
        ->assertOk()
        ->assertSee('Onboarding Instructions');

    $ai = app(VaultService::class)->create('AI');
    $longMemory = str_repeat('A', 2500);
    writeVaultFiles($ai->path, [
        'about-me.md' => $longMemory,
        'project-one/overview.md' => "# One\n",
    ]);

    $ctxRes = MdVaultServer::tool(GetKosContextTool::class)
        ->assertOk()
        ->assertStructuredContentPath('found', true)
        ->assertStructuredContentPath('truncated', true)
        ->assertStructuredContentPath('char_count', 2500)
        ->assertStructuredContentPath('returned_char_count', 2000);

    $ctx = extractStructuredKos($ctxRes);

    expect(mb_strlen($ctx['content']))->toBe(2000)
        ->and($ctx['ai_projects'])->toContain('project-one');

    MdVaultServer::resource(AboutMeResource::class)
        ->assertOk()
        ->assertSee('Truncated to 2000 chars');
});

test('skills tools and resources support AI, project, and personal skills with optimistic locking', function () {
    $personal = app(VaultService::class)->create('Personal');
    writeVaultFiles($personal->path, [
        'plain.md' => "# Plain Note\nBody\n",
    ]);

    // Personal vault without skills/ directory refuses save_skill
    MdVaultServer::tool(SaveSkillTool::class, [
        'vault' => 'Personal',
        'name' => 'personal-skill',
        'content' => "# Personal Skill\n",
    ])->assertHasErrors()->assertSee('skills');

    // Create personal skills/ folder -> save_skill succeeds
    File::makeDirectory($personal->path.DIRECTORY_SEPARATOR.'skills', 0755, true);
    MdVaultServer::tool(SaveSkillTool::class, [
        'vault' => 'Personal',
        'name' => 'personal-skill',
        'content' => "# Personal Skill\nSteps here.\n",
    ])->assertOk()->assertStructuredContentPath('action', 'created');

    // Create AI global skill and project skill
    $aiSkill = extractStructuredKos(
        MdVaultServer::tool(SaveSkillTool::class, [
            'name' => 'deploy-check',
            'content' => str_repeat('x', 4100),
        ])->assertOk(),
    );

    expect($aiSkill['action'])->toBe('created')
        ->and($aiSkill['length_warning'])->not->toBeNull();

    // Overwriting existing skill without file_hash fails with current hash
    MdVaultServer::tool(SaveSkillTool::class, [
        'name' => 'deploy-check',
        'content' => "# Deploy Check\nUpdated\n",
    ])->assertHasErrors()->assertSee($aiSkill['file_hash']);

    // Overwriting with file_hash succeeds
    MdVaultServer::tool(SaveSkillTool::class, [
        'name' => 'deploy-check',
        'content' => "# Deploy Check\nUpdated\n",
        'file_hash' => $aiSkill['file_hash'],
    ])->assertOk()->assertStructuredContentPath('action', 'updated');

    MdVaultServer::tool(SaveSkillTool::class, [
        'name' => 'lint-flow',
        'project' => 'alpha',
        'content' => "# Alpha Lint Flow\nRun pint.\n",
    ])->assertOk()->assertStructuredContentPath('scope', 'project');

    // list_skills discovers all 3 skills
    $listed = extractStructuredKos(
        MdVaultServer::tool(ListSkillsTool::class)->assertOk(),
    );
    expect($listed['count'])->toBe(3);

    $projectSkills = extractStructuredKos(
        MdVaultServer::tool(ListSkillsTool::class, ['project' => 'alpha'])->assertOk(),
    );
    expect($projectSkills['count'])->toBe(1)
        ->and($projectSkills['skills'][0]['resource_uri'])->toBe('mdvault://projects/alpha/skills/lint-flow');

    // Read resources
    MdVaultServer::resource(SkillResource::class, ['vault' => 'AI', 'skill' => 'deploy-check'])
        ->assertOk()
        ->assertSee('Updated');

    MdVaultServer::resource(ProjectSkillResource::class, ['project' => 'alpha', 'skill' => 'lint-flow'])
        ->assertOk()
        ->assertSee('Run pint.');

    app(VaultIndexService::class)->reindex($personal);
    $plainNote = $personal->notes()->where('relative_path', 'plain.md')->firstOrFail();

    MdVaultServer::resource(NoteResource::class, ['vault' => 'Personal', 'uuid' => $plainNote->uuid])
        ->assertOk()
        ->assertSee('# Plain Note');
});

test('all 4 KOS prompts validate required arguments', function () {
    MdVaultServer::prompt(ProjectKickoffPrompt::class, ['project' => 'alpha'])
        ->assertHasErrors();

    MdVaultServer::prompt(SynthesizeNotesPrompt::class, [])
        ->assertHasErrors();

    MdVaultServer::prompt(RecordReceiptPrompt::class, ['project' => 'alpha'])
        ->assertHasErrors();

    MdVaultServer::prompt(SessionBootstrapPrompt::class, [])
        ->assertOk()
        ->assertSee('general session');
});
