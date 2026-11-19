<?php

namespace App\Mcp\Tools;

use App\Exceptions\McpKosException;
use App\Exceptions\NoteOperationException;
use App\Exceptions\VaultOperationException;
use App\Services\McpSearchService;
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

#[Name('search_notes')]
#[Title('Search Notes')]
#[Description('Searches note titles, relative paths, and live Markdown contents across active unencrypted vaults, returning ranked matches with 1-based line numbers and snippets.')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
class SearchNotesTool extends Tool
{
    public function handle(Request $request, McpSearchService $search): Response|ResponseFactory
    {
        $validated = $request->validate([
            'query' => ['required', 'string', 'max:500'],
            'vault' => ['nullable', 'string'],
            'folder' => ['nullable', 'string'],
            'case_sensitive' => ['nullable', 'boolean'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            'max_matches_per_note' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        try {
            return Response::structured($search->search(
                query: (string) $validated['query'],
                vaultIdentifier: isset($validated['vault']) ? (string) $validated['vault'] : null,
                folder: isset($validated['folder']) ? (string) $validated['folder'] : null,
                caseSensitive: (bool) ($validated['case_sensitive'] ?? false),
                limit: (int) ($validated['limit'] ?? 20),
                maxMatchesPerNote: (int) ($validated['max_matches_per_note'] ?? 5),
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
            'query' => $schema->string()
                ->description('Non-empty text query to match against note titles, paths, and Markdown contents (max 500 chars).')
                ->required(),
            'vault' => $schema->string()
                ->description('Optional vault name or UUID to restrict the search.'),
            'folder' => $schema->string()
                ->description('Optional vault-relative folder prefix to narrow the search.'),
            'case_sensitive' => $schema->boolean()
                ->description('Whether matching should be case-sensitive (default false).')
                ->default(false),
            'limit' => $schema->integer()
                ->description('Maximum matched notes to return (1-50, default 20).')
                ->min(1)
                ->max(50)
                ->default(20),
            'max_matches_per_note' => $schema->integer()
                ->description('Maximum content line snippets per note (1-20, default 5).')
                ->min(1)
                ->max(20)
                ->default(5),
        ];
    }
}
