<?php

namespace App\Console\Commands;

use App\Services\McpClientConfigService;
use Illuminate\Console\Command;

/**
 * Prints copyable local stdio MCP client configuration snippets for MDVault.
 */
class McpConfigCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mdvault:mcp-config {--client=json : Output format (json, codex, instructions, all)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Print copyable MCP client configuration snippets for the MDVault KOS server';

    public function handle(McpClientConfigService $configs): int
    {
        $client = strtolower((string) $this->option('client'));

        if (! in_array($client, ['json', 'codex', 'instructions', 'all'], true)) {
            $this->error("Invalid --client value [{$client}]. Expected one of: json, codex, instructions, all.");

            return self::FAILURE;
        }

        if ($client === 'instructions') {
            $this->line($this->orchestratorInstructions());

            return self::SUCCESS;
        }

        $data = $configs->clientConfigs();

        if ($client === 'json') {
            $this->line($data['json']);

            return self::SUCCESS;
        }

        if ($client === 'codex') {
            $this->line($data['codex_toml']);

            return self::SUCCESS;
        }

        $this->info('--- Claude Desktop / Cursor / Antigravity (JSON) ---');
        $this->line($data['json']);
        $this->newLine();
        $this->info('--- Codex CLI (TOML) ---');
        $this->line($data['codex_toml']);
        $this->newLine();
        $this->info('--- Direct CLI Command ---');
        $this->line($data['cli_command']);
        $this->newLine();
        $this->comment($data['database_note']);
        $this->newLine();
        $this->info('--- Project & Orchestrator Instructions ---');
        $this->line($this->orchestratorInstructions());

        return self::SUCCESS;
    }

    private function orchestratorInstructions(): string
    {
        return <<<'MARKDOWN'
## MDVault Persistent AI & Orchestrator Memory

### Session Memory (`AGENTS.md` / `GEMINI.md` / `.claude/CLAUDE.md`)
1. **Session Start:** Call `get_kos_context` on `mdvault` at the start of a new conversation to load `AI/about-me.md`, available vaults, and active AI projects.
2. **Saving Memory (`AI` vault):** Save durable user preferences, key decisions, project context, and reusable skills to the `AI` vault (`create_note`, `update_note`, `append_note`, `init_ai_project`, `save_skill`).
3. **Hash Check:** Always call `read_note` to get the current `file_hash` before calling `update_note` or overwriting an existing skill.

### Orchestrator Artifacts (instead of `.ai/features/active/` and `.ai/features/completed/`)
- Store feature artifacts (`requirements.md`, `plan.md`, `implementation.md`, `qa-report.md`) in `AI/<project>/features/active/<feature>/` and ADRs in `AI/<project>/decisions/` via `mdvault` MCP (`create_note`, `read_note`, `update_note`, `move_note`).
- Always call `read_note` for `file_hash` before `update_note`.
- On QA PASS, move feature notes from `AI/<project>/features/active/<feature>/` to `AI/<project>/features/completed/<feature>/` via `move_note`.
MARKDOWN;
    }
}
