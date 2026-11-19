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
    protected $signature = 'mdvault:mcp-config {--client=json : Output format (json, codex, all)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Print copyable MCP client configuration snippets for the MDVault KOS server';

    public function handle(McpClientConfigService $configs): int
    {
        $client = strtolower((string) $this->option('client'));

        if (! in_array($client, ['json', 'codex', 'all'], true)) {
            $this->error("Invalid --client value [{$client}]. Expected one of: json, codex, all.");

            return self::FAILURE;
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

        return self::SUCCESS;
    }
}
