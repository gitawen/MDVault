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

#[Name('create_note')]
#[Title('Create Note')]
#[Description('Atomically creates a new Markdown note (never overwrites existing files). Auto-provisions the AI vault if targeting "AI".')]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
class CreateNoteTool extends Tool
{
    public function handle(Request $request, McpKosService $kos): Response|ResponseFactory
    {
        $validated = $request->validate([
            'vault' => ['required', 'string'],
            'path' => ['required', 'string'],
            'content' => ['nullable', 'string'],
            'apply_template' => ['nullable', 'boolean'],
        ]);

        try {
            return Response::structured($kos->createNote(
                vaultIdentifier: (string) $validated['vault'],
                path: (string) $validated['path'],
                content: array_key_exists('content', $validated) && $validated['content'] !== null ? (string) $validated['content'] : null,
                applyTemplate: array_key_exists('apply_template', $validated) && $validated['apply_template'] !== null ? (bool) $validated['apply_template'] : null,
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
                ->description('Target vault name (e.g. "AI", "Personal") or UUID.')
                ->required(),
            'path' => $schema->string()
                ->description('Vault-relative note path (".md" is appended if omitted).')
                ->required(),
            'content' => $schema->string()
                ->description('Optional initial Markdown content. When omitted, the configured new-note frontmatter template is applied.'),
            'apply_template' => $schema->boolean()
                ->description('Whether to prepend the new-note frontmatter template (defaults to true when content is omitted, false when content is supplied).'),
        ];
    }
}
