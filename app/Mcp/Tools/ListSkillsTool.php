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

#[Name('list_skills')]
#[Title('List Skills')]
#[Description('Discovers reusable Markdown skills in <vault>/skills/ and AI/<project>/skills/ across accessible unencrypted vaults.')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
class ListSkillsTool extends Tool
{
    public function handle(Request $request, McpKosService $kos): Response|ResponseFactory
    {
        $validated = $request->validate([
            'vault' => ['nullable', 'string'],
            'project' => ['nullable', 'string'],
        ]);

        try {
            return Response::structured($kos->listSkills(
                vaultIdentifier: isset($validated['vault']) ? (string) $validated['vault'] : null,
                project: isset($validated['project']) ? (string) $validated['project'] : null,
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
                ->description('Optional vault name or UUID to filter skills.'),
            'project' => $schema->string()
                ->description('Optional AI project folder name to return project-scoped skills (AI/<project>/skills/).'),
        ];
    }
}
