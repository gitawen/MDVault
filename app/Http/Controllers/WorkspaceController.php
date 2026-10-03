<?php

namespace App\Http\Controllers;

use App\Enums\SettingGroup;
use App\Enums\SettingKey;
use App\Enums\VaultStatus;
use App\Models\Note;
use App\Services\EncryptedNoteService;
use App\Services\NoteService;
use App\Services\SettingsService;
use App\Services\SystemStatusService;
use App\Services\VaultEncryptionService;
use App\Services\VaultIndexService;
use App\Services\VaultKeyService;
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
        VaultKeyService $keys,
        EncryptedNoteService $encryptedNotes,
        VaultEncryptionService $encryption,
        ?Note $note = null,
    ): Response|RedirectResponse {
        $current = $vaults->current();

        if ($note !== null && ($current === null || ! $note->vault->is($current))) {
            Inertia::flash('toast', ['type' => 'error', 'message' => "This note is in the vault \u{201c}{$note->vault->name}\u{201d}. Open that vault first."]);

            return to_route('workspace');
        }

        $active = $current !== null && $current->status === VaultStatus::Active;
        $encrypted = $current !== null && $current->is_encrypted;

        // A locked vault sends no tree, folders, signature or note at all:
        // the key decides, never the client (ADR `encrypted-vault-key-custody`).
        $unlocked = $current !== null && $encrypted && $active && $keys->keyFor($current) !== null;
        $locked = $encrypted && ! $unlocked;

        if ($encrypted) {
            Inertia::encryptHistory();
        }

        /** @var array{tree: list<array<string, mixed>>, folders: list<string>, signature: ?string}|null $browsed */
        $browsed = null;
        $browse = function () use (&$browsed, $index, $encryptedNotes, $current, $note, $encrypted): array {
            if ($current === null) {
                return ['tree' => [], 'folders' => [''], 'signature' => null];
            }

            if ($encrypted) {
                return $browsed ??= $encryptedNotes->browse($current, $note);
            }

            return $browsed ??= $index->browse($current, $note?->relative_path);
        };

        return Inertia::render('Workspace', [
            'status' => $systemStatus->summary(),
            'editor' => $settings->group(SettingGroup::Editor),
            'currentVault' => $current ? $vaults->present($current) : null,
            'tree' => fn () => $active && ! $locked ? $browse()['tree'] : null,
            'folders' => fn () => $locked ? [''] : ($active ? $browse()['folders'] : []),
            'treeSignature' => fn () => $active && ! $locked ? $browse()['signature'] : null,
            'checkExternalChanges' => $settings->boolean(SettingKey::CheckExternalChanges),
            'note' => function () use ($note, $active, $locked, $notes): ?array {
                if (! $note || ! $active || $locked) {
                    return null;
                }

                $preview = $notes->preview($note);

                return [...$notes->present($note), ...$preview];
            },
            'canTrash' => $notes->canTrash(),
            'encryption' => fn () => $current !== null && $encrypted
                ? [
                    'locked' => $locked,
                    'unencrypted_files' => $active ? $index->unencryptedFilesIn($current) : [],
                    'inconsistent' => $active && ! $encryption->isConsistent($current),
                ]
                : null,
        ]);
    }
}
