<script setup lang="ts">
import { router, useHttp } from '@inertiajs/vue3';
import { Save } from '@lucide/vue';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { toast } from 'vue-sonner';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import NoteCompareDialog from '@/components/editor/NoteCompareDialog.vue';
import NoteConflictAlert from '@/components/editor/NoteConflictAlert.vue';
import MarkdownEditor from '@/components/editor/MarkdownEditor.vue';
import UnsavedChangesDialog from '@/components/editor/UnsavedChangesDialog.vue';
import { useUnsavedChangesGuard } from '@/composables/useUnsavedChangesGuard';
import {
    classifyOpenNote,
    decideOpenNoteAction,
} from '@/lib/external/openNoteStatus';
import type { NoteSaverState, SaveOutcome } from '@/lib/editor/noteSaver';
import { createNoteSaver } from '@/lib/editor/noteSaver';
import { mapCopyResult } from '@/lib/editor/copyTransport';
import { mapSaveResult } from '@/lib/editor/saveTransport';
import { workspace } from '@/routes';
import { show } from '@/routes/notes';
import { update as updateContent } from '@/routes/notes/content';
import { reindex } from '@/routes/vaults';
import { copy } from '@/routes/vaults/notes';
import type {
    EditorPreferences,
    NoteCopyResponse,
    NoteDetail,
    NoteSaveResponse,
    RemoteOpenNote,
} from '@/types';

const props = defineProps<{
    note: NoteDetail;
    vaultUuid: string;
    preferences: EditorPreferences;
    runtime: 'desktop' | 'browser';
    externalChecks: boolean;
}>();

const emit = defineEmits<{
    'request-check': [];
}>();

function formatBytes(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }

    const units = ['KB', 'MB', 'GB'];
    let value = bytes / 1024;
    let unitIndex = 0;

    while (value >= 1024 && unitIndex < units.length - 1) {
        value /= 1024;
        unitIndex++;
    }

    return `${value.toFixed(1)} ${units[unitIndex]}`;
}

function runReindex(): void {
    router.post(reindex.url(props.vaultUuid), {}, { preserveScroll: true });
}

// --- Editor ref and content ------------------------------------------------

const editorRef = ref<InstanceType<typeof MarkdownEditor> | null>(null);
const noteText = ref(props.note.content ?? '');

function readContent(): string {
    return editorRef.value?.getText() ?? '';
}

// --- Autosave ---------------------------------------------------------------

const saveState = ref<NoteSaverState>({
    status: 'clean',
    baseHash: props.note.base_hash ?? '',
    lastSavedAt: null,
    message: null,
    conflict: null,
});

type SavePayload = {
    content: string;
    base_hash: string;
    mode: 'source';
};

// A save epoch (Phase 5, ADR `open-note-external-conflicts`): incremented
// before a save request starts and again once it settles, so a stale
// external-change check result (one whose token no longer matches) is
// never acted on. `externalCheckToken()` also returns null while a save is
// in flight, so a check is skipped that round rather than racing the save.
let epoch = 0;

function send(content: string, baseHash: string): Promise<SaveOutcome> {
    const payload: SavePayload = {
        content,
        base_hash: baseHash,
        mode: 'source',
    };

    epoch++;

    return new Promise((resolve) => {
        function finish(outcome: SaveOutcome): void {
            epoch++;

            if (outcome.kind === 'conflict' && outcome.reason === 'missing') {
                // A pure move 409s as `missing`; an immediate check lets it
                // resolve itself through `resume` instead of showing a
                // banner for a note that only moved.
                emit('request-check');
            }

            resolve(outcome);
        }

        useHttp<SavePayload, NoteSaveResponse>(payload).put(
            updateContent.url(props.note.uuid),
            {
                onSuccess: (response) => {
                    finish(mapSaveResult({ kind: 'success', response }));
                },
                onError: (errors) => {
                    finish(mapSaveResult({ kind: 'validation', errors }));
                },
                onHttpException: (httpResponse) => {
                    finish(
                        mapSaveResult({
                            kind: 'httpException',
                            status: httpResponse.status,
                            data: httpResponse.data,
                        }),
                    );
                },
                onNetworkError: () => {
                    finish(mapSaveResult({ kind: 'network' }));
                },
            },
        );
    });
}

let saver: ReturnType<typeof createNoteSaver> | null = null;

function ensureSaver(baseline: string): void {
    if (saver) {
        return;
    }

    saver = createNoteSaver({
        baseHash: props.note.base_hash ?? '',
        baseline,
        readContent,
        send,
        onState: (state) => {
            saveState.value = state;
        },
    });
}

function notifyChange(): void {
    saver?.notifyChange();
}

function flush(): Promise<'clean' | 'saved' | 'failed'> {
    return saver ? saver.flush() : Promise.resolve('clean');
}

function isDirty(): boolean {
    return saver?.isDirty() ?? false;
}

function discard(): void {
    saver?.discard();
}

function onKeydown(event: KeyboardEvent): void {
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') {
        event.preventDefault();
        void flush();
    }
}

onMounted(() => {
    ensureSaver(props.note.content ?? '');
    window.addEventListener('keydown', onKeydown);
});

let unmounted = false;

onUnmounted(() => {
    unmounted = true;
    window.removeEventListener('keydown', onKeydown);
    saver?.dispose();
});

// --- Freeze during a guarded navigation (R2-01) ------------------------------

const frozen = ref(false);

function freeze(): void {
    // Belt-and-braces: the guard normally awaits flush() itself before
    // calling this, but re-checking here covers the synchronous gap
    // between that flush resolving and this call, and engages the
    // read-only state immediately either way, closing the window for any
    // further keystroke to be lost once this component unmounts.
    if (isDirty()) {
        void flush();
    }

    frozen.value = true;
}

function unfreeze(): void {
    frozen.value = false;
}

// --- Conflict actions --------------------------------------------------------

function conflictReload(): void {
    discard();
    router.reload({ only: ['note'] });
}

async function conflictOverwrite(): Promise<void> {
    await saver?.overwrite();
}

function conflictCopy(): void {
    void navigator.clipboard?.writeText(readContent());
}

// --- External changes (ADR `open-note-external-conflicts`) -------------------

function refresh(): void {
    router.reload({ only: ['note', 'tree', 'folders', 'treeSignature'] });
}

function externalCheckToken(): number | null {
    return saveState.value.status === 'saving' ? null : epoch;
}

function localSnapshot(): {
    uuid: string;
    relativePath: string;
    baseHash: string;
} {
    return {
        uuid: props.note.uuid,
        relativePath: props.note.relative_path,
        baseHash:
            saver?.getState().baseHash ||
            props.note.base_hash ||
            props.note.file_hash,
    };
}

function applyExternalStatus(token: number, remote: RemoteOpenNote): void {
    // A check result can resolve after this instance has unmounted (AR-02):
    // acting on it here (e.g. `leave`'s `router.visit`) would navigate away
    // from whatever note the user has since opened.
    if (unmounted || token !== epoch || saveState.value.status === 'saving') {
        return;
    }

    const change = classifyOpenNote(localSnapshot(), remote);
    const action = decideOpenNoteAction(change, {
        dirty: isDirty(),
        conflict: saveState.value.conflict?.reason ?? null,
    });

    switch (action) {
        case 'none':
            break;

        case 'refresh':
            refresh();
            break;

        case 'resume':
            void saver?.resume().then(() => refresh());
            break;

        case 'reload':
            discard();
            toast.info(
                `\u{201c}${props.note.title}\u{201d} was changed outside MDVault and has been reloaded.`,
            );
            refresh();
            break;

        case 'leave':
            discard();
            toast.warning(
                `\u{201c}${props.note.relative_path}\u{201d} was deleted or moved outside MDVault.`,
            );
            router.visit(workspace.url());
            break;

        case 'conflict-changed': {
            if (!saver) {
                // Still assessing (no saver yet): treat exactly like a
                // clean reload rather than losing the change notice.
                discard();
                toast.info(
                    `\u{201c}${props.note.title}\u{201d} was changed outside MDVault and has been reloaded.`,
                );
                refresh();
                break;
            }

            let conflictMessage = `\u{201c}${props.note.relative_path}\u{201d} was changed outside MDVault while you were editing. Your edits haven't been saved yet.`;

            if (change.kind === 'changed' && change.moved) {
                conflictMessage += ` It was also moved to \u{201c}${change.relativePath}\u{201d}.`;
            }

            saver.externalConflict(
                'changed',
                change.kind === 'changed' ? change.currentHash : null,
                conflictMessage,
            );
            break;
        }

        case 'conflict-missing':
            saver?.externalConflict(
                'missing',
                null,
                `\u{201c}${props.note.relative_path}\u{201d} was deleted or moved outside MDVault, and MDVault couldn't find where it went. Your text is still in the editor.`,
            );
            break;
    }
}

type CopyPayload = {
    source_path: string;
    mode: 'source';
    content: string;
};

function saveAsNewNote(): void {
    const payload: CopyPayload = {
        content: readContent(),
        mode: 'source',
        source_path: props.note.relative_path,
    };

    useHttp<CopyPayload, NoteCopyResponse>(payload).post(
        copy.url(props.vaultUuid),
        {
            onSuccess: (response) => {
                discard();
                toast.success(
                    `Your version was saved as \u{201c}${response.relative_path}\u{201d}.`,
                );
                router.visit(show.url(response.uuid));
            },
            onError: (errors) => {
                const outcome = mapCopyResult({ kind: 'validation', errors });

                if (outcome.kind === 'error') {
                    toast.error(outcome.message);
                }
            },
            onHttpException: () => {
                const outcome = mapCopyResult({ kind: 'httpException' });

                if (outcome.kind === 'error') {
                    toast.error(outcome.message);
                }
            },
            onNetworkError: () => {
                const outcome = mapCopyResult({ kind: 'network' });

                if (outcome.kind === 'error') {
                    toast.error(outcome.message);
                }
            },
        },
    );
}

const compareOpen = ref(false);
const compareMine = ref('');

function openCompare(): void {
    compareMine.value = readContent();
    compareOpen.value = true;
}

function discardMissing(): void {
    discard();
    emit('request-check');

    if (!props.externalChecks) {
        router.visit(workspace.url());
    }
}

function checkAgain(): void {
    if (props.externalChecks) {
        emit('request-check');
    } else {
        runReindex();
    }
}

defineExpose({
    noteUuid: () => props.note.uuid,
    externalCheckToken,
    applyExternalStatus,
    resetDisplayMode: () => editorRef.value?.resetDisplayMode(),
});

// --- Unsaved-changes guard ----------------------------------------------------

const guard = useUnsavedChangesGuard({
    isDirty,
    flush,
    discard,
    freeze,
    unfreeze,
    runtime: props.runtime,
});

const statusLabel = computed(() => {
    switch (saveState.value.status) {
        case 'saving':
            return 'Saving…';
        case 'saved':
            return saveState.value.lastSavedAt
                ? `Saved ${saveState.value.lastSavedAt.toLocaleTimeString()}`
                : 'Saved';
        case 'dirty':
            return 'Unsaved changes';
        case 'conflict':
            return 'Conflict';
        case 'error':
            return "Couldn't save";
        default:
            return 'Saved';
    }
});
</script>

<template>
    <article class="flex min-h-0 flex-1 flex-col gap-3">
        <header
            class="flex flex-col justify-between gap-2.5 pb-1 sm:flex-row sm:items-center"
        >
            <div class="flex min-w-0 flex-1 items-center gap-2">
                <h1
                    class="shrink-0 text-base font-semibold tracking-tight sm:text-lg"
                >
                    {{ note.title }}
                </h1>
                <span
                    class="min-w-0 truncate rounded-md border border-border/50 bg-muted/60 px-2 py-0.5 font-mono text-[11px] text-muted-foreground"
                    :title="note.relative_path"
                >
                    {{ note.relative_path }}
                </span>
            </div>

            <template v-if="note.editable">
                <div class="flex flex-wrap items-center gap-2 sm:justify-end">
                    <!-- Status Indicator Badge -->
                    <div
                        aria-live="polite"
                        class="inline-flex items-center gap-1.5 rounded-md border border-border/50 bg-muted/40 px-2.5 py-1 text-xs text-muted-foreground select-none"
                    >
                        <span
                            class="size-2 rounded-full transition-colors"
                            :class="{
                                'bg-emerald-500':
                                    saveState.status === 'saved' ||
                                    saveState.status === 'clean',
                                'animate-pulse bg-amber-500':
                                    saveState.status === 'dirty',
                                'animate-pulse bg-blue-500':
                                    saveState.status === 'saving',
                                'bg-destructive':
                                    saveState.status === 'conflict' ||
                                    saveState.status === 'error',
                            }"
                        />
                        <span class="max-w-[120px] truncate sm:max-w-none">{{
                            statusLabel
                        }}</span>
                    </div>

                    <!-- Save Button -->
                    <Button
                        size="sm"
                        class="h-8 gap-1.5 px-3 text-xs shadow-xs"
                        @click="flush"
                    >
                        <Save class="size-3.5" />
                        <span>Save</span>
                    </Button>
                </div>
            </template>
        </header>

        <template v-if="note.state === 'missing'">
            <Alert variant="destructive">
                <AlertDescription class="flex items-center gap-3">
                    <span>
                        This note&#8217;s file can&#8217;t be found on disk. It
                        may have been moved, renamed or deleted outside MDVault.
                    </span>
                    <Button size="sm" variant="outline" @click="runReindex">
                        Re-index
                    </Button>
                </AlertDescription>
            </Alert>
        </template>

        <template v-else-if="note.state === 'too_large'">
            <Alert>
                <AlertDescription>
                    This file is larger than 1 MB, so it&#8217;s read-only. Open
                    it in another editor.
                </AlertDescription>
            </Alert>
        </template>

        <template v-else-if="note.state === 'unreadable'">
            <Alert>
                <AlertDescription>
                    MDVault couldn&#8217;t read this file. Close any programs
                    that may be locking it and try again.
                </AlertDescription>
            </Alert>
        </template>

        <template v-else-if="note.read_only_reason === 'invalid_utf8'">
            <Alert variant="destructive">
                <AlertDescription>
                    This file isn&#8217;t valid UTF-8, so it&#8217;s read-only;
                    unreadable characters are shown as &#65533;.
                </AlertDescription>
            </Alert>
            <pre
                class="min-h-0 flex-1 overflow-auto rounded-md border bg-muted/30 p-4 font-mono text-sm break-words whitespace-pre-wrap"
                >{{ note.content }}</pre>
        </template>

        <template v-else-if="note.editable">
            <NoteConflictAlert
                v-if="saveState.conflict"
                :conflict="saveState.conflict"
                :message="saveState.message ?? ''"
                :external-checks="externalChecks"
                @reload="conflictReload"
                @overwrite="conflictOverwrite"
                @copy="conflictCopy"
                @save-as-new="saveAsNewNote"
                @compare="openCompare"
                @check="checkAgain"
                @discard="discardMissing"
            />

            <Alert
                v-else-if="saveState.status === 'error'"
                variant="destructive"
            >
                <AlertDescription>{{ saveState.message }}</AlertDescription>
            </Alert>

            <MarkdownEditor
                ref="editorRef"
                v-model="noteText"
                :editable="true"
                :readonly="frozen"
                :preferences="preferences"
                :note-uuid="props.note.uuid"
                @change="notifyChange"
            />
        </template>

        <footer
            class="mt-auto flex flex-wrap items-center justify-between gap-x-3 gap-y-1.5 border-t border-border/40 pt-2 text-xs text-muted-foreground"
        >
            <div class="flex items-center gap-3">
                <span class="shrink-0 font-medium">{{
                    formatBytes(note.file_size)
                }}</span>
                <span
                    class="shrink-0 font-mono text-[11px]"
                    :title="note.file_hash"
                >
                    SHA-256
                    {{
                        (saveState.baseHash || note.file_hash).slice(0, 12)
                    }}&hellip;
                </span>
            </div>
            <span
                v-if="saveState.lastSavedAt"
                class="hidden text-[11px] text-muted-foreground/80 sm:inline-block"
            >
                Saved at {{ saveState.lastSavedAt.toLocaleTimeString() }}
            </span>
        </footer>

        <UnsavedChangesDialog
            v-model:open="guard.dialog.open.value"
            :message="saveState.message ?? ''"
            @stay="guard.stay"
            @discard="guard.discardAndContinue"
        />

        <NoteCompareDialog
            v-model:open="compareOpen"
            :note-uuid="note.uuid"
            :title="note.title"
            :mine="compareMine"
        />
    </article>
</template>
