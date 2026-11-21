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
use App\Mcp\Tools\AppendNoteTool;
use App\Mcp\Tools\CreateFolderTool;
use App\Mcp\Tools\CreateNoteTool;
use App\Mcp\Tools\DeleteFolderTool;
use App\Mcp\Tools\DeleteNoteTool;
use App\Mcp\Tools\GetKosContextTool;
use App\Mcp\Tools\InitAiProjectTool;
use App\Mcp\Tools\ListNotesTool;
use App\Mcp\Tools\ListSkillsTool;
use App\Mcp\Tools\ListVaultsTool;
use App\Mcp\Tools\MoveNoteTool;
use App\Mcp\Tools\ReadNoteTool;
use App\Mcp\Tools\RenameNoteTool;
use App\Mcp\Tools\SaveSkillTool;
use App\Mcp\Tools\SearchNotesTool;
use App\Mcp\Tools\UpdateNoteTool;
use App\Services\McpClientConfigService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Laravel\Mcp\Server\Transport\FakeTransporter;

beforeEach(function () {
    $this->tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mdvault-mcp-disc-'.Str::random(8);
    File::makeDirectory($this->tmp, 0755, true);
    fakeDocumentsDirectory($this->tmp.DIRECTORY_SEPARATOR.'Documents');
});

afterEach(function () {
    File::deleteDirectory($this->tmp);
});

test('routes/ai.php registers the local mdvault MCP server', function () {
    $aiRoutes = file_get_contents(base_path('routes/ai.php'));

    expect($aiRoutes)
        ->toContain("Mcp::local('mdvault', MdVaultServer::class)");
});

test('MdVaultServer discovers all 16 tools with expected annotations', function () {
    $toolClasses = [
        ListVaultsTool::class,
        ListNotesTool::class,
        SearchNotesTool::class,
        ReadNoteTool::class,
        CreateNoteTool::class,
        UpdateNoteTool::class,
        AppendNoteTool::class,
        RenameNoteTool::class,
        MoveNoteTool::class,
        DeleteNoteTool::class,
        CreateFolderTool::class,
        DeleteFolderTool::class,
        InitAiProjectTool::class,
        GetKosContextTool::class,
        ListSkillsTool::class,
        SaveSkillTool::class,
    ];

    MdVaultServer::tools()->assertRegistered($toolClasses);

    expect(app(ListVaultsTool::class)->toArray()['annotations']['readOnlyHint'])->toBeTrue();
    expect(app(SearchNotesTool::class)->toArray()['annotations']['readOnlyHint'])->toBeTrue();
    expect(app(DeleteNoteTool::class)->toArray()['annotations']['destructiveHint'])->toBeTrue();
    expect(app(DeleteFolderTool::class)->toArray()['annotations']['destructiveHint'])->toBeTrue();
    expect(app(CreateNoteTool::class)->toArray()['annotations']['destructiveHint'])->toBeFalse();
    expect(app(UpdateNoteTool::class)->toArray()['annotations']['idempotentHint'])->toBeTrue();
});

test('MdVaultServer discovers all 4 resources and 4 prompts', function () {
    MdVaultServer::resources()->assertRegistered([
        AboutMeResource::class,
    ]);

    // Templated resources are exposed via resources/templates/list and uriTemplate().
    $server = app()->makeWith(MdVaultServer::class, ['transport' => new FakeTransporter]);
    $server->start();
    $templates = (fn () => $this->createContext()->resourceTemplates())->call($server);
    $templateUris = $templates->map(fn ($r) => (string) $r->uriTemplate())->values()->all();

    expect($templateUris)->toEqualCanonicalizing([
        'mdvault://skills/{vault}/{skill}',
        'mdvault://projects/{project}/skills/{skill}',
        'mdvault://notes/{vault}/{uuid}',
    ]);

    expect(app(AboutMeResource::class)->uri())->toBe('mdvault://memory/about-me');
    expect((string) app(SkillResource::class)->uriTemplate())->toBe('mdvault://skills/{vault}/{skill}');
    expect((string) app(ProjectSkillResource::class)->uriTemplate())->toBe('mdvault://projects/{project}/skills/{skill}');
    expect((string) app(NoteResource::class)->uriTemplate())->toBe('mdvault://notes/{vault}/{uuid}');

    MdVaultServer::prompts()->assertRegistered([
        SessionBootstrapPrompt::class,
        ProjectKickoffPrompt::class,
        SynthesizeNotesPrompt::class,
        RecordReceiptPrompt::class,
    ]);

    MdVaultServer::prompt(SessionBootstrapPrompt::class, ['focus' => 'architecture'])
        ->assertOk()
        ->assertSee(['get_kos_context', 'list_skills']);

    MdVaultServer::prompt(ProjectKickoffPrompt::class, ['project' => 'alpha', 'goal' => 'Ship v1', 'stack' => 'Laravel'])
        ->assertOk()
        ->assertSee(['init_ai_project', 'AI/alpha/overview.md']);

    MdVaultServer::prompt(SynthesizeNotesPrompt::class, ['topic' => 'MCP KOS'])
        ->assertOk()
        ->assertSee(['search_notes', 'read_note']);

    MdVaultServer::prompt(RecordReceiptPrompt::class, ['project' => 'alpha', 'summary' => 'Added tests'])
        ->assertOk()
        ->assertSee(['append_note', 'AI/alpha/receipts.md']);
});

test('mdvault:mcp-config outputs valid JSON and Codex TOML client configurations', function () {
    $exitJson = Artisan::call('mdvault:mcp-config', ['--client' => 'json']);
    $jsonOut = Artisan::output();
    $decoded = json_decode($jsonOut, true, 512, JSON_THROW_ON_ERROR);

    expect($exitJson)->toBe(0)
        ->and($decoded['mcpServers']['mdvault']['args'])->toContain('mcp:start', 'mdvault');

    $exitCodex = Artisan::call('mdvault:mcp-config', ['--client' => 'codex']);
    $codexOut = Artisan::output();

    expect($exitCodex)->toBe(0)
        ->and($codexOut)->toContain('[mcp_servers.mdvault]', 'mcp:start');

    $exitInstructions = Artisan::call('mdvault:mcp-config', ['--client' => 'instructions']);
    $instructionsOut = Artisan::output();

    expect($exitInstructions)->toBe(0)
        ->and($instructionsOut)->toContain(
            'get_kos_context',
            'AI/<project>/features/active/',
            'AI/<project>/features/completed/',
        );

    $exitAll = Artisan::call('mdvault:mcp-config', ['--client' => 'all']);
    $allOut = Artisan::output();

    expect($exitAll)->toBe(0)
        ->and($allOut)->toContain(
            '[mcp_servers.mdvault]',
            '"mcpServers"',
            '--- Project & Orchestrator Instructions ---',
            'get_kos_context',
            'AI/<project>/features/active/',
            'AI/<project>/features/completed/',
        );

    $this->artisan('mdvault:mcp-config', ['--client' => 'invalid'])
        ->assertFailed();
});

test('McpClientConfigService includes DB_DATABASE when SQLite file differs from default mdvault.sqlite', function () {
    $customDb = $this->tmp.DIRECTORY_SEPARATOR.'custom-desktop.sqlite';
    File::put($customDb, '');

    $originalDefault = config('database.default');
    config()->set('database.connections.mcp_custom_sqlite', [
        'driver' => 'sqlite',
        'database' => $customDb,
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    config()->set('database.default', 'mcp_custom_sqlite');

    try {
        $config = app(McpClientConfigService::class)->clientConfigs();
        $decoded = json_decode($config['json'], true, 512, JSON_THROW_ON_ERROR);

        expect($config['uses_custom_database'])->toBeTrue()
            ->and($config['env'])->toBe(['DB_DATABASE' => $customDb])
            ->and($decoded['mcpServers']['mdvault']['args'])->toContain('mcp:start', 'mdvault')
            ->and($decoded['mcpServers']['mdvault']['env']['DB_DATABASE'])->toBe($customDb)
            ->and($config['codex_toml'])->toContain('[mcp_servers.mdvault.env]', 'DB_DATABASE =');
    } finally {
        config()->set('database.default', $originalDefault);
        app('db')->purge('mcp_custom_sqlite');
    }
});
