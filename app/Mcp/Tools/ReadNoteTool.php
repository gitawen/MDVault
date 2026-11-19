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
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('read_note')]
#[Title('Read Note')]
#[Description('Reads a Markdown note by path or UUID from an accessible unencrypted vault, returning its content, frontmatter YAML, metadata, and fresh SHA-256 file_hash.')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
class ReadNoteTool extends Tool
{
    public function handle(Request $request, McpKosService $kos): Response|ResponseFactory
    {
        $validated = $request->validate([
            'vault' => ['required', 'string'],
            'path' => ['nullable', 'string', 'required_without:uuid'],
            'uuid' => ['nullable', 'string', 'required_without:path'],
        ]);

        try {
            return Response::structured($kos->readNote(
                vaultIdentifier: (string) $validated['vault'],
                path: isset($validated['path']) ? (string) $validated['path'] : null,
                uuid: isset($validated['uuid']) ? (string) $validated['uuid'] : null,
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
                ->description('Vault-relative note path (e.g. "projects/overview.md"). Required unless uuid is provided.'),
            'uuid' => $schema->string()
                ->description('Note UUID. Required unless path is provided.'),
        ];
    }
}
