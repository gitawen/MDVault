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

#[Name('init_ai_project')]
#[Title('Initialize AI Project')]
#[Description('Idempotently scaffolds an AI project workspace at AI/<name>/ with overview.md, tasks.md, receipts.md, and skills/ without overwriting existing files.')]
#[IsDestructive(false)]
#[IsIdempotent]
#[IsOpenWorld(false)]
class InitAiProjectTool extends Tool
{
    public function handle(Request $request, McpKosService $kos): Response|ResponseFactory
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'stack' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            return Response::structured($kos->initAiProject(
                name: (string) $validated['name'],
                description: isset($validated['description']) ? (string) $validated['description'] : null,
                stack: isset($validated['stack']) ? (string) $validated['stack'] : null,
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
            'name' => $schema->string()
                ->description('Project folder name under AI/ (valid folder segment, max 100 chars).')
                ->required(),
            'description' => $schema->string()
                ->description('Optional project summary inserted into overview.md when created.'),
            'stack' => $schema->string()
                ->description('Optional technology stack summary inserted into overview.md when created.'),
        ];
    }
}
