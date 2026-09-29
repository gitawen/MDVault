<?php

namespace App\Http\Controllers;

use App\Enums\SettingGroup;
use App\Enums\VaultStatus;
use App\Models\Note;
use App\Services\NoteService;
use App\Services\SettingsService;
use App\Services\SystemStatusService;
use App\Services\VaultIndexService;
use App\Services\VaultService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(
        SystemStatusService $systemStatus,
        SettingsService $settings,
        VaultService $vaults,
        VaultIndexService $index,
        NoteService $notes,
        ?Note $note = null,
    ): Response|RedirectResponse {
        $current = $vaults->current();

        if ($note !== null && ($current === null || ! $note->vault->is($current))) {
            Inertia::flash('toast', ['type' => 'error', 'message' => "This note is in the vault \u{201c}{$note->vault->name}\u{201d}. Open that vault first."]);

            return to_route('workspace');
        }

        $active = $current !== null && $current->status === VaultStatus::Active;

        /** @var array{tree: list<array<string, mixed>>, folders: list<string>}|null $browsed */
        $browsed = null;
        $browse = function () use (&$browsed, $index, $current, $note): array {
            if ($current === null) {
                return ['tree' => [], 'folders' => ['']];
            }

            return $browsed ??= $index->browse($current, $note?->relative_path);
        };

        return Inertia::render('Workspace', [
            'status' => $systemStatus->summary(),
            'editor' => $settings->group(SettingGroup::Editor),
            'currentVault' => $current ? $vaults->present($current) : null,
            'tree' => fn () => $active ? $browse()['tree'] : null,
            'folders' => fn () => $active ? $browse()['folders'] : [],
            'note' => function () use ($note, $active, $notes): ?array {
                if (! $note || ! $active) {
                    return null;
                }

                $preview = $notes->preview($note);

                return [...$notes->present($note), ...$preview];
            },
            'canTrash' => $notes->canTrash(),
        ]);
    }
}
