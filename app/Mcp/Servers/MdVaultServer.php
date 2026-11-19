<?php

namespace App\Mcp\Servers;

use App\Mcp\Prompts\ProjectKickoffPrompt;
use App\Mcp\Prompts\RecordReceiptPrompt;
use App\Mcp\Prompts\SessionBootstrapPrompt;
use App\Mcp\Prompts\SynthesizeNotesPrompt;
use App\Mcp\Resources\AboutMeResource;
use App\Mcp\Resources\NoteResource;
use App\Mcp\Resources\ProjectSkillResource;
use App\Mcp\Resources\SkillResource;
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
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('MDVault KOS')]
#[Version('1.0.0')]
#[Instructions(<<<'MARKDOWN'
MDVault Knowledge Operating System (KOS) MCP Server.

Core workflow & rules:
1. **Session bootstrap**: Call `get_kos_context` (or read `mdvault://memory/about-me`) at the start of a session to load durable user memory (`AI/about-me.md`), vault inventory, and active AI projects.
2. **Two-tier permissions**:
   - **AI vault (`AI`)**: Dedicated agent workspace with full CRUD (`create_note`, `update_note`, `append_note`, `rename_note`, `move_note`, `delete_note`, `create_folder`, `delete_folder`, `init_ai_project`, `save_skill`). Auto-provisions on first AI write.
   - **Personal vaults**: Read + safe note writes only (`list_notes`, `search_notes`, `read_note`, `create_note`, `update_note`, `append_note`). Structural mutations (rename, move, delete, folder creation/deletion) are strictly refused on personal vaults.
3. **Encrypted vaults**: Encrypted vaults are strictly excluded from all MCP reads, writes, searches, and resources.
4. **Optimistic locking**: Always call `read_note` to obtain the live SHA-256 `file_hash` before calling `update_note` or overwriting an existing skill via `save_skill`.
5. **Project scaffolding & skills**: Use `init_ai_project` to create `AI/<project>/` (`overview.md`, `tasks.md`, `receipts.md`, `skills/`), and `list_skills` / `save_skill` to discover and maintain reusable Markdown skills.
MARKDOWN)]
class MdVaultServer extends Server
{
    public int $defaultPaginationLength = 50;

    public int $maxPaginationLength = 100;

    protected array $tools = [
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

    protected array $resources = [
        AboutMeResource::class,
        SkillResource::class,
        ProjectSkillResource::class,
        NoteResource::class,
    ];

    protected array $prompts = [
        SessionBootstrapPrompt::class,
        ProjectKickoffPrompt::class,
        SynthesizeNotesPrompt::class,
        RecordReceiptPrompt::class,
    ];

    protected function boot(): void
    {
        try {
            $connection = DB::connection();

            if ($connection->getDriverName() === 'sqlite') {
                $connection->statement('PRAGMA busy_timeout = 5000;');
            }
        } catch (\Throwable) {
            // Keep server boot resilient across :memory: or deferred connections.
        }
    }
}
