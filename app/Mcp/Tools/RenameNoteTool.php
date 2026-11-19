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

#[Name('rename_note')]
#[Title('Rename Note')]
#[Description('Renames a note inside the dedicated AI vault (strictly refused on personal vaults).')]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
class RenameNoteTool extends Tool
{
    public function handle(Request $request, McpKosService $kos): Response|ResponseFactory
    {
        $validated = $request->validate([
            'vault' => ['nullable', 'string'],
            'path' => ['nullable', 'string', 'required_without:uuid'],
            'uuid' => ['nullable', 'string', 'required_without:path'],
            'new_name' => ['required', 'string'],
        ]);

        try {
            return Response::structured($kos->renameNote(
                vaultIdentifier: (string) ($validated['vault'] ?? McpKosService::AI_VAULT_NAME),
                path: isset($validated['path']) ? (string) $validated['path'] : null,
                uuid: isset($validated['uuid']) ? (string) $validated['uuid'] : null,
                newName: (string) $validated['new_name'],
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
                ->description('Vault name or UUID (defaults to "AI"; personal vaults are refused).')
                ->default(McpKosService::AI_VAULT_NAME),
            'path' => $schema->string()
                ->description('Vault-relative path of the note to rename. Required unless uuid is provided.'),
            'uuid' => $schema->string()
                ->description('Note UUID. Required unless path is provided.'),
            'new_name' => $schema->string()
                ->description('New note filename or stem (".md" is added automatically).')
                ->required(),
        ];
    }
}
