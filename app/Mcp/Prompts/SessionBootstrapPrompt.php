<?php

namespace App\Mcp\Prompts;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;

#[Name('session_bootstrap')]
#[Title('KOS Session Bootstrap')]
#[Description('Bootstraps an AI session with MDVault core memory, vault inventory, active projects, and available skills.')]
class SessionBootstrapPrompt extends Prompt
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'focus' => ['nullable', 'string', 'max:300'],
        ]);

        $focus = isset($validated['focus']) && trim((string) $validated['focus']) !== ''
            ? trim((string) $validated['focus'])
            : 'general session';

        return Response::text(<<<MARKDOWN
Bootstrap your MDVault Knowledge Operating System (KOS) context for: **{$focus}**.

Follow these steps in order:
1. Call `get_kos_context` (or read `mdvault://memory/about-me`) to load `AI/about-me.md`, the accessible vs. excluded vaults summary, and existing `AI/` projects.
2. Call `list_skills` to discover reusable procedures in `<vault>/skills/` and `AI/<project>/skills/`.
3. If your focus maps to an existing project under `AI/<project>/`, call `list_notes(vault: "AI", folder: "<project>")` and read `overview.md` and `tasks.md` via `read_note`.
4. Respect MDVault vault boundaries:
   - Never attempt to access encrypted vaults (`accessible: false`).
   - Treat personal vaults as read + safe-write (`create_note`, `update_note`, `append_note` with `file_hash`).
   - Use the `AI` vault for workspace organization, project scaffolding (`init_ai_project`), and receipts.
MARKDOWN);
    }

    /**
     * @return array<int, Argument>
     */
    public function arguments(): array
    {
        return [
            new Argument(
                name: 'focus',
                description: 'Optional topic, task, or project name to focus the session bootstrap on.',
                required: false,
            ),
        ];
    }
}
