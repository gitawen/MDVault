<?php

namespace App\Mcp\Tools;

use App\Exceptions\McpKosException;
use App\Exceptions\NoteOperationException;
use App\Exceptions\VaultOperationException;
use App\Services\McpKosService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[Name('append_note')]
#[Title('Append to Note')]
#[Description('Appends Markdown content to the end of an existing note (with optional SHA-256 file_hash check and clean newline separation).')]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
class AppendNoteTool extends Tool
{
    public function handle(Request $request, McpKosService $kos): Response|ResponseFactory
    {
        $validated = $request->validate([
            'vault' => ['required', 'string'],
            'path' => ['nullable', 'string', 'required_without:uuid'],
            'uuid' => ['nullable', 'string', 'required_without:path'],
            'content' => ['required', 'string'],
            'file_hash' => ['nullable', 'string', 'regex:/^[0-9a-f]{64}$/i'],
            'ensure_newline' => ['nullable', 'boolean'],
        ]);

        try {
            return Response::structured($kos->appendNote(
                vaultIdentifier: (string) $validated['vault'],
                path: isset($validated['path']) ? (string) $validated['path'] : null,
                uuid: isset($validated['uuid']) ? (string) $validated['uuid'] : null,
                content: (string) $validated['content'],
                fileHash: isset($validated['file_hash']) ? (string) $validated['file_hash'] : null,
                ensureNewline: (bool) ($validated['ensure_newline'] ?? true),
            ));
        } catch (McpKosException|NoteOperationException|VaultOperationException $e) {
            return Response::error($e->getMessage());
        }
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'vault' => $schema->string()
                ->description('Vault name or UUID.')
                ->required(),
            'path' => $schema->string()
                ->description('Vault-relative note path. Required unless uuid is provided.'),
            'uuid' => $schema->string()
                ->description('Note UUID. Required unless path is provided.'),
            'content' => $schema->string()
                ->description('Markdown content to append to the end of the note.')
                ->required(),
            'file_hash' => $schema->string()
                ->description('Optional 64-character SHA-256 file_hash to verify the note did not change before appending.'),
            'ensure_newline' => $schema->boolean()
                ->description('Whether to insert a newline separator before appended content when needed (default true).')
                ->default(true),
        ];
    }
}
