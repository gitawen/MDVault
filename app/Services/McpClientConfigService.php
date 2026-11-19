<?php

namespace App\Services;

use Illuminate\Database\DatabaseManager;

/**
 * Generates copyable local stdio MCP client configurations for Claude Desktop,
 * Cursor, Antigravity, Codex CLI, and terminal usage.
 */
final class McpClientConfigService
{
    public const SERVER_NAME = 'mdvault';

    public function __construct(
        private readonly DatabaseManager $database,
    ) {}

    /**
     * Builds copyable MCP client config snippets and database targeting metadata.
     *
     * @return array{
     *     server_name: string,
     *     command: string,
     *     args: list<string>,
     *     env: array<string, string>,
     *     database_path: string,
     *     uses_custom_database: bool,
     *     database_note: string,
     *     json: string,
     *     codex_toml: string,
     *     cli_command: string
     * }
     */
    public function clientConfigs(): array
    {
        $phpBinary = PHP_BINARY;
        $artisanPath = base_path('artisan');
        $args = [$artisanPath, 'mcp:start', self::SERVER_NAME];

        $connection = $this->database->connection();
        $driver = $connection->getDriverName();
        $activeDb = (string) ($connection->getConfig('database') ?? '');
        $defaultDb = database_path('mdvault.sqlite');

        $usesCustomDb = $driver === 'sqlite'
            && $activeDb !== ''
            && $activeDb !== ':memory:'
            && ! $this->sameNormalizedPath($activeDb, $defaultDb);

        $env = $usesCustomDb ? ['DB_DATABASE' => $activeDb] : [];

        $serverEntry = [
            'command' => $phpBinary,
            'args' => $args,
        ];

        if ($env !== []) {
            $serverEntry['env'] = $env;
        }

        $jsonPayload = [
            'mcpServers' => [
                self::SERVER_NAME => $serverEntry,
            ],
        ];

        $json = (string) json_encode($jsonPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $toml = $this->buildCodexToml($phpBinary, $args, $env);
        $cliCommand = $this->buildCliCommand($phpBinary, $args, $env);

        $databaseNote = $usesCustomDb
            ? "Includes DB_DATABASE ({$activeDb}) so external MCP clients share the exact SQLite registry used by this runtime."
            : ($activeDb === ':memory:'
                ? 'Running with an in-memory SQLite database; production CLI connections will target database/mdvault.sqlite unless DB_DATABASE is set.'
                : "Targeting default SQLite registry ({$activeDb}).");

        return [
            'server_name' => self::SERVER_NAME,
            'command' => $phpBinary,
            'args' => $args,
            'env' => $env,
            'database_path' => $activeDb,
            'uses_custom_database' => $usesCustomDb,
            'database_note' => $databaseNote,
            'json' => $json,
            'codex_toml' => $toml,
            'cli_command' => $cliCommand,
        ];
    }

    /**
     * @param  list<string>  $args
     * @param  array<string, string>  $env
     */
    private function buildCodexToml(string $command, array $args, array $env): string
    {
        $escapedArgs = array_map(
            fn (string $arg): string => '"'.$this->escapeTomlString($arg).'"',
            $args,
        );

        $lines = [
            '[mcp_servers.'.self::SERVER_NAME.']',
            'command = "'.$this->escapeTomlString($command).'"',
            'args = ['.implode(', ', $escapedArgs).']',
        ];

        if ($env !== []) {
            $lines[] = '';
            $lines[] = '[mcp_servers.'.self::SERVER_NAME.'.env]';

            foreach ($env as $key => $value) {
                $lines[] = $key.' = "'.$this->escapeTomlString($value).'"';
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param  list<string>  $args
     * @param  array<string, string>  $env
     */
    private function buildCliCommand(string $command, array $args, array $env): string
    {
        $parts = array_map(
            fn (string $part): string => str_contains($part, ' ') ? '"'.$part.'"' : $part,
            [$command, ...$args],
        );

        $base = implode(' ', $parts);

        if ($env === []) {
            return $base;
        }

        $envPairs = [];

        foreach ($env as $key => $value) {
            $quoted = str_contains($value, ' ') ? '"'.$value.'"' : $value;
            $envPairs[] = "{$key}={$quoted}";
        }

        return implode(' ', $envPairs).' '.$base;
    }

    private function escapeTomlString(string $value): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    }

    private function sameNormalizedPath(string $a, string $b): bool
    {
        $normA = str_replace('\\', '/', $a);
        $normB = str_replace('\\', '/', $b);

        if (PHP_OS_FAMILY === 'Windows') {
            return mb_strtolower($normA) === mb_strtolower($normB);
        }

        return $normA === $normB;
    }
}
