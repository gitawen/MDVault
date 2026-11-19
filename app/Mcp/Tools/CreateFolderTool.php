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
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[Name('create_folder')]
#[Title('Create Folder')]
#[Description('Creates a folder inside the dedicated AI vault (auto-provisioning AI if needed). Strictly refused on personal vaults.')]
#[IsDestructive(false)]
#[IsIdempotent]
#[IsOpenWorld(false)]
class CreateFolderTool extends Tool
{
    public function handle(Request $request, McpKosService $kos): Response|ResponseFactory
    {
        $validated = $request->validate([
            'vault' => ['nullable', 'string'],
            'path' => ['required', 'string'],
            'recursive' => ['nullable', 'boolean'],
        ]);

        try {
            return Response::structured($kos->createFolder(
                vaultIdentifier: (string) ($validated['vault'] ?? McpKosService::AI_VAULT_NAME),
                path: (string) $validated['path'],
                recursive: (bool) ($validated['recursive'] ?? true),
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
                ->description('Vault-relative folder path to create.')
                ->required(),
            'recursive' => $schema->boolean()
                ->description('Create missing parent folder segments as needed (default true).')
                ->default(true),
        ];
    }
}
