<?php

namespace App\Mcp\Prompts;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;

#[Name('synthesize_notes')]
#[Title('Synthesize Notes')]
#[Description('Searches across accessible vaults for a topic and synthesizes findings into an AI workspace note with citations.')]
class SynthesizeNotesPrompt extends Prompt
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'topic' => ['required', 'string', 'max:300'],
            'target_path' => ['nullable', 'string', 'max:200'],
            'vault' => ['nullable', 'string', 'max:100'],
        ]);

        $topic = trim((string) $validated['topic']);
        $targetPath = isset($validated['target_path']) && trim((string) $validated['target_path']) !== ''
            ? trim((string) $validated['target_path'])
            : 'syntheses/'.str_replace(' ', '-', strtolower($topic)).'.md';
        $vaultScope = isset($validated['vault']) && trim((string) $validated['vault']) !== ''
            ? 'restricting search to vault `'.trim((string) $validated['vault']).'`'
            : 'across all accessible unencrypted vaults';

        return Response::text(<<<MARKDOWN
Synthesize MDVault notes on **{$topic}** ({$vaultScope}).

1. Run `search_notes` for `"{$topic}"` (and related terms if needed) to locate relevant notes.
2. Read the highest-signal matches in full using `read_note` (or `mdvault://notes/{vault}/{uuid}`).
3. Do **not** modify personal vault source notes during synthesis.
4. Write a structured synthesis note in the `AI` vault at `{$targetPath}` using `create_note` (or `read_note` + `update_note` if it already exists), citing each source note by vault name, `relative_path`, and `uuid`.
MARKDOWN);
    }

    /**
     * @return array<int, Argument>
     */
    public function arguments(): array
    {
        return [
            new Argument(
                name: 'topic',
                description: 'Topic or search query to synthesize across vaults.',
                required: true,
            ),
            new Argument(
                name: 'target_path',
                description: 'Optional relative path inside the AI vault for the output synthesis note.',
                required: false,
            ),
            new Argument(
                name: 'vault',
                description: 'Optional vault name or UUID to scope the search.',
                required: false,
            ),
        ];
    }
}
