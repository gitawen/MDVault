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

#[Name('list_notes')]
#[Title('List Notes')]
#[Description('Reconciles the vault quick index and returns the folder tree and notes inside an accessible unencrypted vault.')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
class ListNotesTool extends Tool
{
    public function handle(Request $request, McpKosService $kos): Response|ResponseFactory
    {
        $validated = $request->validate([
            'vault' => ['required', 'string'],
            'folder' => ['nullable', 'string'],
        ]);

        try {
            return Response::structured($kos->listNotes(
                (string) $validated['vault'],
                isset($validated['folder']) ? (string) $validated['folder'] : null,
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
                ->description('Vault name (e.g. "AI", "Personal") or UUID.')
                ->required(),
            'folder' => $schema->string()
                ->description('Optional vault-relative folder path to scope the listing.'),
        ];
    }
}
