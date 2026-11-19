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

#[Name('get_kos_context')]
#[Title('Get KOS Context')]
#[Description('Loads MDVault KOS session bootstrap context: AI/about-me.md core memory (bounded by max_chars), accessible vaults summary, and active AI projects.')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
class GetKosContextTool extends Tool
{
    public function handle(Request $request, McpKosService $kos): Response|ResponseFactory
    {
        $validated = $request->validate([
            'max_chars' => ['nullable', 'integer', 'min:100', 'max:50000'],
        ]);

        try {
            return Response::structured($kos->getKosContext(
                maxChars: (int) ($validated['max_chars'] ?? McpKosService::DEFAULT_CONTEXT_MAX_CHARS),
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
            'max_chars' => $schema->integer()
                ->description('Maximum characters of AI/about-me.md to return (100-50000, default 2000).')
                ->min(100)
                ->max(50000)
                ->default(McpKosService::DEFAULT_CONTEXT_MAX_CHARS),
        ];
    }
}
