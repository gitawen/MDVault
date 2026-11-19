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

#[Name('save_skill')]
#[Title('Save Skill')]
#[Description('Creates or updates a reusable Markdown skill in AI/skills/, AI/<project>/skills/, or an existing <personal-vault>/skills/ folder (requires file_hash when overwriting).')]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
class SaveSkillTool extends Tool
{
    public function handle(Request $request, McpKosService $kos): Response|ResponseFactory
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'content' => ['required', 'string'],
            'project' => ['nullable', 'string', 'max:100'],
            'vault' => ['nullable', 'string'],
            'file_hash' => ['nullable', 'string', 'regex:/^[0-9a-f]{64}$/i'],
        ]);

        try {
            return Response::structured($kos->saveSkill(
                name: (string) $validated['name'],
                content: (string) $validated['content'],
                project: isset($validated['project']) ? (string) $validated['project'] : null,
                vaultIdentifier: isset($validated['vault']) ? (string) $validated['vault'] : null,
                fileHash: isset($validated['file_hash']) ? (string) $validated['file_hash'] : null,
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
                ->description('Skill name/slug (e.g. "release-checklist" or "release-checklist.md").')
                ->required(),
            'content' => $schema->string()
                ->description('Full Markdown content of the skill.')
                ->required(),
            'project' => $schema->string()
                ->description('Optional AI project folder name to scope the skill to AI/<project>/skills/.'),
            'vault' => $schema->string()
                ->description('Target vault name or UUID (defaults to "AI"). Personal vaults require an existing skills/ folder.'),
            'file_hash' => $schema->string()
                ->description('64-character SHA-256 hex digest required when overwriting an existing skill.'),
        ];
    }
}
