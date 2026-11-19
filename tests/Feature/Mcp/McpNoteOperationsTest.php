<?php

use App\Exceptions\NoteOperationException;
use App\Mcp\Servers\MdVaultServer;
use App\Mcp\Tools\AppendNoteTool;
use App\Mcp\Tools\CreateNoteTool;
use App\Mcp\Tools\DeleteFolderTool;
use App\Mcp\Tools\DeleteNoteTool;
use App\Mcp\Tools\ListNotesTool;
use App\Mcp\Tools\MoveNoteTool;
use App\Mcp\Tools\ReadNoteTool;
use App\Mcp\Tools\UpdateNoteTool;
use App\Services\MarkdownService;
use App\Services\NoteService;
use App\Services\VaultIndexService;
use App\Services\VaultService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Testing\Fluent\AssertableJson;
use Laravel\Mcp\Server\Testing\TestResponse;

beforeEach(function () {
    $this->tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mdvault-mcp-notes-'.Str::random(8);
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
function extractStructuredNotes(TestResponse $response): array
{
    $data = [];
    $response->assertStructuredContent(function (AssertableJson $json) use (&$data): void {
        $data = $json->toArray();
        $json->etc();
    });

    return $data;
}

test('list_notes reconciles external markdown files and handles unprovisioned AI gracefully', function () {
    MdVaultServer::tool(ListNotesTool::class, ['vault' => 'AI'])
        ->assertOk()
        ->assertStructuredContentPath('provisioned', false)
        ->assertStructuredContentPath('note_count', 0);

    $personal = app(VaultService::class)->create('Personal');
    writeVaultFiles($personal->path, [
        'root.md' => "# Root\n",
        'work/sprint.md' => "# Sprint\n",
    ]);

    $res = MdVaultServer::tool(ListNotesTool::class, ['vault' => 'Personal']);
    $res->assertOk()
        ->assertStructuredContentPath('provisioned', true)
        ->assertStructuredContentPath('note_count', 2);

    $scoped = MdVaultServer::tool(ListNotesTool::class, ['vault' => 'Personal', 'folder' => 'work']);
    $scoped->assertOk()
        ->assertStructuredContentPath('folder', 'work')
        ->assertStructuredContentPath('note_count', 1);

    MdVaultServer::tool(ListNotesTool::class, ['vault' => 'Personal', 'folder' => 'missing-folder'])
        ->assertHasErrors()
        ->assertSee('not found');
});

test('read_note returns fresh file_hash and reconciles external edits', function () {
    $personal = app(VaultService::class)->create('Personal');
    writeVaultFiles($personal->path, ['ideas.md' => "---\ntitle: Ideas\n---\n\nOriginal\n"]);
    app(VaultIndexService::class)->reindex($personal);

    $first = extractStructuredNotes(
        MdVaultServer::tool(ReadNoteTool::class, ['vault' => 'Personal', 'path' => 'ideas.md'])->assertOk(),
    );

    $newBytes = "---\ntitle: Ideas\n---\n\nEdited externally\n";
    File::put($personal->path.DIRECTORY_SEPARATOR.'ideas.md', $newBytes);

    $second = extractStructuredNotes(
        MdVaultServer::tool(ReadNoteTool::class, ['vault' => 'Personal', 'uuid' => $first['uuid']])->assertOk(),
    );

    expect($second['file_hash'])->toBe(hash('sha256', $newBytes))
        ->and($second['file_hash'])->not->toBe($first['file_hash'])
        ->and($second['frontmatter_yaml'])->toBe('title: Ideas')
        ->and($second['body'])->toBe("Edited externally\n");
});

test('NoteService::createWithContent validates content and optionally applies template', function () {
    $vault = app(VaultService::class)->create('Personal');
    $notes = app(NoteService::class);

    $withTemplate = $notes->createWithContent($vault, null, 'templated', 'Hello body', applyTemplate: true);
    expect(File::get($notes->absolutePath($withTemplate)))
        ->toStartWith("---\ntitle: \"templated\"")
        ->toEndWith('Hello body');

    $withOwnFrontmatter = $notes->createWithContent($vault, null, 'custom', "---\ntitle: Custom\n---\nBody", applyTemplate: true);
    expect(File::get($notes->absolutePath($withOwnFrontmatter)))
        ->toBe("---\ntitle: Custom\n---\nBody");

    expect(fn () => $notes->createWithContent($vault, null, 'nullbyte', "bad\0text"))
        ->toThrow(NoteOperationException::class);

    expect(fn () => $notes->createWithContent($vault, null, 'badutf8', "\xFF\xFE"))
        ->toThrow(NoteOperationException::class);

    [$encrypted] = encryptedVault('Locked');
    expect(fn () => $notes->createWithContent($encrypted, null, 'nope', 'text'))
        ->toThrow(NoteOperationException::class);
});

test('create_note creates atomically with or without content and refuses collisions', function () {
    $personal = app(VaultService::class)->create('Personal');

    $emptyWithTemplate = extractStructuredNotes(
        MdVaultServer::tool(CreateNoteTool::class, [
            'vault' => 'Personal',
            'path' => 'daily-log',
        ])->assertOk(),
    );

    expect($emptyWithTemplate['relative_path'])->toBe('daily-log.md');
    expect(File::get($personal->path.DIRECTORY_SEPARATOR.'daily-log.md'))->toStartWith("---\n");

    $rawContent = extractStructuredNotes(
        MdVaultServer::tool(CreateNoteTool::class, [
            'vault' => 'Personal',
            'path' => 'raw-note.md',
            'content' => "# Raw Heading\n",
        ])->assertOk(),
    );

    expect(File::get($personal->path.DIRECTORY_SEPARATOR.'raw-note.md'))->toBe("# Raw Heading\n");
    expect($rawContent['file_hash'])->toBe(hash('sha256', "# Raw Heading\n"));

    MdVaultServer::tool(CreateNoteTool::class, [
        'vault' => 'Personal',
        'path' => 'raw-note.md',
        'content' => 'Overwrite attempt',
    ])->assertHasErrors()->assertSee('already exists');
});

test('update_note enforces SHA-256 optimistic locking and preserves CRLF and BOM', function () {
    $personal = app(VaultService::class)->create('Personal');
    $initialRaw = MarkdownService::BOM."# Title\r\nLine 1\r\n";
    writeVaultFiles($personal->path, ['win.md' => $initialRaw]);

    $read = extractStructuredNotes(
        MdVaultServer::tool(ReadNoteTool::class, ['vault' => 'Personal', 'path' => 'win.md'])->assertOk(),
    );

    $updated = extractStructuredNotes(
        MdVaultServer::tool(UpdateNoteTool::class, [
            'vault' => 'Personal',
            'path' => 'win.md',
            'content' => "# Title\nLine 1\nLine 2\n",
            'file_hash' => $read['file_hash'],
        ])->assertOk(),
    );

    $diskBytes = File::get($personal->path.DIRECTORY_SEPARATOR.'win.md');
    expect($diskBytes)->toBe(MarkdownService::BOM."# Title\r\nLine 1\r\nLine 2\r\n");

    // Stale hash returns conflict with current_hash
    MdVaultServer::tool(UpdateNoteTool::class, [
        'vault' => 'Personal',
        'path' => 'win.md',
        'content' => 'Stale overwrite',
        'file_hash' => $read['file_hash'],
    ])->assertHasErrors()->assertSee([$updated['file_hash'], 'changed']);

    // Empty content clears the note (preserving BOM if present)
    $cleared = extractStructuredNotes(
        MdVaultServer::tool(UpdateNoteTool::class, [
            'vault' => 'Personal',
            'path' => 'win.md',
            'content' => '',
            'file_hash' => $updated['file_hash'],
        ])->assertOk(),
    );

    expect(File::get($personal->path.DIRECTORY_SEPARATOR.'win.md'))->toBe(MarkdownService::BOM)
        ->and($cleared['file_hash'])->toBe(hash('sha256', MarkdownService::BOM));
});

test('move_note can move notes back to vault root via empty string and slash', function () {
    $ai = app(VaultService::class)->create('AI');
    writeVaultFiles($ai->path, [
        'nested/first.md' => "# First\n",
        'nested/deep/second.md' => "# Second\n",
    ]);

    $movedEmpty = extractStructuredNotes(
        MdVaultServer::tool(MoveNoteTool::class, [
            'vault' => 'AI',
            'path' => 'nested/first.md',
            'target_folder' => '',
        ])->assertOk(),
    );

    expect($movedEmpty['relative_path'])->toBe('first.md')
        ->and(file_exists($ai->path.DIRECTORY_SEPARATOR.'first.md'))->toBeTrue()
        ->and(file_exists($ai->path.DIRECTORY_SEPARATOR.'nested'.DIRECTORY_SEPARATOR.'first.md'))->toBeFalse();

    $movedSlash = extractStructuredNotes(
        MdVaultServer::tool(MoveNoteTool::class, [
            'vault' => 'AI',
            'path' => 'nested/deep/second.md',
            'target_folder' => '/',
        ])->assertOk(),
    );

    expect($movedSlash['relative_path'])->toBe('second.md')
        ->and(file_exists($ai->path.DIRECTORY_SEPARATOR.'second.md'))->toBeTrue()
        ->and(file_exists($ai->path.DIRECTORY_SEPARATOR.'nested'.DIRECTORY_SEPARATOR.'deep'.DIRECTORY_SEPARATOR.'second.md'))->toBeFalse();
});

test('append_note appends with clean newline separation and validates optional file_hash', function () {
    $personal = app(VaultService::class)->create('Personal');
    writeVaultFiles($personal->path, ['log.md' => '# Log']);

    $first = extractStructuredNotes(
        MdVaultServer::tool(AppendNoteTool::class, [
            'vault' => 'Personal',
            'path' => 'log.md',
            'content' => '- Entry 1',
        ])->assertOk(),
    );

    expect(File::get($personal->path.DIRECTORY_SEPARATOR.'log.md'))->toBe("# Log\n- Entry 1");

    MdVaultServer::tool(AppendNoteTool::class, [
        'vault' => 'Personal',
        'path' => 'log.md',
        'content' => '- Entry 2',
        'file_hash' => $first['file_hash'],
    ])->assertOk();

    expect(File::get($personal->path.DIRECTORY_SEPARATOR.'log.md'))->toBe("# Log\n- Entry 1\n- Entry 2");

    // Stale file_hash fails cleanly
    MdVaultServer::tool(AppendNoteTool::class, [
        'vault' => 'Personal',
        'path' => 'log.md',
        'content' => '- Stale',
        'file_hash' => $first['file_hash'],
    ])->assertHasErrors()->assertSee('changed');
});

test('delete_note uses OS trash when available and AI/.trash fallback when unavailable', function () {
    $ai = app(VaultService::class)->create('AI');
    writeVaultFiles($ai->path, [
        'one.md' => "# One\n",
        'two.md' => "# Two\n",
    ]);

    // CLI fallback -> AI/.trash/
    $res1 = extractStructuredNotes(
        MdVaultServer::tool(DeleteNoteTool::class, ['vault' => 'AI', 'path' => 'one.md'])->assertOk(),
    );

    expect($res1['trashed_to'])->toBe('vault_trash');
    expect(file_exists($ai->path.DIRECTORY_SEPARATOR.'one.md'))->toBeFalse();
    expect(file_exists($ai->path.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $res1['trash_path'])))->toBeTrue();

    // Ensure .trash is dot-ignored by VaultIndexService
    $list = extractStructuredNotes(
        MdVaultServer::tool(ListNotesTool::class, ['vault' => 'AI'])->assertOk(),
    );
    expect($list['folders'])->not->toContain('.trash')
        ->and($list['note_count'])->toBe(1);

    // OS trash available -> os_trash
    $trash = fakeTrash(available: true, deletes: true);
    $res2 = extractStructuredNotes(
        MdVaultServer::tool(DeleteNoteTool::class, ['vault' => 'AI', 'path' => 'two.md'])->assertOk(),
    );

    expect($res2['trashed_to'])->toBe('os_trash')
        ->and($trash->trashed)->toHaveCount(1);
});

test('delete_folder refuses non-empty folders and path traversal inputs are rejected', function () {
    $ai = app(VaultService::class)->create('AI');
    writeVaultFiles($ai->path, ['busy/child.md' => "# Child\n"]);

    MdVaultServer::tool(DeleteFolderTool::class, ['vault' => 'AI', 'path' => 'busy'])
        ->assertHasErrors()
        ->assertSee("isn't empty");

    foreach (['../secret.md', 'sub\\win.md', 'C:\\escape.md', '.git/config.md', 'node_modules/pkg.md', 'a//b.md'] as $badPath) {
        MdVaultServer::tool(CreateNoteTool::class, [
            'vault' => 'AI',
            'path' => $badPath,
            'content' => 'bad',
        ])->assertHasErrors();
    }
});
