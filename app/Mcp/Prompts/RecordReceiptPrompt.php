<?php

namespace App\Mcp\Prompts;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;

#[Name('record_receipt')]
#[Title('Record Work Receipt')]
#[Description('Logs a dated execution receipt to AI/<project>/receipts.md and updates project task status safely.')]
class RecordReceiptPrompt extends Prompt
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'project' => ['required', 'string', 'max:100'],
            'summary' => ['required', 'string', 'max:1000'],
        ]);

        $project = trim((string) $validated['project']);
        $summary = trim((string) $validated['summary']);

        return Response::text(<<<MARKDOWN
Record a work receipt for AI project **{$project}**:

> {$summary}

1. Verify `AI/{$project}/receipts.md` exists via `read_note(vault: "AI", path: "{$project}/receipts.md")`. If missing, call `init_ai_project(name: "{$project}")` first.
2. Append a timestamped Markdown entry summarizing the completed work, key files/notes touched, and verification results to `AI/{$project}/receipts.md` using `append_note` (pass `file_hash` when available).
3. Read `AI/{$project}/tasks.md` via `read_note` and update completed/follow-up checkboxes via `update_note` with its `file_hash`.
4. If this work uncovered a reusable procedure, persist it via `save_skill(project: "{$project}", ...)`.
MARKDOWN);
    }

    /**
     * @return array<int, Argument>
     */
    public function arguments(): array
    {
        return [
            new Argument(
                name: 'project',
                description: 'AI project folder name under AI/.',
                required: true,
            ),
            new Argument(
                name: 'summary',
                description: 'Concise summary of the completed work, decisions, and verification.',
                required: true,
            ),
        ];
    }
}
