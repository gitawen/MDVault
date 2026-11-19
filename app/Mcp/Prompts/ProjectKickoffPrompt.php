<?php

namespace App\Mcp\Prompts;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;

#[Name('project_kickoff')]
#[Title('AI Project Kickoff')]
#[Description('Guides scaffolding a new AI project workspace in MDVault and populating its initial plan, tasks, and skills.')]
class ProjectKickoffPrompt extends Prompt
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'project' => ['required', 'string', 'max:100'],
            'goal' => ['required', 'string', 'max:1000'],
            'stack' => ['nullable', 'string', 'max:500'],
        ]);

        $project = trim((string) $validated['project']);
        $goal = trim((string) $validated['goal']);
        $stack = isset($validated['stack']) && trim((string) $validated['stack']) !== ''
            ? trim((string) $validated['stack'])
            : 'Unspecified';

        return Response::text(<<<MARKDOWN
Initialize and plan the MDVault AI project **{$project}**.

1. Call `get_kos_context` to check user preferences and confirm whether `AI/{$project}/` already exists.
2. Call `init_ai_project` with:
   - `name`: `"{$project}"`
   - `description`: `"{$goal}"`
   - `stack`: `"{$stack}"`
3. Read `AI/{$project}/overview.md` and `AI/{$project}/tasks.md` using `read_note` to capture their `file_hash`.
4. Refine `overview.md` with clear scope, milestones, and constraints, and populate `tasks.md` with concrete actionable items via `update_note` (passing the exact `file_hash`).
5. Record the kickoff in `AI/{$project}/receipts.md` via `append_note`, and extract any repeatable project workflow into `AI/{$project}/skills/` via `save_skill`.
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
                description: 'Project folder name to create under AI/.',
                required: true,
            ),
            new Argument(
                name: 'goal',
                description: 'Primary goal or problem statement for the project.',
                required: true,
            ),
            new Argument(
                name: 'stack',
                description: 'Optional technology stack or tools used in the project.',
                required: false,
            ),
        ];
    }
}
