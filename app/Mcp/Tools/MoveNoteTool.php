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

#[Name('move_note')]
#[Title('Move Note')]
#[Description('Moves a note to another folder inside the dedicated AI vault (strictly refused on personal vaults).')]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
class MoveNoteTool extends Tool
{
    public function handle(Request $request, McpKosService $kos): Response|ResponseFactory
    {
        $validated = $request->validate([
            'vault' => ['nullable', 'string'],
            'path' => ['nullable', 'string', 'required_without:uuid'],
            'uuid' => ['nullable', 'string', 'required_without:path'],
            'target_folder' => ['present', 'nullable', 'string'],
        ]);

        try {
            return Response::structured($kos->moveNote(
                vaultIdentifier: (string) ($validated['vault'] ?? McpKosService::AI_VAULT_NAME),
                path: isset($validated['path']) ? (string) $validated['path'] : null,
                uuid: isset($validated['uuid']) ? (string) $validated['uuid'] : null,
                targetFolder: (string) ($validated['target_folder'] ?? ''),
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
                ->description('Vault-relative path of the note to move. Required unless uuid is provided.'),
            'uuid' => $schema->string()
                ->description('Note UUID. Required unless path is provided.'),
            'target_folder' => $schema->string()
                ->description('Destination vault-relative folder path (use "" or "/" for the vault root).')
                ->required(),
        ];
    }
}
