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

#[Name('update_note')]
#[Title('Update Note')]
#[Description('Atomically replaces a note\'s Markdown content using mandatory SHA-256 optimistic locking (file_hash from read_note), preserving BOM and line endings.')]
#[IsDestructive(false)]
#[IsIdempotent]
#[IsOpenWorld(false)]
class UpdateNoteTool extends Tool
{
    public function handle(Request $request, McpKosService $kos): Response|ResponseFactory
    {
        $validated = $request->validate([
            'vault' => ['required', 'string'],
            'path' => ['nullable', 'string', 'required_without:uuid'],
            'uuid' => ['nullable', 'string', 'required_without:path'],
            'content' => ['present', 'nullable', 'string'],
            'file_hash' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/i'],
        ]);

        try {
            return Response::structured($kos->updateNote(
                vaultIdentifier: (string) $validated['vault'],
                path: isset($validated['path']) ? (string) $validated['path'] : null,
                uuid: isset($validated['uuid']) ? (string) $validated['uuid'] : null,
                content: (string) ($validated['content'] ?? ''),
                fileHash: (string) $validated['file_hash'],
            ));
        } catch (McpKosException $e) {
            $reason = $e->context()['reason'] ?? null;

            if ($reason !== null && $e->currentHash() !== null) {
                return Response::error("[{$reason}] {$e->getMessage()}");
            }

            return Response::error($e->getMessage());
        } catch (NoteOperationException|VaultOperationException $e) {
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
                ->description('Complete new Markdown source for the note.')
                ->required(),
            'file_hash' => $schema->string()
                ->description('64-character SHA-256 hex digest obtained from read_note.')
                ->required(),
        ];
    }
}
